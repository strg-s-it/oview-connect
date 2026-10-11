<?php

declare(strict_types=1);

/**
 * connect/public/admin/webhooks.php
 *
 * Shop-uebergreifendes Webhook-Dashboard fuer Andreas, analog zu jobs.php:
 * aktive Abos (connect_webhook_subscriptions) oben, Ereignishistorie
 * (connect_webhook_events) darunter. Reines Admin-Feature, kein Shop-Bezug.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Http\HttpClient;
use Connect\Security\Crypto;
use Connect\Shopify\TokenRefresher;
use Connect\Shopify\WebhookRegistrar;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$flashMessage = null;
$flashType = 'info';

// -----------------------------------------------------------------------
// POST: Neues Abo anlegen -- bisher konnte man auf dieser Seite nur
// abbestellen, ein neues Abo musste indirekt ueber einen Merchant-Toggle
// (z.B. "Rechnungen/Aufträge") ausgeloest werden. Admin kann jetzt jedes
// verfuegbare Topic fuer jede Shopify-Connection direkt hier abonnieren.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'subscribe') {
    $connectionId = (int) ($_POST['connection_id'] ?? 0);
    $topic = (string) ($_POST['topic'] ?? '');

    if (!isset(WebhookRegistrar::AVAILABLE_TOPICS[$topic])) {
        $flashMessage = 'Unbekanntes Thema.';
        $flashType = 'danger';
    } else {
        $connStmt = $pdo->prepare('SELECT credentials FROM connect_connections WHERE id = :id');
        $connStmt->execute(['id' => $connectionId]);
        $connRow = $connStmt->fetch();

        if ($connRow === false) {
            $flashMessage = 'Connection nicht gefunden.';
            $flashType = 'danger';
        } else {
            try {
                $crypto = new Crypto($config->require('CONNECT_ENCRYPTION_KEY'));
                $credentials = TokenRefresher::fromConfig($config)->freshCredentials($pdo, $crypto, $connectionId);
                $shopDomain = str_replace(['https://', 'http://'], '', (string) ($credentials['base_url'] ?? ''));
                $accessToken = (string) ($credentials['header_value'] ?? '');

                $registrar = new WebhookRegistrar(
                    $pdo,
                    new HttpClient(),
                    $config->require('CONNECT_APP_URL'),
                    $config->optional('CONNECT_SHOPIFY_API_VERSION', '2026-07')
                );
                $registrar->subscribe($connectionId, $topic, $shopDomain, $accessToken);

                $flashMessage = "Abo \"{$topic}\" angelegt.";
                $flashType = 'success';
            } catch (\Throwable $e) {
                $flashMessage = 'Abonnieren fehlgeschlagen: ' . $e->getMessage();
                $flashType = 'danger';
            }
        }
    }
}

// -----------------------------------------------------------------------
// POST: Abo manuell entfernen (z.B. veraltetes/fehlerhaftes Abo aufraeumen).
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unsubscribe') {
    $connectionId = (int) ($_POST['connection_id'] ?? 0);
    $topic = (string) ($_POST['topic'] ?? '');

    $connStmt = $pdo->prepare('SELECT credentials FROM connect_connections WHERE id = :id');
    $connStmt->execute(['id' => $connectionId]);
    $connRow = $connStmt->fetch();

    if ($connRow === false) {
        $flashMessage = 'Connection nicht gefunden.';
        $flashType = 'danger';
    } else {
        try {
            $crypto = new Crypto($config->require('CONNECT_ENCRYPTION_KEY'));
            $credentials = TokenRefresher::fromConfig($config)->freshCredentials($pdo, $crypto, $connectionId);
            $shopDomain = str_replace(['https://', 'http://'], '', (string) ($credentials['base_url'] ?? ''));
            $accessToken = (string) ($credentials['header_value'] ?? '');

            $registrar = new WebhookRegistrar(
                $pdo,
                new HttpClient(),
                $config->require('CONNECT_APP_URL'),
                $config->optional('CONNECT_SHOPIFY_API_VERSION', '2026-07')
            );
            $registrar->unsubscribe($connectionId, $topic, $shopDomain, $accessToken);

            $flashMessage = "Abo \"{$topic}\" entfernt.";
            $flashType = 'success';
        } catch (\Throwable $e) {
            $flashMessage = 'Abbestellen fehlgeschlagen: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

// -----------------------------------------------------------------------
// Aktive Abos ueber alle Shops.
// -----------------------------------------------------------------------
$subscriptions = $pdo->query(
    "SELECT s.id, s.connection_id, s.topic, s.external_webhook_id, s.created_at,
            c.shop, c.label AS connection_label
     FROM connect_webhook_subscriptions s
     JOIN connect_connections c ON c.id = s.connection_id
     ORDER BY c.shop, s.topic"
)->fetchAll();

// -----------------------------------------------------------------------
// Letzte 100 empfangenen Events ueber alle Shops.
// -----------------------------------------------------------------------
$events = $pdo->query(
    "SELECT id, shop, connection_id, topic, status, error_message, received_at, processed_at
     FROM connect_webhook_events
     ORDER BY id DESC
     LIMIT 100"
)->fetchAll();

// Fuer das "Neues Abo anlegen"-Formular: alle Shopify-Connections ueber alle
// Shops (webhookfaehig ist aktuell nur Shopify, siehe WebhookRegistrar).
$shopifyConnections = $pdo->query(
    "SELECT c.id, c.shop, c.label
     FROM connect_connections c
     JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE t.type_key = 'shopify'
     ORDER BY c.shop"
)->fetchAll();

$topicLabels = array_map(static fn ($entry) => $entry[0], \Connect\Shopify\WebhookRegistrar::AVAILABLE_TOPICS);

$statusBadge = [
    'processed' => 'bg-green-lt',
    'received' => 'bg-secondary-lt',
    'error' => 'bg-red-lt',
    'ignored' => 'bg-yellow-lt',
];

$pageTitle = 'Webhooks';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = []; // kein Shop-Bezug auf dieser Seite
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Neues Abo anlegen</h3>
            </div>
            <div class="card-body">
                <?php if (count($shopifyConnections) === 0): ?>
                    <div class="text-secondary">Noch keine Shopify-Connection vorhanden.</div>
                <?php else: ?>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="subscribe">
                        <div class="col-auto">
                            <select name="connection_id" class="form-select" required>
                                <?php foreach ($shopifyConnections as $connection): ?>
                                    <option value="<?php echo (int) $connection['id']; ?>"><?php echo htmlspecialchars($connection['shop'] . ' — ' . $connection['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <select name="topic" class="form-select" required>
                                <?php foreach (WebhookRegistrar::AVAILABLE_TOPICS as $topicKey => $topicInfo): ?>
                                    <option value="<?php echo htmlspecialchars($topicKey); ?>"><?php echo htmlspecialchars($topicInfo[0]); ?> (<?php echo htmlspecialchars($topicKey); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary">Abonnieren</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Aktive Abos</h3>
            </div>
            <?php if (count($subscriptions) === 0): ?>
                <div class="card-body text-secondary">Noch kein Webhook abonniert.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Shop</th>
                                <th>Connection</th>
                                <th>Topic</th>
                                <th>Externe Webhook-ID</th>
                                <th>Seit</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subscriptions as $sub): ?>
                                <tr>
                                    <td><a href="connections.php?shop=<?php echo urlencode($sub['shop']); ?>"><?php echo htmlspecialchars($sub['shop']); ?></a></td>
                                    <td><?php echo htmlspecialchars($sub['connection_label']); ?></td>
                                    <td><?php echo htmlspecialchars($topicLabels[$sub['topic']] ?? $sub['topic']); ?> <span class="text-secondary">(<?php echo htmlspecialchars($sub['topic']); ?>)</span></td>
                                    <td class="text-secondary"><?php echo htmlspecialchars($sub['external_webhook_id']); ?></td>
                                    <td class="text-secondary"><?php echo htmlspecialchars((string) $sub['created_at']); ?></td>
                                    <td class="text-end">
                                        <form method="post" onsubmit="return confirm('Abo \'<?php echo htmlspecialchars(addslashes($sub['topic'])); ?>\' für \'<?php echo htmlspecialchars(addslashes($sub['shop'])); ?>\' wirklich entfernen?');">
                                            <input type="hidden" name="action" value="unsubscribe">
                                            <input type="hidden" name="connection_id" value="<?php echo (int) $sub['connection_id']; ?>">
                                            <input type="hidden" name="topic" value="<?php echo htmlspecialchars($sub['topic']); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Abbestellen</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Letzte Events (letzte 100)</h3>
            </div>
            <?php if (count($events) === 0): ?>
                <div class="card-body text-secondary">Noch kein Event empfangen.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Shop</th>
                                <th>Topic</th>
                                <th>Status</th>
                                <th>Empfangen</th>
                                <th>Verarbeitet</th>
                                <th>Fehler</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <tr>
                                    <td><a href="connections.php?shop=<?php echo urlencode($event['shop']); ?>"><?php echo htmlspecialchars($event['shop']); ?></a></td>
                                    <td><?php echo htmlspecialchars($topicLabels[$event['topic']] ?? $event['topic']); ?></td>
                                    <td>
                                        <span class="badge <?php echo htmlspecialchars($statusBadge[$event['status']] ?? 'bg-secondary-lt'); ?>">
                                            <?php echo htmlspecialchars($event['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-secondary"><?php echo htmlspecialchars((string) $event['received_at']); ?></td>
                                    <td class="text-secondary"><?php echo $event['processed_at'] !== null ? htmlspecialchars((string) $event['processed_at']) : '–'; ?></td>
                                    <td class="text-danger">
                                        <?php if ($event['error_message'] !== null): ?>
                                            <small><?php echo htmlspecialchars(mb_substr((string) $event['error_message'], 0, 150)); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="webhook_event.php?event_id=<?php echo (int) $event['id']; ?>" class="btn btn-sm btn-outline-secondary">Details</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
