<?php

declare(strict_types=1);

/**
 * connect/public/admin/shopify_activity.php
 *
 * "Was passiert in Shopify" fuer einen Shop: Kosten-Eimer, API-Verbrauch der
 * letzten 24 h je Stunde (Migration 0068, Connect\Http\ApiUsageRecorder),
 * eingehende Webhooks je Thema, die Webhook-Abos, die Shopify gerade kennt
 * (auf Knopfdruck live abgefragt), und der Stand des Zwischenspeichers.
 * Verlinkt aus admin/jobs.php (Karte "Shopify").
 */

use Connect\Http\HttpClient;
use Connect\Routing\LiveSwitch;

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$pdo = $app->pdo;
$shop = $app->shop;
$isAdmin = true;

$flashMessage = null;
$flashType = 'info';

$buildLiveSwitch = static fn (): LiveSwitch => new LiveSwitch(
    $pdo,
    $app->crypto,
    new HttpClient(),
    $app->config->require('CONNECT_APP_URL'),
    $app->config->optional('CONNECT_SHOPIFY_API_VERSION', '2026-07')
);

// Abos live bei Shopify abfragen (nur lesen) oder passend zum Schalter abgleichen
$liveHooks = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['list_webhooks', 'sync_webhooks'], true)) {
    try {
        if ($_POST['action'] === 'sync_webhooks') {
            $notes = $buildLiveSwitch()->syncWebhooks($shop);
            $flashMessage = $notes === [] ? 'Webhook-Abos waren bereits passend.' : 'Abgleich: ' . implode(' ', $notes);
            $flashType = 'success';
        }
        $liveHooks = $buildLiveSwitch()->inspectWebhooks($shop);
    } catch (\Throwable $e) {
        $flashMessage = 'Shopify-Abfrage fehlgeschlagen: ' . $e->getMessage();
        $flashType = 'danger';
    }
}

$connStmt = $pdo->prepare(
    "SELECT c.id, c.label, c.status FROM connect_connections c
     JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
);
$connStmt->execute(['shop' => $shop]);
$connection = $connStmt->fetch();
$connectionId = $connection !== false ? (int) $connection['id'] : null;

$liveStmt = $pdo->prepare('SELECT live_in_connect FROM connect_shops WHERE shop = :shop');
$liveStmt->execute(['shop' => $shop]);
$isLive = (int) $liveStmt->fetchColumn() === 1;

$bucket = null;
$hours = [];
$usageMissing = false;
if ($connectionId !== null) {
    try {
        $bucketStmt = $pdo->prepare(
            'SELECT currently_available, maximum_available, restore_rate, last_throttled_at, updated_at,
                    TIMESTAMPDIFF(MINUTE, updated_at, NOW()) AS minutes_ago
             FROM connect_api_bucket WHERE connection_id = :id'
        );
        $bucketStmt->execute(['id' => $connectionId]);
        $bucket = $bucketStmt->fetch() ?: null;

        $hoursStmt = $pdo->prepare(
            'SELECT hour_start, calls, cost, throttled, errors FROM connect_api_usage_hourly
             WHERE connection_id = :id AND hour_start >= NOW() - INTERVAL 1 DAY ORDER BY hour_start DESC'
        );
        $hoursStmt->execute(['id' => $connectionId]);
        $hours = $hoursStmt->fetchAll();
    } catch (\Throwable) {
        $usageMissing = true;
    }
}
$maxCalls = 1;
foreach ($hours as $h) {
    $maxCalls = max($maxCalls, (int) $h['calls']);
}

$topicsStmt = $pdo->prepare(
    "SELECT topic,
            SUM(status = 'processed') AS processed, SUM(status = 'received') AS received,
            SUM(status = 'ignored') AS ignored, SUM(status = 'error') AS errored,
            MAX(received_at) AS last_received
     FROM connect_webhook_events WHERE shop = :shop AND received_at >= NOW() - INTERVAL 1 DAY
     GROUP BY topic ORDER BY topic"
);
$topicsStmt->execute(['shop' => $shop]);
$topics = $topicsStmt->fetchAll();

$eventsStmt = $pdo->prepare(
    'SELECT id, topic, status, error_message, received_at FROM connect_webhook_events
     WHERE shop = :shop ORDER BY id DESC LIMIT 20'
);
$eventsStmt->execute(['shop' => $shop]);
$events = $eventsStmt->fetchAll();

$localSubs = [];
if ($connectionId !== null) {
    $subsStmt = $pdo->prepare('SELECT topic, external_webhook_id, created_at FROM connect_webhook_subscriptions WHERE connection_id = :id ORDER BY topic');
    $subsStmt->execute(['id' => $connectionId]);
    $localSubs = $subsStmt->fetchAll();
}

