<?php

declare(strict_types=1);

/**
 * connect/public/admin/shop_fields.php
 *
 * Shop-Felder eines Shops (Migration 0074, Connect\Sync\ShopFields): zusaetzliche
 * Werte aus Shopify-Produkt-Metafields, die nur dieser Shop hat (z.B. Netto-
 * Einkaufspreis bei danysmusicshop). In Mapping-Vorlagen ueber die Umrechnung
 * "Shop-Feld" nutzbar.
 *
 * "Metafields anzeigen" fragt live EIN Produkt ab und listet alle Metafields --
 * so laesst sich der Namespace finden, ohne Zugriff auf den Shopify-Admin.
 */

use Connect\Connector\ConnectorFactory;
use Connect\Connector\Generic\GenericHttpConnector;
use Connect\Http\HttpClient;
use Connect\Shopify\TokenRefresher;
use Connect\Sync\ObjectCache;
use Connect\Sync\ShopFields;

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$pdo = $app->pdo;
$shop = $app->shop;
$isAdmin = true;

$flashMessage = null;
$flashType = 'info';
$discovery = null;

$tableMissing = false;
try {
    $pdo->query('SELECT 1 FROM connect_shop_fields LIMIT 1');
} catch (\Throwable) {
    $tableMissing = true;
}

$stmt = $pdo->prepare(
    "SELECT c.id FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE c.shop = :shop AND t.type_key = 'shopify' ORDER BY c.id LIMIT 1"
);
$stmt->execute(['shop' => $shop]);
$shopifyConnectionId = $stmt->fetchColumn();
$shopifyConnectionId = $shopifyConnectionId !== false ? (int) $shopifyConnectionId : null;

$buildConnector = function () use ($pdo, $app, $shopifyConnectionId): GenericHttpConnector {
    $tokenRefresher = new TokenRefresher(new HttpClient(), $app->config->optional('CONNECT_SHOPIFY_API_KEY'), $app->config->optional('CONNECT_SHOPIFY_API_SECRET'));
    $connector = (new ConnectorFactory($pdo, $app->crypto, new HttpClient(), $tokenRefresher))->forConnection((int) $shopifyConnectionId);
    if (!$connector instanceof GenericHttpConnector) {
        throw new \RuntimeException('Shopify-Connection unterstützt keine freie Abfrage.');
    }

    return $connector;
};

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';

