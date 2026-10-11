<?php

declare(strict_types=1);

/**
 * connect/public/admin/transition.php
 *
 * Betrieb -> Umstieg: je Shop (alte + neue Welt), ob die Vorbereitung fuer den Umzug erledigt ist:
 *   Shopify-Berechtigungen  Connect\Notify\MerchantDirectory (Connect-Pruefung + oview_stores.scopes
 *                           der alten Welt, die generate_token.php jetzt beim Bestaetigen speichert)
 *   helloCash-Token         Connect\Setup\TargetAccessCheck (neu generiert, v2-Zugriff vollstaendig)
 *   letzte Nachricht        connect_message_recipients
 * "Berechtigungen jetzt pruefen" fragt die Scopes ALLER Shops live bei Shopify ab: Connect-Shops
 * ueber ScopeCheck, Shops nur der alten Welt mit deren Token aus oview_stores (nur lesend,
 * Ergebnis in connect_legacy_scope_checks, Migration 0085).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Notify\MerchantDirectory;
use Connect\Security\Crypto;
use Connect\Shopify\ScopeCheck;

$config = Config::load();
$pdo = Database::connect($config)->pdo();
$auth = new AdminAuth($pdo);
$auth->requireLogin();
$adminEmail = (string) $auth->currentEmail();
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);

$flashMessage = null;
$flashType = 'info';

$directory = new MerchantDirectory($pdo, $config);
$merchants = $directory->all();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_scopes') {
    // In Etappen (ca. 100 Shops): hoechstens ~45 s je Klick, damit kein Gateway-Timeout (oft 60 s)
    // zuschlaegt. In den letzten 15 Min. gepruefte Shops werden uebersprungen -- jeder weitere
    // Klick macht dort weiter, wo der letzte aufgehoert hat.
    @set_time_limit(90);
    $scopeCheck = new ScopeCheck($pdo, new Crypto($config->require('CONNECT_ENCRYPTION_KEY')), $config);
    $http = new \Connect\Http\HttpClient();
    $checked = 0;
    $recent = 0;
    $remaining = 0;
    $failed = [];
    $started = time();
    foreach ($merchants as $m) {
        if ($m['world'] === 'live') {
            continue;
        }
        $checkedAt = $m['scopes_checked_at'] !== null ? strtotime((string) $m['scopes_checked_at']) : false;
        if ($checkedAt !== false && $checkedAt > time() - 900) {
            $recent++;
            continue;
        }
        if (time() - $started > 45) {
            $remaining++;
            continue;
        }
        if ($m['world'] === 'legacy') {
            $error = $directory->checkLegacyScopes($m['shop'], $http);
        } else {
            $result = $scopeCheck->check($m['shop']);
            $error = $result['ok'] === null ? (string) $result['error'] : null;
        }
        if ($error !== null) {
            $failed[] = $m['shop'] . ': ' . $error;
        } else {
            $checked++;
        }
    }
    $flashMessage = "{$checked} Shop(s) live bei Shopify geprüft"
        . ($recent > 0 ? ", {$recent} waren schon aktuell (letzte 15 Min.)" : '') . '.'
        . ($remaining > 0 ? " Noch {$remaining} offen – bitte noch einmal klicken." : ' Alle Shops sind geprüft.')
        . ($failed !== [] ? ' Nicht prüfbar: ' . implode(' · ', $failed) : '');
    $flashType = $remaining > 0 || $failed !== [] ? 'warning' : 'success';
    $merchants = $directory->all();
}

$lastMessages = [];
try {
    foreach ($pdo->query(
        "SELECT r.shop, MAX(r.sent_at) AS last_sent, COUNT(*) AS sent FROM connect_message_recipients r WHERE r.status = 'sent' GROUP BY r.shop"
    )->fetchAll() as $row) {
        $lastMessages[(string) $row['shop']] = $row;
    }
} catch (\PDOException) {
    // Migration 0082 fehlt
}

$isReady = static fn (array $m): bool => $m['scopes_missing'] === [] && $m['token'] === 'ok';
$show = in_array($_GET['show'] ?? '', ['open', 'ready'], true) ? (string) $_GET['show'] : 'all';
$world = in_array($_GET['world'] ?? '', ['legacy', 'connect'], true) ? (string) $_GET['world'] : 'all';
$counts = ['all' => 0, 'ready' => 0, 'scopes' => 0, 'token' => 0];
$rows = [];
foreach ($merchants as $m) {
    if ($m['world'] === 'live') {
        continue; // schon umgestellt
    }
    $counts['all']++;
    $counts['ready'] += $isReady($m) ? 1 : 0;
    $counts['scopes'] += $m['scopes_missing'] === [] ? 1 : 0;
    $counts['token'] += $m['token'] === 'ok' ? 1 : 0;
    if (($show === 'ready' && !$isReady($m)) || ($show === 'open' && $isReady($m))) {
        continue;
    }
    if (($world === 'legacy' && $m['world'] !== 'legacy') || ($world === 'connect' && $m['world'] === 'legacy')) {
        continue;
    }
    $rows[] = $m;
}
$query = static fn (array $over): string => '?' . http_build_query(array_merge(['show' => $show, 'world' => $world], $over));

$pageTitle = 'Umstieg';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$topRightHtml = '<span class="text-secondary me-3">' . $e($adminEmail) . '</span><a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
require dirname(__DIR__) . '/app/_header.php';
?>

<?php if ($directory->legacyError() !== null): ?>
    <div class="alert alert-warning"><?php echo $e($directory->legacyError()); ?></div>
<?php endif; ?>

<div class="row row-cards mb-3">
    <?php foreach ([
        ['Noch nicht umgestellt', $counts['all'], null],
        ['Shopify-Berechtigungen erledigt', $counts['scopes'], $counts['all']],
        ['helloCash-Token erledigt', $counts['token'], $counts['all']],
        ['Bereit zum Umzug', $counts['ready'], $counts['all']],
    ] as [$label, $value, $of]): ?>
        <div class="col-sm-6 col-lg-3">
            <div class="card card-sm"><div class="card-body">
                <div class="text-secondary small"><?php echo $e($label); ?></div>
                <div class="h2 mb-0"><?php echo (int) $value; ?><?php echo $of !== null ? '<span class="text-secondary fs-4"> / ' . (int) $of . '</span>' : ''; ?></div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header flex-wrap gap-2">
        <div class="btn-group" role="group" aria-label="Status">
            <?php foreach (['all' => 'Alle', 'open' => 'Offen', 'ready' => 'Bereit'] as $key => $label): ?>
                <a href="<?php echo $e($query(['show' => $key])); ?>" class="btn btn-sm<?php echo $show === $key ? ' btn-primary' : ''; ?>"><?php echo $e($label); ?></a>
            <?php endforeach; ?>
        </div>
        <div class="btn-group" role="group" aria-label="Welt">
            <?php foreach (['all' => 'Beide Welten', 'legacy' => 'Nur alte Welt', 'connect' => 'In Connect angelegt'] as $key => $label): ?>
                <a href="<?php echo $e($query(['world' => $key])); ?>" class="btn btn-sm<?php echo $world === $key ? ' btn-primary' : ''; ?>"><?php echo $e($label); ?></a>
            <?php endforeach; ?>
        </div>
        <div class="card-actions d-flex gap-2">
            <form method="post" class="m-0"><input type="hidden" name="action" value="check_scopes"><button type="submit" class="btn btn-sm" title="Scopes aller noch nicht umgestellten Shops (alte und neue Welt) live bei Shopify abfragen">Berechtigungen jetzt prüfen</button></form>
            <a href="cleanup.php" class="btn btn-sm">Aufräumen (nicht erreichbare Shops)</a>
            <a href="messages.php?new=1&amp;template=prepare" class="btn btn-sm btn-primary">Nachricht an Offene</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th>Shop</th><th>Welt</th><th>Shopify-Berechtigungen</th><th>helloCash-Token</th><th>Letzte Nachricht</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($rows as $m): ?>
                    <?php $last = $lastMessages[$m['shop']] ?? null; ?>
                    <tr>
                        <td>
                            <?php echo $e($m['shop']); ?>
                            <div class="small text-secondary"><?php echo $e($m['company'] ?? $m['name'] ?? ''); ?><?php echo $m['email'] !== null ? ' · ' . $e($m['email']) : ' · <span class="text-danger">keine Mail-Adresse</span>'; ?></div>
                        </td>
                        <td class="small"><?php echo $m['world'] === 'legacy' ? 'alte Welt' : 'in Connect, nicht umgestellt'; ?></td>
                        <td class="small">
                            <?php if ($m['scopes_error'] !== null && $m['scopes_missing'] === null): ?>
                                <span class="badge bg-yellow-lt">nicht prüfbar</span>
                                <div class="text-secondary"><?php echo $e($m['scopes_error']); ?></div>
                            <?php elseif ($m['scopes_missing'] === null): ?>
                                <span class="badge bg-secondary-lt">unbekannt</span>
                            <?php elseif ($m['scopes_missing'] === []): ?>
                                <span class="badge bg-green-lt">✓ erledigt</span>
                            <?php else: ?>
                                <span class="badge bg-red-lt">offen</span>
                                <div class="text-secondary">fehlt: <?php echo $e(implode(', ', $m['scopes_missing'])); ?></div>
                            <?php endif; ?>
                            <div class="text-secondary"><?php echo $m['scopes_checked_at'] !== null ? 'geprüft ' . $e(substr((string) $m['scopes_checked_at'], 0, 16)) : ($m['scopes_missing'] !== null ? 'Stand alte Welt' : ''); ?></div>
                        </td>
                        <td class="small">
                            <?php echo [
                                'ok' => '<span class="badge bg-green-lt">✓ erledigt</span>',
                                'missing' => '<span class="badge bg-red-lt">unvollständig</span>',
                                'unknown' => '<span class="badge bg-secondary-lt">offen</span>',
                            ][$m['token']] ?? ''; ?>
                        </td>
                        <td class="small text-secondary"><?php echo $last !== null ? $e(substr((string) $last['last_sent'], 0, 10)) . ((int) $last['sent'] > 1 ? ' (' . (int) $last['sent'] . ' gesamt)' : '') : '–'; ?></td>
                        <td class="text-end">
                            <?php if ($m['world'] !== 'legacy'): ?>
                                <a href="migrate.php?shop=<?php echo $e(rawurlencode($m['shop'])); ?>" class="btn btn-sm<?php echo $isReady($m) ? ' btn-primary' : ''; ?>">Umzug</a>
                            <?php else: ?>
                                <a href="shops.php" class="btn btn-sm" title="Zuerst unter Shops „Token übernehmen“">In Connect anlegen</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="text-secondary">Keine Shops in dieser Ansicht.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-secondary">
        Bereits umgestellte Shops sind ausgeblendet. Shopify-Berechtigungen: „geprüft“ = live bei Shopify abgefragt („Berechtigungen jetzt prüfen“, auch für Shops nur der alten Welt), „Stand alte Welt“ = vom letzten Bestätigen bzw. Öffnen der App.
        helloCash-Token: „erledigt“ = neu generiert und Zugriff für Connect vollständig.
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
