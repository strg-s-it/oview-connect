<?php

declare(strict_types=1);

/**
 * connect/public/admin/export.php
 *
 * Haendler -> Export: Kontaktliste aller Shops (alte + neue Welt) als CSV, fuer Akquise und fuer den
 * Umstieg auf Connect. Quelle fuer Name, echte Shop-URL, Telefon und Mail ist Shopify selbst
 * (Connect\Notify\ShopContacts, Migration 0108): "Kontaktdaten abrufen" fragt die Stammdaten live ab
 * (nur lesend, in Etappen), der Export nutzt den gemerkten Stand. Filter wie bei den Nachrichten
 * (Connect\Notify\MerchantDirectory::filter). Shops mit Opt-out stehen standardmaessig nicht drin.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Notify\MerchantDirectory;
use Connect\Notify\ShopContacts;
use Connect\Security\Crypto;

$config = Config::load();
$pdo = Database::connect($config)->pdo();
$auth = new AdminAuth($pdo);
$auth->requireLogin();
$adminEmail = (string) $auth->currentEmail();
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);

$flashMessage = null;
$flashType = 'info';

$source = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$filter = [
    'world' => array_values(array_intersect((array) ($source['world'] ?? []), array_keys(MerchantDirectory::WORLD_LABELS))),
    'q' => trim((string) ($source['q'] ?? '')),
];
$withOptout = !empty($source['optout']);
$onlyMissing = !empty($source['missing']);

$directory = new MerchantDirectory($pdo, $config);
$merchants = MerchantDirectory::filter($directory->all(), $filter);
if (!$withOptout) {
    $merchants = array_values(array_filter($merchants, static fn (array $m): bool => !$m['optout']));
}
$contacts = new ShopContacts($pdo, new Crypto($config->require('CONNECT_ENCRYPTION_KEY')), $config);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fetch') {
    // In Etappen wie "Berechtigungen jetzt pruefen" (Umstieg): hoechstens ~45 s je Klick, in den
    // letzten 24 Std. abgefragte Shops werden uebersprungen -- der naechste Klick macht weiter.
    @set_time_limit(90);
    $known = $contacts->all();
    $done = 0;
    $recent = 0;
    $remaining = 0;
    $failed = [];
    $started = time();
    foreach ($merchants as $m) {
        $fetchedAt = isset($known[$m['shop']]['fetched_at']) ? strtotime((string) $known[$m['shop']]['fetched_at']) : false;
        if ($fetchedAt !== false && $fetchedAt > time() - 86400 && ($known[$m['shop']]['error'] ?? null) === null) {
            $recent++;
            continue;
        }
        if (time() - $started > 45) {
            $remaining++;
            continue;
        }
        $error = $contacts->fetch($m['shop'], $m['world'] === 'legacy', $directory->legacyToken($m['shop']));
        if ($error !== null) {
            $failed[] = $m['shop'] . ': ' . $error;
        } else {
            $done++;
        }
    }
    $flashMessage = "{$done} Shop(s) bei Shopify abgefragt"
        . ($recent > 0 ? ", {$recent} waren schon aktuell (letzte 24 Std.)" : '') . '.'
        . ($remaining > 0 ? " Noch {$remaining} offen – bitte noch einmal klicken." : ' Alle Shops der Auswahl sind abgefragt.')
        . ($failed !== [] ? ' Nicht abrufbar: ' . implode(' · ', array_slice($failed, 0, 15)) . (count($failed) > 15 ? ' …' : '') : '');
    $flashType = $remaining > 0 || $failed !== [] ? 'warning' : 'success';
}

$known = $contacts->all();
$rows = [];
foreach ($merchants as $m) {
    $c = $known[$m['shop']] ?? null;
    $row = [
        'shop' => $m['shop'],
        'world' => $m['world'],
        'name' => (string) ($c['shop_name'] ?? $m['name'] ?? ''),
        'company' => (string) ($c['company'] ?? $m['company'] ?? ''),
        'url' => (string) ($c['url'] ?? ''),
        'myshopify' => $m['shop'] . '.myshopify.com',
        'email' => (string) ($c['email'] ?? $m['email'] ?? ''),
        'contact_email' => (string) ($c['contact_email'] ?? ''),
        'phone' => (string) ($c['phone'] ?? ''),
        'address' => (string) ($c['address'] ?? ''),
        'country' => (string) ($c['country'] ?? ''),
        'system' => (string) ($m['system'] ?? ''),
        'plan' => (string) $m['plan_label'],
        'installed' => (string) ($m['installed'] ?? ''),
        'fetched_at' => (string) ($c['fetched_at'] ?? ''),
        'error' => (string) ($c['error'] ?? ''),
    ];
    if ($onlyMissing && $row['phone'] !== '' && $row['url'] !== '') {
        continue;
    }
    $rows[] = $row;
}

if (($source['download'] ?? '') === '1') {
    $worldLabels = ['live' => 'Connect', 'pending' => 'Connect (nicht umgestellt)', 'legacy' => 'alte Welt'];
    $csvEscape = static function (string $v): string {
        // CSV-Injection: Excel wertet Zellen mit =, +, -, @ am Anfang als Formel aus -- Telefonnummern
        // mit fuehrendem + bleiben lesbar, weil nur Zellen mit Formelzeichen UND Buchstaben entschaerft werden
        $formula = $v !== '' && (strpbrk($v[0], '=@') !== false || (strpbrk($v[0], '+-') !== false && preg_match('/[a-z]/i', $v) === 1));
        if ($formula) {
            $v = "'" . $v;
        }

        return '"' . str_replace('"', '""', $v) . '"';
    };
    $header = ['Shop', 'Welt', 'Name', 'Firma', 'URL', 'Shopify-Adresse', 'E-Mail', 'Kontakt-E-Mail', 'Telefon', 'Adresse', 'Land', 'Kassensystem', 'Abo', 'Installiert', 'Abgefragt am', 'Fehler'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="oview-shops-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM, damit Excel die Umlaute richtig liest
    echo implode(';', array_map($csvEscape, $header)) . "\r\n";
    foreach ($rows as $r) {
        $line = [$r['shop'], $worldLabels[$r['world']] ?? $r['world'], $r['name'], $r['company'], $r['url'], $r['myshopify'], $r['email'],
            $r['contact_email'], $r['phone'], $r['address'], $r['country'], $r['system'], $r['plan'], $r['installed'], $r['fetched_at'], $r['error']];
        echo implode(';', array_map($csvEscape, $line)) . "\r\n";
    }
    exit;
}

$withPhone = count(array_filter($rows, static fn (array $r): bool => $r['phone'] !== ''));
$withUrl = count(array_filter($rows, static fn (array $r): bool => $r['url'] !== ''));
$queryString = http_build_query(array_filter(['world' => $filter['world'], 'q' => $filter['q'], 'optout' => $withOptout ? 1 : null, 'missing' => $onlyMissing ? 1 : null]));

$pageTitle = 'Export';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$topRightHtml = '<span class="text-secondary me-3">' . $e($adminEmail) . '</span><a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
require dirname(__DIR__) . '/app/_header.php';
?>

<?php if ($directory->legacyError() !== null): ?>
    <div class="alert alert-warning"><?php echo $e($directory->legacyError()); ?></div>
<?php endif; ?>
<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?php echo $e($flashType); ?>"><?php echo $e($flashMessage); ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end" autocomplete="off">
            <div class="col-md-6">
                <label class="form-label">Welt</label>
                <div>
                    <?php foreach (MerchantDirectory::WORLD_LABELS as $key => $label): ?>
                        <label class="form-check form-check-inline"><input type="checkbox" class="form-check-input" name="world[]" value="<?php echo $e($key); ?>"<?php echo in_array($key, $filter['world'], true) ? ' checked' : ''; ?>> <span class="form-check-label"><?php echo $e($label); ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="form-hint">Nichts angehakt = alle Shops.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Suche</label>
                <input type="text" name="q" class="form-control" value="<?php echo $e($filter['q']); ?>" placeholder="Shop, Name, Mail">
            </div>
            <div class="col-md-3">
                <label class="form-check"><input type="checkbox" class="form-check-input" name="missing" value="1"<?php echo $onlyMissing ? ' checked' : ''; ?>> <span class="form-check-label">nur mit fehlender URL/Telefon</span></label>
                <label class="form-check"><input type="checkbox" class="form-check-input" name="optout" value="1"<?php echo $withOptout ? ' checked' : ''; ?>> <span class="form-check-label">Opt-out-Shops einschließen</span></label>
            </div>
            <div class="col-12 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary">Filtern</button>
                <button type="submit" name="download" value="1" class="btn">CSV herunterladen</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header flex-wrap gap-2">
        <h3 class="card-title"><?php echo count($rows); ?> Shops · <?php echo $withUrl; ?> mit URL · <?php echo $withPhone; ?> mit Telefon</h3>
        <div class="card-actions">
            <form method="post" class="m-0">
                <input type="hidden" name="action" value="fetch">
                <?php foreach ($filter['world'] as $w): ?><input type="hidden" name="world[]" value="<?php echo $e($w); ?>"><?php endforeach; ?>
                <input type="hidden" name="q" value="<?php echo $e($filter['q']); ?>">
                <?php if ($withOptout): ?><input type="hidden" name="optout" value="1"><?php endif; ?>
                <?php if ($onlyMissing): ?><input type="hidden" name="missing" value="1"><?php endif; ?>
                <button type="submit" class="btn btn-sm btn-primary" title="Name, URL, Telefon und Mail der gefilterten Shops live bei Shopify abfragen (nur lesend)">Kontaktdaten abrufen</button>
            </form>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th>Shop</th><th>Welt</th><th>Name</th><th>URL</th><th>Telefon</th><th>E-Mail</th><th>Abgefragt</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo $e($r['shop']); ?></td>
                        <td class="small"><?php echo $e(MerchantDirectory::WORLD_LABELS[$r['world']] ?? $r['world']); ?></td>
                        <td><?php echo $e($r['name']); ?><?php echo $r['company'] !== '' && $r['company'] !== $r['name'] ? '<div class="small text-secondary">' . $e($r['company']) . '</div>' : ''; ?></td>
                        <td class="small"><?php echo $r['url'] !== '' ? $e($r['url']) : '<span class="text-secondary">–</span>'; ?></td>
                        <td class="small"><?php echo $r['phone'] !== '' ? $e($r['phone']) : '<span class="text-secondary">–</span>'; ?></td>
                        <td class="small"><?php echo $r['email'] !== '' ? $e($r['email']) : '<span class="text-secondary">–</span>'; ?></td>
                        <td class="small text-secondary">
                            <?php if ($r['error'] !== ''): ?><span class="text-danger"><?php echo $e($r['error']); ?></span>
                            <?php else: ?><?php echo $r['fetched_at'] !== '' ? $e(substr($r['fetched_at'], 0, 16)) : 'noch nicht'; ?><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-secondary">Keine Shops in dieser Auswahl.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-secondary">
        Quelle: Shopify-Stammdaten des Shops (Name, primäre Domain, Telefon der Rechnungsadresse, Shop-E-Mail). Solange ein Shop noch nicht abgefragt wurde, stehen nur Name und Mail aus dem bekannten Bestand da.
        Das Telefon fehlt, wenn der Händler in Shopify keines hinterlegt hat. Die CSV ist mit Semikolon getrennt und für Excel (UTF-8) gedacht.
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
