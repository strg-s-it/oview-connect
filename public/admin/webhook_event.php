<?php

declare(strict_types=1);

/**
 * connect/public/admin/webhook_event.php
 *
 * Detailansicht EINES Webhook-Events -- verlinkt von webhooks.php. Zeigt das
 * vollstaendige (bereits HMAC-verifizierte) Roh-Payload, im Unterschied zur
 * kurzen error_message-Vorschau in der Listenansicht.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Shopify\WebhookRegistrar;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$eventId = (int) ($_GET['event_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM connect_webhook_events WHERE id = :id');
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();

if ($event === false) {
    \Connect\Http\ErrorPage::show(
        404,
        'Event nicht gefunden',
        'Dieser Eintrag existiert nicht (mehr) oder wurde gelöscht.',
        ['label' => 'Zur Shop-Übersicht', 'url' => 'shops.php']
    );
}

$topicLabels = array_map(static fn ($entry) => $entry[0], WebhookRegistrar::AVAILABLE_TOPICS);

$statusBadge = [
    'processed' => 'bg-green-lt',
    'received' => 'bg-secondary-lt',
    'error' => 'bg-red-lt',
];

// Payload huebsch formatieren, falls gueltiges JSON -- sonst roh anzeigen.
$decodedPayload = json_decode((string) $event['payload'], true);
$prettyPayload = $decodedPayload !== null
    ? json_encode($decodedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    : (string) $event['payload'];

$pageTitle = 'Webhook-Event-Details';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$breadcrumbs = [['webhooks.php', 'Webhooks'], [null, 'Event #' . $eventId]];
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <?php echo htmlspecialchars($topicLabels[$event['topic']] ?? $event['topic']); ?>
                    <span class="badge <?php echo htmlspecialchars($statusBadge[$event['status']] ?? 'bg-secondary-lt'); ?> ms-2">
                        <?php echo htmlspecialchars($event['status']); ?>
                    </span>
                </h3>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-3">Shop</dt>
                    <dd class="col-9"><a href="connections.php?shop=<?php echo urlencode($event['shop']); ?>"><?php echo htmlspecialchars($event['shop']); ?></a></dd>

                    <dt class="col-3">Topic</dt>
                    <dd class="col-9"><?php echo htmlspecialchars($event['topic']); ?></dd>

                    <dt class="col-3">Empfangen</dt>
                    <dd class="col-9"><?php echo htmlspecialchars((string) $event['received_at']); ?></dd>

                    <dt class="col-3">Verarbeitet</dt>
                    <dd class="col-9"><?php echo $event['processed_at'] !== null ? htmlspecialchars((string) $event['processed_at']) : '–'; ?></dd>

                    <?php if ($event['error_message'] !== null): ?>
                        <dt class="col-3">Fehler</dt>
                        <dd class="col-9 text-danger"><?php echo htmlspecialchars((string) $event['error_message']); ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Payload</h3>
            </div>
            <div class="card-body">
                <pre class="mb-0" style="white-space: pre-wrap; word-break: break-word;"><?php echo htmlspecialchars($prettyPayload); ?></pre>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
