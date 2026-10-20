<?php

declare(strict_types=1);

/**
 * connect/public/app/_status_body.php
 *
 * Merchant-Seite "Status" (seit 2026-10-05): die Technik hinter oView in Alltagssprache --
 * Verbindungen (mit Test), Sofort-Meldungen von Shopify (Webhook-Abos), Datenstand
 * (Zwischenspeicher je Object), Zeitplan (aktive Aktivierungen) und zugeklappt die
 * technischen Details fuer den Support. Ersetzt die Technik-Karten im Dashboard und den
 * Menuepunkt "Connections" fuer den Merchant (connections.php bleibt als Unterseite fuer
 * "Schlüssel ändern" erreichbar).
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

use Connect\Connector\ConnectorFactory;
use Connect\Connector\TestResult;
use Connect\Http\HttpClient;
use Connect\Shopify\TokenRefresher;

$isAdmin = $isAdmin ?? false;

$pdo = $app->pdo;
$shop = $app->shop;
$previewSuffix = isset($_GET['preview']) ? '&preview=1' : '';
$q = '?shop=' . urlencode($shop) . $previewSuffix;

$flashMessage = null;
$flashType = 'info';

// -----------------------------------------------------------------------
// POST: alle Verbindungen testen
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_all') {
    $factory = new ConnectorFactory($pdo, $app->crypto, new HttpClient(), TokenRefresher::fromConfig($app->config));
    $failed = [];
    $idsStmt = $pdo->prepare('SELECT c.id, t.name FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id WHERE c.shop = :shop');
    $idsStmt->execute(['shop' => $shop]);
    foreach ($idsStmt->fetchAll() as $conn) {
        $previousStatus = \Connect\Sync\JobScheduler::connectionStatus($pdo, (int) $conn['id']);
        try {
            $result = $factory->forConnection((int) $conn['id'])->testConnection();
        } catch (\Throwable $e) {
            $result = TestResult::failure($e->getMessage());
        }
        $pdo->prepare('UPDATE connect_connections SET status = :status, last_test_at = CURRENT_TIMESTAMP, last_test_result = :result WHERE id = :id')
            ->execute(['status' => $result->success ? 'active' : 'error', 'result' => $result->message, 'id' => (int) $conn['id']]);
        if (!$result->success) {
            $failed[] = (string) $conn['name'];
        } else {
            \Connect\Setup\ConnectionAccount::refresh($pdo, $factory, (int) $conn['id']); // Firmenname nachziehen
            \Connect\Sync\JobScheduler::afterSuccessfulTest($pdo, (int) $conn['id'], $previousStatus);
        }
    }
    $flashMessage = $failed === [] ? 'Alle Verbindungen funktionieren.' : 'Nicht erreichbar: ' . implode(', ', $failed) . '. Prüfe dort die Zugangsdaten.';
    $flashType = $failed === [] ? 'success' : 'danger';
}

// -----------------------------------------------------------------------
// Daten
// -----------------------------------------------------------------------
$connStmt = $pdo->prepare(
    "SELECT c.id, c.status, c.last_test_result, c.config, t.name, t.type_key,
            DATE_FORMAT(c.last_test_at, '%d.%m.%Y %H:%i') AS tested_at, TIMESTAMPDIFF(MINUTE, c.last_test_at, NOW()) AS tested_ago
     FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE c.shop = :shop ORDER BY t.type_key = 'shopify' DESC, t.name"
);
$connStmt->execute(['shop' => $shop]);
$connections = $connStmt->fetchAll();

$topicLabels = [
    'orders/paid' => 'Bestellung bezahlt',
    'orders/create' => 'Bestellung angelegt',
    'products/update' => 'Produkt geändert',
    'app/uninstalled' => 'App entfernt',
];
$subsStmt = $pdo->prepare(
    'SELECT ws.topic, (SELECT DATE_FORMAT(MAX(e.received_at), \'%d.%m. %H:%i\') FROM connect_webhook_events e WHERE e.shop = :shop AND e.topic = ws.topic) AS last_received
     FROM connect_webhook_subscriptions ws JOIN connect_connections c ON c.id = ws.connection_id
     WHERE c.shop = :shop2 ORDER BY ws.topic'
);
$subsStmt->execute(['shop' => $shop, 'shop2' => $shop]);
$subscriptions = $subsStmt->fetchAll();

$dataStmt = $pdo->prepare(
    "SELECT t.name AS system, COALESCE(o.label, r.object_key) AS label, r.object_key, COUNT(*) AS n,
            DATE_FORMAT(MAX(r.fetched_at), '%d.%m. %H:%i') AS fetched
     FROM connect_object_records r
     JOIN connect_connections c ON c.id = r.connection_id
     JOIN connect_connector_types t ON t.id = c.connector_type_id
     LEFT JOIN connect_objects o ON o.connector_type_id = c.connector_type_id AND o.object_key = r.object_key
     WHERE c.shop = :shop AND r.status = 'active'
     GROUP BY t.type_key, t.name, label, r.object_key ORDER BY t.type_key = 'shopify' DESC, t.name, n DESC"
);
$dataStmt->execute(['shop' => $shop]);
$dataRows = $dataStmt->fetchAll();

$scheduleStmt = $pdo->prepare(
    "SELECT m.id, m.name, m.mapping_kind, m.trigger_type, m.trigger_topic, m.schedule_interval_minutes,
            (SELECT DATE_FORMAT(DATE_ADD(MAX(j.scheduled_for), INTERVAL m.schedule_interval_minutes MINUTE), '%H:%i') FROM connect_sync_jobs j WHERE j.sync_mapping_id = m.id) AS next_time,
            (SELECT COUNT(*) FROM connect_sync_jobs j2 WHERE j2.sync_mapping_id = m.id AND j2.status IN ('pending', 'running')) AS busy,
            (SELECT r.status FROM connect_sync_runs r WHERE r.sync_mapping_id = m.id ORDER BY r.id DESC LIMIT 1) AS last_status,
            (SELECT DATE_FORMAT(r2.started_at, '%d.%m. %H:%i') FROM connect_sync_runs r2 WHERE r2.sync_mapping_id = m.id ORDER BY r2.id DESC LIMIT 1) AS last_at
     FROM connect_sync_mappings m WHERE m.shop = :shop AND m.enabled = 1
     ORDER BY m.mapping_kind = 'stock_sync' DESC, m.mapping_kind = 'invoice' DESC, m.name"
);
$scheduleStmt->execute(['shop' => $shop]);
$schedule = $scheduleStmt->fetchAll();

$scheduleLabel = static function (array $m): string {
    return match ((string) $m['mapping_kind']) {
        'stock_sync' => 'Lagerbestand abgleichen',
        'stock_back' => 'Kassenverkäufe in Shopify zurückbuchen',
        'invoice' => 'Rechnung bei bezahlter Bestellung',
        'auto_import_products' => 'Neue Produkte automatisch übernehmen',
        'product_visibility' => 'Ausverkaufte Produkte ausblenden',
        default => 'Daten laden: ' . $m['name'],
    };
};
$intervalLabel = static function (?int $minutes): string {
    return match (true) {
        $minutes === null => '',
        $minutes % 1440 === 0 => $minutes === 1440 ? 'täglich' : 'alle ' . ($minutes / 1440) . ' Tage',
        $minutes % 60 === 0 => $minutes === 60 ? 'stündlich' : 'alle ' . ($minutes / 60) . ' Std.',
        default => 'alle ' . $minutes . ' Min.',
    };
};

$pageTitle = 'Status' . ($isAdmin ? ' — ' . $shop : '');
$navItems = require __DIR__ . '/_shop_nav.php';

if ($isAdmin) {
    $homeUrl = 'shops.php';
    $adminNavItems = require dirname(__DIR__) . '/admin/_nav.php';
    $adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
    $topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
        . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
}

require __DIR__ . '/_header.php';
?>

<style>
    .st-mark { width: 8px; height: 8px; border-radius: 50%; margin-top: .45rem; flex: none; }
    .st-mark.ok { background: var(--tblr-green, #2fb344); }
    .st-mark.err { background: var(--tblr-red, #d63939); }
    .st-mark.wait { background: var(--tblr-yellow, #f59f00); }
</style>

<p class="text-secondary">Die Technik hinter oView. Normalerweise musst du hier nichts tun. Bei Fragen hilft diese Seite dem Support.</p>

<div class="row row-cards">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Verbindungen</h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ($connections as $conn): ?>
                    <?php $tone = $conn['status'] === 'active' ? 'ok' : ($conn['status'] === 'error' ? 'err' : 'wait'); ?>
                    <div class="list-group-item d-flex gap-3 align-items-start">
                        <span class="st-mark <?php echo $tone; ?>"></span>
                        <div class="flex-fill" style="min-width: 0;">
                            <div class="fw-bold"><?php echo htmlspecialchars((string) $conn['name']); ?></div>
                            <?php $accountSummary = \Connect\Setup\ConnectionAccount::summary($conn['config'] ?? null); ?>
                            <?php if ($accountSummary !== ''): ?>
                                <div class="small"><?php echo htmlspecialchars($accountSummary); ?></div>
                            <?php endif; ?>
                            <div class="text-secondary small">
                                <?php echo ['ok' => 'verbunden', 'err' => 'funktioniert nicht', 'wait' => 'noch nicht geprüft'][$tone]; ?>
                                <?php echo $conn['tested_at'] !== null ? ' · zuletzt geprüft ' . htmlspecialchars((string) $conn['tested_at']) : ''; ?>
                            </div>
                            <?php if ($tone === 'err' && ($conn['last_test_result'] ?? '') !== ''): ?>
                                <div class="text-danger small"><?php echo htmlspecialchars(mb_strimwidth((string) $conn['last_test_result'], 0, 200, '…')); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($conn['type_key'] !== 'shopify'): ?>
                            <a href="connections.php<?php echo htmlspecialchars($q); ?>" class="btn btn-sm">Schlüssel ändern</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <span class="text-secondary small">Alle Verbindungen jetzt testen</span>
                <form method="post" class="m-0"><input type="hidden" name="action" value="test_all"><button type="submit" class="btn btn-sm">Testen</button></form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Sofort-Meldungen von Shopify</h3></div>
            <div class="card-body pb-0 text-secondary small">Shopify meldet diese Ereignisse direkt an oView, ohne auf den nächsten Abgleich zu warten.</div>
            <div class="list-group list-group-flush">
                <?php foreach ($subscriptions as $sub): ?>
                    <div class="list-group-item d-flex gap-3">
                        <span class="st-mark ok"></span>
                        <div>
                            <div><?php echo htmlspecialchars($topicLabels[$sub['topic']] ?? (string) $sub['topic']); ?></div>
                            <div class="text-secondary small"><?php echo $sub['last_received'] !== null ? 'zuletzt ' . htmlspecialchars((string) $sub['last_received']) : 'noch keine Meldung erhalten'; ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($subscriptions === []): ?>
                    <div class="list-group-item text-secondary">Keine Sofort-Meldungen eingerichtet. oView gleicht nur nach Zeitplan ab.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Datenstand</h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ($dataRows as $data): ?>
                    <div class="list-group-item d-flex gap-3">
                        <span class="st-mark ok"></span>
                        <div>
                            <div><?php echo htmlspecialchars((string) $data['system']); ?>: <?php echo number_format((int) $data['n'], 0, ',', '.'); ?> <?php echo htmlspecialchars((string) $data['label']); ?></div>
                            <div class="text-secondary small">aktualisiert <?php echo htmlspecialchars((string) $data['fetched']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($dataRows === []): ?>
                    <div class="list-group-item text-secondary">Noch keine Daten geladen. Das passiert in den ersten Minuten nach der Einrichtung.</div>
                <?php endif; ?>
                <div class="list-group-item text-secondary small">Bestellungen bewahrt oView 90 Tage auf, ältere werden aus Datenschutzgründen gelöscht.</div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Automatische Abläufe</h3></div>
            <?php $statusPlan = \Connect\Billing\Plans::forShop($pdo, $shop); ?>
            <?php if ($statusPlan !== null): ?>
                <div class="card-body pb-0 text-secondary small">
                    Dein Abo <?php echo htmlspecialchars(\Connect\Billing\Plans::PLANS[$statusPlan]['name']); ?>: Lagerabgleich alle <?php echo \Connect\Billing\Plans::stockMinutes($statusPlan); ?> Minuten.
                    <a href="billing.php<?php echo htmlspecialchars($q); ?>">Abo ansehen</a>
                </div>
            <?php endif; ?>
            <div class="list-group list-group-flush">
                <?php foreach ($schedule as $m): ?>
                    <?php
                    $interval = $m['schedule_interval_minutes'] !== null ? (int) $m['schedule_interval_minutes'] : null;
                    $when = $m['trigger_type'] === 'webhook' ? 'sofort' : $intervalLabel($interval);
                    $sub = (int) $m['busy'] > 0 ? 'läuft gerade' : ($interval !== null && $m['next_time'] !== null ? 'nächster Lauf ca. ' . $m['next_time'] : ($m['trigger_type'] === 'webhook' ? 'wenn Shopify die Bestellung meldet' : 'in Kürze'));
                    if ($m['trigger_type'] === 'webhook' && $interval !== null) {
                        $sub = 'plus Nachprüfung ' . $intervalLabel($interval);
                    }
                    // Ergebnis des letzten Laufs (frueher Karte "Automatische Abgleiche" in den Einstellungen)
                    $lastTone = match ((string) $m['last_status']) {
                        'success' => 'ok',
                        'error' => 'err',
                        '' => null,
                        default => 'wait',
                    };
                    $lastText = match ($lastTone) {
                        'ok' => 'zuletzt ' . $m['last_at'] . ' – erfolgreich',
                        'err' => 'zuletzt ' . $m['last_at'] . ' – mit Fehler',
                        'wait' => 'zuletzt ' . $m['last_at'] . ($m['last_status'] === 'partial' ? ' – teilweise erledigt' : ' – läuft'),
                        default => 'noch nie gelaufen',
                    };
                    ?>
                    <div class="list-group-item d-flex gap-3">
                        <span class="text-secondary small" style="width: 4.5rem; flex: none;"><?php echo htmlspecialchars($when); ?></span>
                        <div class="flex-fill">
                            <div><?php echo htmlspecialchars($scheduleLabel($m)); ?></div>
                            <div class="text-secondary small"><?php echo htmlspecialchars($sub); ?></div>
                            <div class="small d-flex align-items-center gap-1 <?php echo $lastTone === 'err' ? 'text-danger' : 'text-secondary'; ?>">
                                <?php if ($lastTone !== null): ?><span class="st-mark <?php echo $lastTone; ?>" style="margin-top:0"></span><?php endif; ?>
                                <?php echo htmlspecialchars($lastText); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($schedule === []): ?>
                    <div class="list-group-item text-secondary">Nichts aktiv.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Für den Support</h3></div>
            <div class="card-body">
                <details>
                    <summary class="text-secondary" style="cursor:pointer;">Technische Details anzeigen</summary>
<pre class="mt-2 small">Shop: <?php echo htmlspecialchars($shop); ?>

Connections:
<?php foreach ($connections as $conn): ?>  #<?php echo (int) $conn['id']; ?> <?php echo htmlspecialchars($conn['type_key'] . ' ' . $conn['status']); ?>

<?php endforeach; ?>
Webhooks: <?php echo htmlspecialchars(implode(', ', array_column($subscriptions, 'topic')) ?: '–'); ?>


Aktivierungen:
<?php foreach ($schedule as $m): ?>  #<?php echo (int) $m['id']; ?> <?php echo htmlspecialchars($m['mapping_kind'] . ' · ' . $m['name'] . ' · ' . $m['trigger_type'] . ($m['schedule_interval_minutes'] !== null ? ' ' . $m['schedule_interval_minutes'] . ' min' : '')); ?>

<?php endforeach; ?></pre>
                </details>
            </div>
        </div>
    </div>
</div>

<?php if (!$isAdmin && $previewSuffix === ''): ?>
    <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
        <span class="text-secondary small">Etwas grundsätzlich ändern?</span>
        <a href="welcome.php?shop=<?php echo urlencode($shop); ?>&amp;rerun=1" class="btn btn-sm btn-outline-primary">Einrichtungs-Assistent starten</a>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