if (!$tableMissing && $action === 'add_field') {
    $label = trim((string) ($_POST['label'] ?? ''));
    $namespace = trim((string) ($_POST['metafield_namespace'] ?? ''));
    $key = trim((string) ($_POST['metafield_key'] ?? ''));
    $valuePath = trim((string) ($_POST['value_path'] ?? ''));
    $fieldKey = strtolower(trim((string) ($_POST['field_key'] ?? '')));
    if ($fieldKey === '') {
        $fieldKey = strtolower((string) preg_replace('/[^a-z0-9_]+/i', '_', $key));
    }

    $usedSlots = $pdo->prepare("SELECT slot FROM connect_shop_fields WHERE shop = :shop AND object_key = 'product'");
    $usedSlots->execute(['shop' => $shop]);
    $freeSlots = array_values(array_diff(range(1, ShopFields::MAX_SLOTS), array_map('intval', $usedSlots->fetchAll(PDO::FETCH_COLUMN))));

    if ($label === '' || $namespace === '' || $key === '') {
        $flashMessage = 'Bitte Name, Namespace und Schlüssel angeben.';
        $flashType = 'danger';
    } elseif (!preg_match('/^[a-z0-9_]{1,64}$/', $fieldKey)) {
        $flashMessage = 'Schlüssel für Vorlagen nur mit a–z, 0–9 und _ (max. 64 Zeichen).';
        $flashType = 'danger';
    } elseif (preg_match('/\s/', $namespace . $key . $valuePath)) {
        $flashMessage = 'Namespace, Schlüssel und Teilwert dürfen keine Leerzeichen enthalten.';
        $flashType = 'danger';
    } elseif ($freeSlots === []) {
        $flashMessage = 'Alle ' . ShopFields::MAX_SLOTS . ' Plätze sind belegt.';
        $flashType = 'danger';
    } else {
        try {
            $pdo->prepare(
                "INSERT INTO connect_shop_fields (shop, object_key, field_key, label, metafield_namespace, metafield_key, value_path, slot)
                 VALUES (:shop, 'product', :field_key, :label, :namespace, :key, :value_path, :slot)"
            )->execute([
                'shop' => $shop,
                'field_key' => $fieldKey,
                'label' => mb_substr($label, 0, 190),
                'namespace' => mb_substr($namespace, 0, 190),
                'key' => mb_substr($key, 0, 190),
                'value_path' => $valuePath !== '' ? mb_substr($valuePath, 0, 190) : null,
                'slot' => $freeSlots[0],
            ]);
            $flashMessage = "Shop-Feld „{$label}“ angelegt (Schlüssel {$fieldKey}, Platz {$freeSlots[0]}). Werte kommen mit dem nächsten Produktabruf (Jobs → Shopify-Produkt → jetzt ausführen).";
            $flashType = 'success';
        } catch (\PDOException $e) {
            $flashMessage = str_contains($e->getMessage(), 'Duplicate') ? "Den Schlüssel „{$fieldKey}“ gibt es bei diesem Shop schon." : 'Speichern fehlgeschlagen: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
} elseif (!$tableMissing && $action === 'delete_field') {
    $pdo->prepare('DELETE FROM connect_shop_fields WHERE id = :id AND shop = :shop')->execute(['id' => (int) ($_POST['id'] ?? 0), 'shop' => $shop]);
    $flashMessage = 'Shop-Feld gelöscht. Der Wert verschwindet aus dem Zwischenspeicher mit dem nächsten Produktabruf.';
    $flashType = 'success';
} elseif ($action === 'discover') {
    if ($shopifyConnectionId === null) {
        $flashMessage = 'Dieser Shop hat keine Shopify-Connection.';
        $flashType = 'danger';
    } else {
        $productInput = trim((string) ($_POST['product'] ?? ''));
        $productGid = null;
        if ($productInput !== '') {
            $productGid = ctype_digit($productInput) ? 'gid://shopify/Product/' . $productInput : $productInput;
        }
        try {
            $discovery = ShopFields::discoverMetafields($buildConnector(), $productGid);
            if ($discovery['product'] === null) {
                $flashMessage = 'Produkt nicht gefunden.';
                $flashType = 'warning';
            }
        } catch (\Throwable $e) {
            $flashMessage = 'Abfrage fehlgeschlagen: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

$fields = [];
if (!$tableMissing) {
    $stmt = $pdo->prepare("SELECT * FROM connect_shop_fields WHERE shop = :shop AND object_key = 'product' ORDER BY slot");
    $stmt->execute(['shop' => $shop]);
    $fields = $stmt->fetchAll();
}

// Befuellung im Zwischenspeicher: wie viele Produkte haben einen Wert, plus ein Beispiel
$fill = [];
if ($fields !== [] && $shopifyConnectionId !== null) {
    $records = array_filter((new ObjectCache($pdo))->getCached($shopifyConnectionId, 'product'), static fn (array $r): bool => ($r['_status'] ?? 'active') !== 'orphaned');
    foreach ($fields as $field) {
        $cacheKey = ShopFields::CACHE_PREFIX . $field['field_key'];
        $count = 0;
        $example = null;
        foreach ($records as $record) {
            if (isset($record[$cacheKey]) && $record[$cacheKey] !== '') {
                $count++;
                $example ??= is_scalar($record[$cacheKey]) ? (string) $record[$cacheKey] : json_encode($record[$cacheKey]);
            }
        }
        $fill[(int) $field['id']] = ['count' => $count, 'total' => count($records), 'example' => $example];
    }
}

$pageTitle = 'Shop-Felder';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = require dirname(__DIR__) . '/app/_shop_nav.php';
$adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <?php if ($tableMissing): ?>
        <div class="col-12"><div class="alert alert-warning">Migration 0074 ist noch nicht eingespielt.</div></div>
    <?php endif; ?>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Shop-Felder (Shopify-Produkt-Metafields)</h3></div>
            <div class="card-body">
                <p class="text-secondary">
                    Werte, die nur dieser Shop hat. Connect liest sie beim normalen Produktabruf mit und legt sie im
                    Zwischenspeicher ab. In einer Mapping-Vorlage holt die Umrechnung „Shop-Feld“ mit dem
                    <strong>Schlüssel</strong> den Wert – Shops ohne dieses Feld liefern nichts. Max. <?php echo ShopFields::MAX_SLOTS; ?> Felder je Shop.
                </p>
                <?php if ($fields === []): ?>
                    <div class="text-secondary">Noch keine Shop-Felder.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-vcenter">
                            <thead><tr><th>Name</th><th>Schlüssel (Vorlage)</th><th>Metafield</th><th>Teilwert</th><th>Im Zwischenspeicher</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($fields as $field): ?>
                                    <?php $f = $fill[(int) $field['id']] ?? null; ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($field['label']); ?><br><small class="text-secondary">Platz <?php echo (int) $field['slot']; ?></small></td>
                                        <td class="font-monospace"><?php echo htmlspecialchars($field['field_key']); ?></td>
                                        <td class="font-monospace"><?php echo htmlspecialchars($field['metafield_namespace'] . '.' . $field['metafield_key']); ?></td>
                                        <td class="font-monospace"><?php echo htmlspecialchars((string) ($field['value_path'] ?? '–')); ?></td>
                                        <td>
                                            <?php if ($f === null): ?>–<?php else: ?>
                                                <?php echo $f['count']; ?> von <?php echo $f['total']; ?> Varianten
                                                <?php if ($f['example'] !== null): ?><br><small class="text-secondary">z. B. <?php echo htmlspecialchars(mb_strimwidth($f['example'], 0, 60, '…')); ?></small><?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post" onsubmit="return confirm('Shop-Feld wirklich löschen? Vorlagen, die es nutzen, bekommen für diesen Shop dann keinen Wert mehr.');">
                                                <input type="hidden" name="action" value="delete_field">
                                                <input type="hidden" name="id" value="<?php echo (int) $field['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
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
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Metafields eines Produkts anzeigen</h3></div>
            <div class="card-body">
                <p class="text-secondary">Fragt live bei Shopify ein Produkt ab (ohne Angabe: das zuletzt geänderte) und listet seine Metafields. „Übernehmen“ füllt das Formular rechts.</p>
                <form method="post" class="row g-2 mb-3">
                    <input type="hidden" name="action" value="discover">
                    <div class="col"><input type="text" name="product" class="form-control" placeholder="Produkt-ID oder GID (optional)" value="<?php echo htmlspecialchars((string) ($_POST['product'] ?? '')); ?>"></div>
                    <div class="col-auto"><button type="submit" class="btn btn-outline-primary" <?php echo $shopifyConnectionId === null ? 'disabled' : ''; ?>>Metafields anzeigen</button></div>
                </form>
                <?php if ($discovery !== null && $discovery['product'] !== null): ?>
                    <div class="mb-2"><strong>Produkt:</strong> <?php echo htmlspecialchars($discovery['product']); ?></div>
                    <?php if ($discovery['metafields'] === []): ?>
                        <div class="text-secondary">Dieses Produkt hat keine Metafields – ggf. ein anderes Produkt angeben.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-vcenter">
                                <thead><tr><th>Namespace.Schlüssel</th><th>Typ</th><th>Wert</th><th></th></tr></thead>
                                <tbody>
                                    <?php foreach ($discovery['metafields'] as $mf): ?>
                                        <tr>
                                            <td class="font-monospace small"><?php echo htmlspecialchars($mf['namespace'] . '.' . $mf['key']); ?></td>
                                            <td class="small"><?php echo htmlspecialchars($mf['type']); ?></td>
                                            <td class="small"><?php echo htmlspecialchars(mb_strimwidth($mf['value'], 0, 60, '…')); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    data-ns="<?php echo htmlspecialchars($mf['namespace']); ?>"
                                                    data-key="<?php echo htmlspecialchars($mf['key']); ?>"
                                                    data-path="<?php echo $mf['type'] === 'money' ? 'amount' : (str_ends_with($mf['type'], '_dimension') || $mf['type'] === 'weight' || $mf['type'] === 'volume' ? 'value' : ''); ?>"
                                                    onclick="shopFieldTake(this)">Übernehmen</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Shop-Feld anlegen</h3></div>
            <div class="card-body">
                <form method="post" class="row g-2" autocomplete="off">
                    <input type="hidden" name="action" value="add_field">
                    <div class="col-12">
                        <label class="form-label">Name</label>
                        <input type="text" name="label" class="form-control" placeholder="z. B. Netto-Einkaufspreis" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Metafield-Namespace</label>
                        <input type="text" id="sf-namespace" name="metafield_namespace" class="form-control font-monospace" placeholder="z. B. custom" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Metafield-Schlüssel</label>
                        <input type="text" id="sf-key" name="metafield_key" class="form-control font-monospace" placeholder="z. B. article_net_purchase_price" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Teilwert (optional)</label>
                        <input type="text" id="sf-path" name="value_path" class="form-control font-monospace" placeholder="z. B. amount">
                        <small class="text-secondary">Bei Geldbeträgen „amount“ (sonst kommt Betrag + Währung als JSON).</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Schlüssel für Vorlagen (optional)</label>
                        <input type="text" name="field_key" class="form-control font-monospace" placeholder="leer = Metafield-Schlüssel">
                        <small class="text-secondary">Gleicher Schlüssel bei mehreren Shops = eine Vorlage für alle.</small>
                    </div>
                    <div class="col-12 mt-2">
                        <button type="submit" class="btn btn-primary" <?php echo $tableMissing ? 'disabled' : ''; ?>>Anlegen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function shopFieldTake(button) {
        document.getElementById('sf-namespace').value = button.dataset.ns;
        document.getElementById('sf-key').value = button.dataset.key;
        document.getElementById('sf-path').value = button.dataset.path;
        document.getElementById('sf-namespace').scrollIntoView({behavior: 'smooth', block: 'center'});
    }
</script>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
