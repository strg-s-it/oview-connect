<?php

declare(strict_types=1);

/**
 * connect/public/admin/order_data.php
 *
 * Nur lesen: zeigt die Shopify-Bestellung, so wie Connect sie im Zwischenspeicher
 * (connect_object_records, Object "order") hat -- Grundlage fuer jede Rechnung. Zweck: Fehlersuche,
 * wenn man keinen Zugriff auf den Shopify-Admin des Shops hat (z. B. falsche Versandsteuer,
 * Mischsatz). Kein Aufruf bei Shopify, keine Aenderung, kein Anstoss eines Jobs.
 *
 * Zeigt (1) Versand und Steuern, (2) die Rechnungspositionen, die Connect daraus bauen wuerde
 * (OrderPreparer::prepare, reine Berechnung), (3) die Rohdaten. Personenbezogene Felder (Name,
 * Adresse, E-Mail, Telefon) sind standardmaessig ausgeblendet, "mit Personendaten" blendet sie ein.
 * Bestellungen aelter als das Cache-Fenster (90 Tage) sind nicht (mehr) vorhanden.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Sync\Document\OrderPreparer;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$shop = trim((string) ($_GET['shop'] ?? ''));
$orderInput = trim((string) ($_GET['order'] ?? ''));
$showPersonal = ($_GET['personal'] ?? '') === '1';
// Nur Zeichen, die in Bestellnummern vorkommen (auch fuer LIKE unkritisch)
$orderNumber = ltrim($orderInput, '#');
$orderValid = $orderNumber !== '' && preg_match('/^[A-Za-z0-9._-]{1,40}$/', $orderNumber) === 1;

$shops = $pdo->query('SELECT shop FROM connect_shops ORDER BY shop')->fetchAll(PDO::FETCH_COLUMN);

/** Felder mit Personenbezug (Schluessel, rekursiv) */
$isPersonalKey = static fn (string $key): bool => !in_array($key, ['line_name', 'shipping_name'], true)
    && preg_match('/(^customer_|email|phone|first_name|last_name|address|zip|city|country|company|province|_name$)/i', $key) === 1;

$mask = static function (mixed $value) use (&$mask, $isPersonalKey): mixed {
    if (!is_array($value)) {
        return $value;
    }
    $out = [];
    foreach ($value as $key => $item) {
        $out[$key] = is_string($key) && $isPersonalKey($key) && !is_array($item) && $item !== null && $item !== ''
            ? '[ausgeblendet]'
            : $mask($item);
    }

    return $out;
};

$record = null;
$fetchedAt = null;
$status = null;
$preview = null;
$message = null;

if ($shop !== '' && $orderValid) {
    // Shopify-Verbindung des Shops
    $conn = $pdo->prepare(
        "SELECT c.id FROM connect_connections c
         JOIN connect_connector_types t ON t.id = c.connector_type_id
         WHERE c.shop = :shop AND t.type_key = 'shopify' ORDER BY c.id LIMIT 1"
    );
    $conn->execute(['shop' => $shop]);
    $connectionId = (int) $conn->fetchColumn();

    if ($connectionId === 0) {
        $message = 'Dieser Shop hat keine Shopify-Verbindung.';
    } else {
        // Kein JSON_EXTRACT auf MariaDB 5.5: Textsuche auf dem gespeicherten JSON (Zahl oder Text, mit/ohne "#")
        $stmt = $pdo->prepare(
            "SELECT data, status, fetched_at FROM connect_object_records
             WHERE connection_id = :conn AND object_key = 'order'
               AND (data LIKE :a OR data LIKE :b OR data LIKE :c OR data LIKE :d)
             ORDER BY fetched_at DESC LIMIT 1"
        );
        $stmt->execute([
            'conn' => $connectionId,
            'a' => '%"order_number":"' . $orderNumber . '"%',
            'b' => '%"order_number":"#' . $orderNumber . '"%',
            'c' => '%"order_number":' . $orderNumber . ',%',
            'd' => '%"order_number":' . $orderNumber . '}%',
        ]);
        $found = $stmt->fetch();
        if ($found === false) {
            $message = 'Bestellung nicht im Zwischenspeicher gefunden (älter als 90 Tage, noch nicht geladen oder andere Nummer).';
        } else {
            $decoded = json_decode((string) $found['data'], true);
            if (is_array($decoded)) {
                $record = $decoded;
                $fetchedAt = (string) $found['fetched_at'];
                $status = (string) $found['status'];
                try {
                    $priceStmt = $pdo->prepare('SELECT shopify_prices_include_tax FROM connect_shops WHERE shop = :shop');
                    $priceStmt->execute(['shop' => $shop]);
                    $flag = $priceStmt->fetchColumn();
                    $includeTax = $flag === false || $flag === null || (int) $flag === 1;
                    // Reine Berechnung, ohne Artikelsuche im Kassensystem und ohne Schreiben
                    $preview = (new OrderPreparer())->prepare($record, $includeTax);
                    $preview['include_tax'] = $includeTax;
                } catch (\Throwable $ex) {
                    $message = 'Vorschau der Rechnungspositionen nicht möglich: ' . $ex->getMessage();
                }
            } else {
                $message = 'Datensatz ist kein gültiges JSON.';
            }
        }
    }
}

