<?php

declare(strict_types=1);

/**
 * connect/public/admin/migrate.php
 *
 * Umzug eines Shops aus der alten Welt in vier Schritten:
 *   1. Shopify-Token (bestehende Migrationsbruecke, Shops -> "Token uebernehmen")
 *   2. Einstellungen der alten Welt gegenueberstellen und uebernehmen
 *      (Connect\Legacy\ShopMigrator: Einstellungen, HelloCash-Connection,
 *      Zielsystem, Aktivierungen)
 *   3. Bereitschaft: Verbindungen und die Ampeln der Ablauf-Ansicht
 *      (Connect\Flow\FlowInspector)
 *   4. Umstellen (Connect\Routing\LiveSwitch: Schalter + Webhook-Abos)
 *
 * Bis Schritt 4 schreibt Connect fuer den Shop nichts in Shopify/HelloCash:
 * order_paid ignoriert nicht umgestellte Shops, schreibende Scheduler-Jobs
 * werden uebersprungen (SyncRunner). Lese-Jobs fuellen schon den Zwischenspeicher.
 */

use Connect\Connector\ConnectorFactory;
use Connect\Flow\FlowInspector;
use Connect\Http\HttpClient;
use Connect\Legacy\ShopMigrator;
use Connect\Routing\LiveSwitch;
use Connect\Shopify\TokenRefresher;

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$pdo = $app->pdo;
$shop = $app->shop;
$isAdmin = true;

$flashMessage = null;
$flashType = 'info';

$migrator = new ShopMigrator($pdo, $app->config, $app->crypto);
$factory = new ConnectorFactory($pdo, $app->crypto, new HttpClient(), TokenRefresher::fromConfig($app->config));
$liveSwitch = new LiveSwitch($pdo, $app->crypto, new HttpClient(), $app->config->require('CONNECT_APP_URL'), $app->config->optional('CONNECT_SHOPIFY_API_VERSION', '2026-07'), TokenRefresher::fromConfig($app->config));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'apply') {
            $notes = $migrator->apply($shop, $factory);
            $flashMessage = 'Übernommen. ' . implode(' ', $notes);
            $flashType = 'success';
        } elseif ($action === 'go_live') {
            if (empty($_POST['confirm'])) {
                $flashMessage = 'Bitte bestätigen, dass die Bereitschaft geprüft ist.';
                $flashType = 'warning';
            } else {
                $result = $liveSwitch->setLive($shop, true);
                $flashMessage = $result['message'];
                $flashType = $result['ok'] ? 'success' : 'danger';
            }
        } elseif ($action === 'go_back') {
            $result = $liveSwitch->setLive($shop, false);
            $flashMessage = $result['message'];
            $flashType = $result['ok'] ? 'success' : 'danger';
        } elseif ($action === 'set_token') {
            // Neu generierter helloCash-Token (z.B. am Telefon vom Merchant): wie public/prepare.php
            // sofort in der alten Welt, in Connect und fuer den Umzug einsetzen.
            $newToken = preg_replace('/\s+/', '', (string) ($_POST['token'] ?? ''));
            if ($newToken === '') {
                throw new \RuntimeException('Bitte den Token einfügen.');
            }
            $res = (new \Connect\Setup\TargetAccessCheck($pdo, $app->crypto, $factory, $app->config, new HttpClient()))->prepare(explode('.', $shop)[0], $newToken);
            if (!$res['valid']) {
                $flashMessage = 'Token nicht übernommen: ' . ($res['error'] ?? 'helloCash nimmt ihn nicht an.');
                $flashType = 'danger';
            } else {
                $flashMessage = 'Token eingesetzt. Alte Welt: ' . (['updated' => 'aktualisiert', 'not_found' => 'Shop NICHT gefunden – bitte manuell eintragen!'][$res['legacy']] ?? ('NICHT aktualisiert – ' . $res['legacy']))
                    . ' · API v1: ' . ($res['v1_ok'] === true ? 'ok' : ($res['v1_ok'] === false ? 'FUNKTIONIERT NICHT' : 'nicht prüfbar'))
                    . ' · Connect: ' . ($res['applied'] ? 'Connection aktualisiert' : 'wird beim Umzug verwendet')
                    . ' · API v2: ' . ($res['ok'] ? 'Zugriff vollständig' : 'es fehlen ' . implode(', ', $res['missing']));
                $flashType = $res['ok'] && $res['legacy'] === 'updated' ? 'success' : 'warning';
            }
        } elseif ($action === 'check_access') {
            $hcId = (int) ($_POST['connection_id'] ?? 0);
            $access = (new \Connect\Setup\TargetAccessCheck($pdo, $app->crypto, $factory))->checkConnection($hcId);
            $flashMessage = $access['ok']
                ? 'Zugriff vollständig: der Token kann alles lesen, was Connect braucht.'
                : 'Zugriff unvollständig: ' . implode(', ', $access['missing'] !== [] ? $access['missing'] : [(string) $access['error']]) . '.';
            $flashType = $access['ok'] ? 'success' : 'danger';
        } elseif ($action === 'test_connections') {
            $results = [];
            $conns = $pdo->prepare('SELECT c.id, c.label FROM connect_connections c WHERE c.shop = :shop ORDER BY c.id');
            $conns->execute(['shop' => $shop]);
            foreach ($conns->fetchAll() as $conn) {
                try {
                    $previousStatus = \Connect\Sync\JobScheduler::connectionStatus($pdo, (int) $conn['id']);
                    $test = $factory->forConnection((int) $conn['id'])->testConnection();
                    $pdo->prepare('UPDATE connect_connections SET status = :status, last_test_at = CURRENT_TIMESTAMP, last_test_result = :result WHERE id = :id')
                        ->execute(['status' => $test->success ? 'active' : 'error', 'result' => $test->message, 'id' => (int) $conn['id']]);
                    if ($test->success) {
                        \Connect\Sync\JobScheduler::afterSuccessfulTest($pdo, (int) $conn['id'], $previousStatus);
                    }
                    $results[] = $conn['label'] . ': ' . ($test->success ? 'OK' : $test->message);
                } catch (\Throwable $e) {
                    $results[] = $conn['label'] . ': ' . $e->getMessage();
                }
            }
            $flashMessage = 'Verbindungstests: ' . implode(' · ', $results);
            $flashType = 'info';
        }
    } catch (\Throwable $e) {
        $flashMessage = 'Fehler: ' . $e->getMessage();
        $flashType = 'danger';
    }
}