$cache = [];
if ($connectionId !== null) {
    $cacheStmt = $pdo->prepare(
        "SELECT object_key, SUM(status = 'active') AS active, SUM(status = 'orphaned') AS orphaned, MAX(fetched_at) AS last_fetched
         FROM connect_object_records WHERE connection_id = :id GROUP BY object_key ORDER BY object_key"
    );
    $cacheStmt->execute(['id' => $connectionId]);
    $cache = $cacheStmt->fetchAll();
}

$hookBadge = ['processed' => 'bg-green-lt', 'received' => 'bg-secondary-lt', 'ignored' => 'bg-yellow-lt', 'error' => 'bg-red-lt'];

$pageTitle = 'Shopify-Aktivität';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = require dirname(__DIR__) . '/app/_shop_nav.php';
$breadcrumbs = [
    ['jobs.php', 'Jobs'],
    [null, 'Shopify · ' . $shop],
];
$adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <?php if ($connectionId === null): ?>
        <div class="col-12"><div class="alert alert-warning">Dieser Shop hat keine Shopify-Connection.</div></div>
    <?php else: ?>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">Kosten-Eimer</h3></div>
                <div class="card-body">
                    <?php if ($usageMissing): ?>
                        <div class="alert alert-warning mb-0">Braucht Migration 0068 (<code>php connect/bin/install.php</code>).</div>
                    <?php elseif ($bucket === null): ?>
                        <p class="text-secondary mb-0">Noch keine Meldung von Shopify. Der Stand erscheint nach dem ersten GraphQL-Aufruf.</p>
                    <?php else: ?>
                        <?php
                        $max = (float) $bucket['maximum_available'];
                        $available = (float) $bucket['currently_available'];
                        $pct = $max > 0 ? max(0, min(100, $available / $max * 100)) : 0;
                        $barClass = $pct < 20 ? 'bg-red' : ($pct < 50 ? 'bg-yellow' : 'bg-green');
                        ?>
                        <div class="h2 mb-1" style="font-variant-numeric: tabular-nums"><?php echo number_format($available, 0, ',', '.'); ?> <span class="text-secondary fs-4">/ <?php echo number_format($max, 0, ',', '.'); ?> Punkte</span></div>
                        <div class="progress mb-2"><div class="progress-bar <?php echo $barClass; ?>" style="width: <?php echo round($pct); ?>%"></div></div>
                        <div class="text-secondary small">
                            Füllt sich mit <?php echo number_format((float) $bucket['restore_rate'], 0, ',', '.'); ?> Punkten pro Sekunde auf.
                            Gemeldet vor <?php echo (int) $bucket['minutes_ago']; ?> Min.
                            <?php if ($bucket['last_throttled_at'] !== null): ?>
                                <br>Zuletzt gedrosselt: <?php echo htmlspecialchars((string) $bucket['last_throttled_at']); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <hr>
                    <div class="small">
                        Shop läuft <strong><?php echo $isLive ? 'in Connect' : 'in der alten Welt'; ?></strong> ·
                        Connection „<?php echo htmlspecialchars((string) $connection['label']); ?>“ (<?php echo htmlspecialchars((string) $connection['status']); ?>)
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">API-Aufrufe der letzten 24 h</h3></div>
                <?php if ($hours === []): ?>
                    <div class="card-body text-secondary">Keine Aufrufe erfasst<?php echo $usageMissing ? ' (Migration 0068 fehlt)' : ''; ?>.</div>
                <?php else: ?>
                    <div class="table-responsive" style="max-height: 340px">
                        <table class="table table-sm table-vcenter card-table">
                            <thead><tr><th>Stunde</th><th style="width:40%">Aufrufe</th><th class="text-end">Kosten</th><th class="text-end">Gedrosselt</th><th class="text-end">Fehler</th></tr></thead>
                            <tbody>
                                <?php foreach ($hours as $h): ?>
                                    <tr>
                                        <td class="text-secondary"><?php echo htmlspecialchars(substr((string) $h['hour_start'], 5, 11)); ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress progress-sm flex-fill"><div class="progress-bar bg-primary" style="width: <?php echo round((int) $h['calls'] / $maxCalls * 100); ?>%"></div></div>
                                                <span style="font-variant-numeric: tabular-nums; min-width: 3em" class="text-end"><?php echo (int) $h['calls']; ?></span>
                                            </div>
                                        </td>
                                        <td class="text-end" style="font-variant-numeric: tabular-nums"><?php echo number_format((int) $h['cost'], 0, ',', '.'); ?></td>
                                        <td class="text-end <?php echo (int) $h['throttled'] > 0 ? 'text-warning fw-bold' : 'text-secondary'; ?>"><?php echo (int) $h['throttled']; ?></td>
                                        <td class="text-end <?php echo (int) $h['errors'] > 0 ? 'text-danger fw-bold' : 'text-secondary'; ?>"><?php echo (int) $h['errors']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">Webhooks der letzten 24 h</h3></div>
                <?php if ($topics === []): ?>
                    <div class="card-body text-secondary">Keine Webhooks empfangen.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead><tr><th>Thema</th><th>Status</th><th>Zuletzt</th></tr></thead>
                            <tbody>
                                <?php foreach ($topics as $t): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars((string) $t['topic']); ?></code></td>
                                        <td>
                                            <?php foreach (['processed' => 'processed', 'received' => 'received', 'ignored' => 'ignored', 'errored' => 'error'] as $col => $label): ?>
                                                <?php if ((int) $t[$col] > 0): ?><span class="badge <?php echo $hookBadge[$label]; ?>"><?php echo (int) $t[$col]; ?> <?php echo $label; ?></span><?php endif; ?>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="text-secondary small"><?php echo htmlspecialchars((string) $t['last_received']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="card-body border-top">
                    <div class="flow-h small text-secondary text-uppercase fw-bold mb-2">Letzte Ereignisse</div>
                    <?php if ($events === []): ?>
                        <span class="text-secondary">Keine.</span>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0 small d-grid gap-1">
                            <?php foreach ($events as $e): ?>
                                <li>
                                    <span class="badge <?php echo $hookBadge[$e['status']] ?? 'bg-secondary-lt'; ?>"><?php echo htmlspecialchars((string) $e['status']); ?></span>
                                    <a href="webhook_event.php?event_id=<?php echo (int) $e['id']; ?>"><?php echo htmlspecialchars((string) $e['topic']); ?></a>
                                    <span class="text-secondary"><?php echo htmlspecialchars((string) $e['received_at']); ?></span>
                                    <?php if ($e['error_message'] !== null && $e['error_message'] !== ''): ?>
                                        <span class="text-secondary">– <?php echo htmlspecialchars(mb_strimwidth((string) $e['error_message'], 0, 90, '…')); ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">Webhook-Abos</h3></div>
                <div class="card-body">
                    <p class="text-secondary small">
                        Laut Connect angelegt: <?php echo $localSubs === [] ? 'keine' : htmlspecialchars(implode(', ', array_map(static fn ($s) => $s['topic'], $localSubs))); ?>.
                        Was Shopify tatsächlich kennt, zeigt die Abfrage. Für einen Shop in Connect sollten <code>orders/paid</code> und <code>products/update</code> nur einmal auf connect.oview.at zeigen.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <form method="post"><input type="hidden" name="action" value="list_webhooks"><button type="submit" class="btn btn-outline-primary">Bei Shopify abfragen</button></form>
                        <form method="post"><input type="hidden" name="action" value="sync_webhooks"><button type="submit" class="btn btn-outline-secondary" title="Legt fehlende Abos an und entfernt die der jeweils anderen Welt, passend zum Live-Schalter">Abos abgleichen</button></form>
                    </div>
                </div>
                <?php if ($liveHooks !== null): ?>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead><tr><th>Thema</th><th>Welt</th><th>Adresse</th></tr></thead>
                            <tbody>
                                <?php if ($liveHooks === []): ?>
                                    <tr><td colspan="3" class="text-secondary">Shopify kennt keine Abos für diese App.</td></tr>
                                <?php endif; ?>
                                <?php
                                $topicCount = [];
                                foreach ($liveHooks as $hook) {
                                    $topicCount[$hook['topic']] = ($topicCount[$hook['topic']] ?? 0) + 1;
                                }
                                ?>
                                <?php foreach ($liveHooks as $hook): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($hook['topic']); ?></code><?php if ($topicCount[$hook['topic']] > 1): ?> <span class="badge bg-yellow-lt">doppelt</span><?php endif; ?></td>
                                        <td><?php echo ['connect' => '<span class="badge bg-blue-lt">Connect</span>', 'legacy' => '<span class="badge bg-secondary-lt">alte Welt</span>', 'other' => '<span class="badge bg-secondary-lt">andere</span>'][$hook['owner']]; ?></td>
                                        <td class="small text-secondary text-break"><?php echo htmlspecialchars($hook['address']); ?></td>
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
                <div class="card-header"><h3 class="card-title">Zwischenspeicher</h3></div>
                <?php if ($cache === []): ?>
                    <div class="card-body text-secondary">Noch nichts zwischengespeichert.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead><tr><th>Object</th><th class="text-end">Aktiv</th><th class="text-end">Verwaist</th><th>Zuletzt aktualisiert</th></tr></thead>
                            <tbody>
                                <?php foreach ($cache as $c): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars((string) $c['object_key']); ?></code></td>
                                        <td class="text-end" style="font-variant-numeric: tabular-nums"><?php echo number_format((int) $c['active'], 0, ',', '.'); ?></td>
                                        <td class="text-end text-secondary" style="font-variant-numeric: tabular-nums"><?php echo number_format((int) $c['orphaned'], 0, ',', '.'); ?></td>
                                        <td class="text-secondary"><?php echo htmlspecialchars((string) $c['last_fetched']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