$num = static fn (mixed $v): string => is_numeric($v) ? number_format((float) $v, 2, ',', '.') : '–';

$pageTitle = 'Bestelldaten';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$breadcrumbs = [[null, 'Bestelldaten']];
$topRightHtml = '<span class="text-secondary me-3">' . $e((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Bestelldaten aus dem Zwischenspeicher</h3></div>
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Shop</label>
                        <select name="shop" class="form-select">
                            <option value="">– wählen –</option>
                            <?php foreach ($shops as $shopOption): ?>
                                <option value="<?php echo $e((string) $shopOption); ?>" <?php echo $shop === $shopOption ? 'selected' : ''; ?>><?php echo $e((string) $shopOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Bestellnummer</label>
                        <input type="text" name="order" class="form-control" value="<?php echo $e($orderInput); ?>" placeholder="z. B. 11134" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <label class="form-check mb-2">
                            <input type="checkbox" name="personal" value="1" class="form-check-input" <?php echo $showPersonal ? 'checked' : ''; ?>>
                            <span class="form-check-label">mit Personendaten</span>
                        </label>
                    </div>
                    <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Anzeigen</button></div>
                </form>
                <?php if ($shop !== '' && $orderInput !== '' && !$orderValid): ?>
                    <div class="alert alert-warning mt-3 mb-0">Ungültige Bestellnummer.</div>
                <?php elseif ($message !== null): ?>
                    <div class="alert alert-warning mt-3 mb-0"><?php echo $e($message); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($record !== null): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Versand und Steuern (so liefert es Shopify)</h3></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-4">Zwischenspeicher</dt>
                        <dd class="col-8">Stand <?php echo $e($fetchedAt); ?> (<?php echo $e($status); ?>)</dd>
                        <dt class="col-4">Preise inkl. Steuer (Shop-Einstellung)</dt>
                        <dd class="col-8"><?php echo ($preview['include_tax'] ?? true) ? 'ja (brutto)' : 'nein (netto)'; ?></dd>
                        <dt class="col-4">Versand original / bezahlt</dt>
                        <dd class="col-8"><?php echo $num($record['shipping_original_price'] ?? null); ?> / <?php echo $num($record['shipping_discounted_price'] ?? $record['shipping_price'] ?? null); ?></dd>
                        <dt class="col-4">Versand-Steuerzeilen</dt>
                        <dd class="col-8">
                            <?php $taxLines = (array) ($record['shipping_tax_lines'] ?? []); ?>
                            <?php if ($taxLines === []): ?>
                                <span class="text-secondary">keine (Versand ohne Steuerangabe)</span>
                            <?php else: ?>
                                <?php foreach ($taxLines as $taxLine): ?>
                                    <?php if (is_array($taxLine)): ?>
                                        Satz <strong><?php echo $e(rtrim(rtrim(number_format(((float) ($taxLine['shipping_tax_line_rate'] ?? 0)) * 100, 4, ',', ''), '0'), ',')); ?> %</strong>,
                                        Betrag <?php echo $num($taxLine['shipping_tax_line_amount'] ?? null); ?><br>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </dd>
                        <dt class="col-4">Steuer gesamt / Gesamtbetrag</dt>
                        <dd class="col-8"><?php echo $num($record['total_tax'] ?? null); ?> / <?php echo $num($record['gross_total'] ?? null); ?></dd>
                    </dl>
                </div>
            </div>
        </div>

        <?php if ($preview !== null): ?>
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Rechnungspositionen, die Connect daraus bauen würde</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead><tr><th>Position</th><th class="text-end">Menge</th><th class="text-end">Betrag brutto</th><th class="text-end">Steuersatz</th><th class="text-end">Steuer</th></tr></thead>
                            <tbody>
                                <?php foreach ($preview['lines'] as $line): ?>
                                    <tr>
                                        <td><?php echo $e((string) ($line['name'] ?? '')); ?><?php echo !empty($line['is_shipping']) ? ' <span class="badge bg-blue-lt">Versand</span>' : ''; ?></td>
                                        <td class="text-end"><?php echo $e((string) ($line['quantity'] ?? '')); ?></td>
                                        <td class="text-end"><?php echo $num($line['line_total'] ?? null); ?></td>
                                        <td class="text-end"><?php echo $e(rtrim(rtrim(number_format((float) ($line['tax_rate_percent'] ?? 0), 4, ',', ''), '0'), ',')); ?> %</td>
                                        <td class="text-end"><?php echo $num($line['tax_amount'] ?? null); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-body text-secondary">
                        Steuersumme berechnet: <?php echo $num($preview["computed_total_tax"]); ?>
                        <?php foreach ($preview["notes"] as $note): ?>
                            <div><?php echo $e((string) $note); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-12">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Rohdaten<?php echo $showPersonal ? '' : ' (Personendaten ausgeblendet)'; ?></h3></div>
                <div class="card-body">
                    <pre class="mb-0" style="white-space: pre-wrap; word-break: break-word;"><?php
                        $shown = $showPersonal ? $record : $mask($record);
                        echo $e((string) json_encode($shown, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    ?></pre>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