// ---- Zustand ----------------------------------------------------------------------
$shopStmt = $pdo->prepare('SELECT live_in_connect, live_changed_at FROM connect_shops WHERE shop = :shop');
$shopStmt->execute(['shop' => $shop]);
$shopRow = $shopStmt->fetch() ?: null;
$isLive = $shopRow !== null && (int) $shopRow['live_in_connect'] === 1;

$connStmt = $pdo->prepare(
    'SELECT c.id, c.label, c.status, c.last_test_at, c.last_test_result, c.config, t.type_key, t.name AS type_name
     FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id WHERE c.shop = :shop ORDER BY t.type_key = \'shopify\' DESC, c.id'
);
$connStmt->execute(['shop' => $shop]);
$connections = $connStmt->fetchAll();
$shopifyActive = false;
$targetConnection = null;
foreach ($connections as $c) {
    if ($c['type_key'] === 'shopify' && $c['status'] === 'active') {
        $shopifyActive = true;
    }
    if ($c['type_key'] === 'hellocash' && $targetConnection === null) {
        $targetConnection = $c;
    }
}
// Zugriff des helloCash-Tokens je Object (Connect\Setup\TargetAccessCheck): API v2 prueft
// Berechtigungen je Token, die v1-Tokens der alten Welt haben sie oft nicht.
$targetAccess = $targetConnection !== null ? \Connect\Setup\TargetAccessCheck::stored((string) $targetConnection['config']) : null;
$targetAccessMissing = $targetAccess !== null && empty($targetAccess['ok']);
$preparedRow = \Connect\Setup\TargetAccessCheck::preparedRow($pdo, $shop);

// Shopify-Berechtigungen (Connect\Shopify\ScopeCheck): live bei Shopify abgefragt
$scopeCheck = new \Connect\Shopify\ScopeCheck($pdo, $app->crypto, $app->config);
$scopes = $shopRow !== null ? $scopeCheck->check($shop) : null;
$scopesMissing = $scopes !== null && $scopes['ok'] === false;

$legacyError = null;
$plan = [];
$legacyFound = false;
try {
    $legacyFound = $migrator->loadLegacy($shop) !== null;
    $plan = $legacyFound ? $migrator->plan($shop, $factory) : [];
} catch (\Throwable $e) {
    $legacyError = $e->getMessage();
}
$planCounts = [ShopMigrator::STATUS_SAME => 0, ShopMigrator::STATUS_CHANGE => 0, ShopMigrator::STATUS_MANUAL => 0, ShopMigrator::STATUS_UNSUPPORTED => 0];
foreach ($plan as $row) {
    $planCounts[$row['status']]++;
}

$flows = [];
try {
    $flows = $shopRow !== null ? (new FlowInspector($pdo))->forShop($shop) : [];
} catch (\Throwable) {
    $flows = [];
}
$flowSummary = [];
$blocking = 0;
foreach ($flows as $flow) {
    $counts = ['err' => 0, 'warn' => 0];
    $problems = [];
    foreach ($flow['nodes'] as $node) {
        if (isset($counts[$node['status']])) {
            $counts[$node['status']]++;
            $problems[] = $node['title'] . ($node['note'] ? ': ' . $node['note'] : '');
        }
    }
    $blocking += $counts['err'];
    $flowSummary[] = ['key' => $flow['key'], 'label' => $flow['label'], 'counts' => $counts, 'problems' => $problems];
}

$statusBadge = [
    ShopMigrator::STATUS_SAME => ['bg-green-lt', 'gleich'],
    ShopMigrator::STATUS_CHANGE => ['bg-blue-lt', 'wird übernommen'],
    ShopMigrator::STATUS_MANUAL => ['bg-yellow-lt', 'manuell'],
    ShopMigrator::STATUS_UNSUPPORTED => ['bg-red-lt', 'nicht in Connect'],
];
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);

$pageTitle = 'Umzug in Connect';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = require dirname(__DIR__) . '/app/_shop_nav.php';
$adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="alert <?php echo $isLive ? 'alert-success' : 'alert-info'; ?> mb-0">
            <?php if ($isLive): ?>
                <strong><?php echo $e($shop); ?> läuft in Connect</strong> (seit <?php echo $e((string) $shopRow['live_changed_at']); ?>). Der Rückweg in die alte Welt ist unten jederzeit möglich.
            <?php else: ?>
                <strong><?php echo $e($shop); ?> läuft noch in der alten Welt.</strong> Bis zum Umstellen in Schritt 4 schreibt Connect für diesen Shop nichts in Shopify oder HelloCash – Daten werden nur gelesen und zwischengespeichert.
            <?php endif; ?>
        </div>
    </div>

    <!-- Schritt 1 -->
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">1 · Verbindungen</h3></div>
            <div class="card-body">
                <?php if ($shopRow === null): ?>
                    <div class="alert alert-warning mb-0">Der Shop ist noch nicht in Connect. Zuerst unter <a href="shops.php">Shops</a> „Token übernehmen“ (Migrationsbrücke).</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-vcenter">
                            <thead><tr><th>Connection</th><th>System</th><th>Status</th><th>Letzter Test</th></tr></thead>
                            <tbody>
                                <?php foreach ($connections as $c): ?>
                                    <tr>
                                        <td><?php echo $e((string) $c['label']); ?></td>
                                        <td><?php echo $e((string) $c['type_name']); ?></td>
                                        <td><span class="badge <?php echo $c['status'] === 'active' ? 'bg-green-lt' : ($c['status'] === 'error' ? 'bg-red-lt' : 'bg-yellow-lt'); ?>"><?php echo $e((string) $c['status']); ?></span></td>
                                        <td class="small text-secondary"><?php echo $e((string) ($c['last_test_at'] ?? '–')); ?><?php echo $c['status'] === 'error' && $c['last_test_result'] ? ' · ' . $e(mb_strimwidth((string) $c['last_test_result'], 0, 120, '…')) : ''; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <form method="post"><input type="hidden" name="action" value="test_connections"><button class="btn btn-outline-primary" type="submit">Alle Verbindungen testen</button></form>

                    <h4 class="mt-4 mb-2">helloCash-Zugriff (API v2)</h4>
                    <?php if ($targetConnection === null): ?>
                        <div class="small text-secondary">Noch keine helloCash-Connection – entsteht in Schritt 2.
                            <?php if ($preparedRow !== null && (int) $preparedRow['access_ok'] === 1): ?>
                                <span class="text-success">Der Merchant hat am <?php echo $e(substr((string) $preparedRow['submitted_at'], 0, 16)); ?> einen neuen, geprüften Token hinterlegt – Schritt 2 verwendet ihn.</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php if ($targetAccess === null): ?>
                            <div class="small text-secondary mb-2">Noch nicht geprüft. Der Verbindungstest oben reicht nicht: er hört beim ersten lesbaren Object auf.</div>
                        <?php elseif (!empty($targetAccess['ok'])): ?>
                            <div class="small text-success mb-2">Vollständig (geprüft <?php echo $e((string) $targetAccess['checked_at']); ?>).<?php echo !empty($targetAccess['optional_missing']) ? ' <span class="text-secondary">Optional fehlt: ' . $e(implode(', ', $targetAccess['optional_missing'])) . '.</span>' : ''; ?></div>
                        <?php else: ?>
                            <div class="alert alert-danger mb-2">
                                <strong>Dem Token fehlen Berechtigungen<?php echo !empty($targetAccess['missing']) ? ' für: ' . $e(implode(', ', $targetAccess['missing'])) : ''; ?>.</strong>
                                <?php echo !empty($targetAccess['error']) ? '<div class="small">' . $e((string) $targetAccess['error']) . '</div>' : ''; ?>
                                Umstellen ist erst möglich, wenn der Merchant einen neuen helloCash-Token mit allen Berechtigungen erstellt hat. Am einfachsten über
                                <a href="messages.php?new=1&amp;template=prepare">Nachrichten → Vorlage „Umstieg vorbereiten“</a>: der Merchant trägt den Token über seinen persönlichen Link selbst ein, Connect übernimmt ihn hier automatisch.
                                <?php if ($preparedRow !== null && $preparedRow['access'] !== null && (int) $preparedRow['access_ok'] !== 1): ?>
                                    <div class="small mt-1">Der Merchant hat es versucht (<?php echo $e(substr((string) $preparedRow['updated_at'], 0, 16)); ?>), der Token war aber noch unvollständig.</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <form method="post"><input type="hidden" name="action" value="check_access"><input type="hidden" name="connection_id" value="<?php echo (int) $targetConnection['id']; ?>"><button class="btn btn-outline-primary btn-sm" type="submit">Zugriff prüfen</button></form>
                    <?php endif; ?>
                    <details class="mt-2">
                        <summary class="small">Neu generierten helloCash-Token eintragen (alte Welt + Connect)</summary>
                        <form method="post" autocomplete="off" class="d-flex gap-2 mt-2 flex-wrap">
                            <input type="hidden" name="action" value="set_token">
                            <input type="text" name="token" class="form-control form-control-sm font-monospace" style="max-width: 520px" placeholder="Token aus helloCash" required spellcheck="false">
                            <button class="btn btn-sm btn-primary" type="submit">Prüfen und überall einsetzen</button>
                        </form>
                        <div class="small text-secondary mt-1">helloCash hat nur einen Token je Konto – „Neu generieren“ macht den alten sofort ungültig. Deshalb wird ein gültiger Token sofort in <code>hellocash_binding.token</code> der alten Welt, in der helloCash-Connection und für den Umzug gespeichert, auch wenn ihm noch v2-Berechtigungen fehlen.</div>
                    </details>

                    <?php if ($scopes !== null): ?>
                        <h4 class="mt-4 mb-2">Shopify-Berechtigungen</h4>
                        <?php if ($scopes['ok'] === null): ?>
                            <div class="alert alert-warning mb-0">Nicht prüfbar: <?php echo $e((string) $scopes['error']); ?></div>
                        <?php else: ?>
                            <div class="d-flex flex-wrap gap-1 mb-2">
                                <?php foreach ($scopes['required'] as $scope): ?>
                                    <?php $isMissing = in_array($scope, $scopes['missing'], true); ?>
                                    <span class="badge <?php echo $isMissing ? 'bg-red-lt' : 'bg-green-lt'; ?>" title="<?php echo $isMissing ? 'fehlt' : 'vorhanden'; ?>"><?php echo $isMissing ? '✗ ' : '✓ '; ?><?php echo $e($scope); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="small text-secondary">
                                Benötigt laut <?php echo $scopeCheck->requiredFromEnv() ? '<code>CONNECT_SHOPIFY_SCOPES</code>' : 'Standard von Connect (<code>CONNECT_SHOPIFY_SCOPES</code> in der .env nicht gesetzt – am besten denselben Wert wie <code>SHOPIFY_SCOPES</code> der alten Welt eintragen)'; ?>.
                                Erteilt: <?php echo $e($scopes['granted'] !== [] ? implode(', ', $scopes['granted']) : '–'); ?>.
                            </div>
                            <?php if ($scopesMissing): ?>
                                <div class="alert alert-danger mt-2 mb-0">
                                    <strong>Es fehlen <?php echo count($scopes['missing']); ?> Berechtigung(en): <?php echo $e(implode(', ', $scopes['missing'])); ?>.</strong>
                                    Umstellen ist erst möglich, wenn sie erteilt sind.
                                    <ol class="mb-0 mt-1">
                                        <li>Der Merchant öffnet diesen Link (eingeloggt in seinem Shopify-Admin) und bestätigt:<br>
                                            <input type="text" class="form-control form-control-sm font-monospace mt-1" readonly value="<?php echo $e($scopeCheck->authorizeUrl(explode('.', $shop)[0])); ?>" onclick="this.select()">
                                        </li>
                                        <li>Danach unter <a href="shops.php">Shops</a> bei diesem Shop „Token übernehmen“, dann diese Seite neu laden.</li>
                                    </ol>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Schritt 2 -->
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">2 · Einstellungen aus der alten Welt</h3></div>
            <?php if ($legacyError !== null): ?>
                <div class="card-body"><div class="alert alert-danger mb-0"><?php echo $e($legacyError); ?></div></div>
            <?php elseif (!$legacyFound): ?>
                <div class="card-body text-secondary">In der alten Welt gibt es für diesen Shop keine Einstellungen (neuer Shop oder anderer Name).</div>
            <?php else: ?>
                <div class="card-body pb-2 d-flex flex-wrap gap-2 align-items-center">
                    <?php foreach ($planCounts as $status => $count): ?>
                        <?php if ($count > 0): ?><span class="badge <?php echo $statusBadge[$status][0]; ?>"><?php echo $count; ?> <?php echo $statusBadge[$status][1]; ?></span><?php endif; ?>
                    <?php endforeach; ?>
                    <span class="text-secondary small ms-2">„Übernehmen“ überschreibt die Connect-Werte mit denen der alten Welt. Eine bestehende HelloCash-Connection bleibt unverändert.</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr><th>Bereich</th><th>Einstellung</th><th>Alte Welt</th><th>Connect jetzt</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($plan as $row): ?>
                                <tr class="<?php echo $row['status'] === ShopMigrator::STATUS_UNSUPPORTED ? 'table-danger' : ''; ?>">
                                    <td class="text-secondary small"><?php echo $e($row['area']); ?></td>
                                    <td>
                                        <?php echo $e($row['label']); ?>
                                        <?php if ($row['note']): ?><div class="small text-secondary"><?php echo $e($row['note']); ?></div><?php endif; ?>
                                    </td>
                                    <td><?php echo $e($row['legacy']); ?></td>
                                    <td class="text-secondary"><?php echo $e($row['connect']); ?></td>
                                    <td><span class="badge <?php echo $statusBadge[$row['status']][0]; ?>"><?php echo $statusBadge[$row['status']][1]; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    <form method="post" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="action" value="apply">
                        <button class="btn btn-primary" type="submit" <?php echo $shopRow === null ? 'disabled' : ''; ?>>Alle Einstellungen übernehmen</button>
                        <span class="text-secondary small">Legt bei Bedarf die HelloCash-Connection (mit Test), die Rechnungs- und Lagerbestand-Aktivierung an. Kann mehrfach ausgeführt werden.</span>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Schritt 3 -->
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">3 · Bereitschaft</h3></div>
            <div class="card-body">
                <?php if ($flowSummary === []): ?>
                    <span class="text-secondary">Noch keine Abläufe – erst Schritt 2 ausführen.</span>
                <?php else: ?>
                    <div class="d-grid gap-2">
                        <?php foreach ($flowSummary as $f): ?>
                            <div class="d-flex flex-wrap gap-2 align-items-start">
                                <span class="badge <?php echo $f['counts']['err'] > 0 ? 'bg-red-lt' : ($f['counts']['warn'] > 0 ? 'bg-yellow-lt' : 'bg-green-lt'); ?>" style="min-width: 7em">
                                    <?php echo $f['counts']['err'] > 0 ? $f['counts']['err'] . ' fehlt' : ($f['counts']['warn'] > 0 ? $f['counts']['warn'] . ' Hinweis' . ($f['counts']['warn'] === 1 ? '' : 'e') : 'bereit'); ?>
                                </span>
                                <div class="flex-fill">
                                    <a href="flows.php?shop=<?php echo urlencode($shop); ?>#<?php echo $e($f['key']); ?>"><strong><?php echo $e($f['label']); ?></strong></a>
                                    <?php if ($f['problems'] !== []): ?>
                                        <ul class="small text-secondary mb-0"><?php foreach ($f['problems'] as $p): ?><li><?php echo $e($p); ?></li><?php endforeach; ?></ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($planCounts[ShopMigrator::STATUS_UNSUPPORTED] > 0): ?>
                    <div class="alert alert-danger mt-3 mb-0">Der Shop nutzt in der alten Welt Funktionen, die es in Connect noch nicht gibt (rot in Schritt 2). Erst klären, dann umstellen.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Schritt 4 -->
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">4 · Umstellen</h3></div>
            <div class="card-body">
                <?php if ($isLive): ?>
                    <p>Der Shop läuft in Connect. Zurückstellen legt die Webhook-Abos der alten Welt wieder an und entfernt die von Connect, die alte Welt übernimmt sofort wieder.</p>
                    <form method="post"><input type="hidden" name="action" value="go_back"><button class="btn btn-outline-danger" type="submit">Zurück in die alte Welt</button></form>
                <?php else: ?>
                    <p class="mb-2">Ab dem Umstellen verarbeitet Connect Bestellungen, Lagerbestand und Jobs dieses Shops, die alte Welt nicht mehr. Die Webhook-Abos werden dabei automatisch umgehängt.</p>
                    <ul class="small text-secondary">
                        <li>Shopify-Connection aktiv: <strong><?php echo $shopifyActive ? 'ja' : 'nein'; ?></strong></li>
                        <li>Shopify-Berechtigungen vollständig: <strong><?php echo $scopes === null || $scopes['ok'] === null ? 'nicht prüfbar' : ($scopesMissing ? 'nein – siehe Schritt 1' : 'ja'); ?></strong></li>
                        <li>helloCash-Zugriff vollständig: <strong><?php echo $targetAccess === null ? 'nicht geprüft – Schritt 1 „Zugriff prüfen“' : ($targetAccessMissing ? 'nein – siehe Schritt 1' : 'ja'); ?></strong></li>
                        <li>Fehlende Schritte in den Abläufen: <strong><?php echo $blocking; ?></strong></li>
                        <li>Funktionen ohne Gegenstück in Connect: <strong><?php echo $planCounts[ShopMigrator::STATUS_UNSUPPORTED]; ?></strong></li>
                    </ul>
                    <form method="post" class="d-flex flex-wrap gap-3 align-items-center">
                        <input type="hidden" name="action" value="go_live">
                        <label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="confirm" value="1"> <span class="form-check-label">Bereitschaft geprüft, Shop jetzt umstellen</span></label>
                        <button class="btn btn-primary" type="submit" <?php echo $shopifyActive && !$scopesMissing && !$targetAccessMissing ? '' : 'disabled'; ?>>Auf Connect umstellen</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
