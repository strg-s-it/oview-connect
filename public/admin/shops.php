<?php

declare(strict_types=1);

/**
 * connect/public/admin/shops.php
 *
 * Shop-Auswahl fuer den Admin-Bereich. Nutzt bewusst nicht admin_bootstrap.php
 * (das wuerde ohne ?shop= wieder hierher umleiten -- Zirkel), sondern nur
 * das Login, ohne Shop-Anforderung.
 *
 * Solange der echte Einstiegs-Button in der alten Welt (der connect_shops
 * befuellen wird) noch nicht existiert, gibt es hier zusaetzlich ein
 * simples Formular, um einen Test-Shop manuell einzutragen.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Connector\ConnectorFactory;
use Connect\Database;
use Connect\Http\HttpClient;
use Connect\Legacy\LegacyBridge;
use Connect\Security\Crypto;
use Connect\Shopify\TokenRefresher;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$bridgeMessage = null;
$bridgeMessageType = 'info';

// -----------------------------------------------------------------------
// POST: Shop aus der alten Welt uebernehmen (die eigentliche
// Migrationsbruecke, admin-ausgeloest -- kein App-Bridge-Session-Token
// noetig, wir kopieren den bereits vorhandenen Access-Token 1:1).
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'migrate_from_legacy') {
    $shopInput = trim((string) ($_POST['shop'] ?? ''));

    if ($shopInput === '') {
        $bridgeMessage = 'Bitte einen Shop-Namen angeben.';
        $bridgeMessageType = 'danger';
    } else {
        // Logik in Connect\Legacy\ShopProvisioner -- gemeinsam mit der automatischen
        // Anlage von Neuinstallationen (public/internal/provision_shop.php).
        try {
            $provisioned = (new \Connect\Legacy\ShopProvisioner($pdo, $config))->provisionFromLegacy($shopInput);
            $bridgeMessage = $provisioned['message'];
            $bridgeMessageType = 'success';
        } catch (\Throwable $e) {
            $bridgeMessage = 'Fehler bei der Migrationsbrücke: ' . $e->getMessage();
            $bridgeMessageType = 'danger';
        }
    }
}

// -----------------------------------------------------------------------
// POST: Schalter "Neuinstallationen direkt in Connect" (connect_settings, Migration 0076)
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_new_installs') {
    try {
        \Connect\Setup\Settings::set($pdo, \Connect\Setup\Settings::NEW_INSTALLS_TO_CONNECT, isset($_POST['new_installs_to_connect']) ? '1' : '0');
        $bridgeMessage = isset($_POST['new_installs_to_connect'])
            ? 'Neuinstallationen werden ab sofort direkt in Connect eingerichtet.'
            : 'Neuinstallationen landen wieder in der alten Welt.';
        $bridgeMessageType = 'success';
    } catch (\Throwable $e) {
        $bridgeMessage = 'Speichern fehlgeschlagen (Migration 0076 eingespielt?): ' . $e->getMessage();
        $bridgeMessageType = 'danger';
    }
}

// -----------------------------------------------------------------------
// POST: Shop komplett aus Connect entfernen (z.B. fehlerhafte Test-Eintraege
// wie "crystalp" statt "crystalpjewellery"). Loescht Connections (kaskadiert
// per FK auf Sync Mappings/Field Mappings/Jobs/Runs), Webhook-Events und
// Lookup-Tabellen dieses Shops, zuletzt den Shop-Eintrag selbst.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_shop') {
    $shopToRemove = trim((string) ($_POST['shop'] ?? ''));

    if ($shopToRemove === '') {
        $bridgeMessage = 'Kein Shop angegeben.';
        $bridgeMessageType = 'danger';
    } else {
        \Connect\Setup\ShopRemover::remove($pdo, $shopToRemove);

        $bridgeMessage = "Shop \"{$shopToRemove}\" und alle zugehörigen Daten wurden entfernt.";
        $bridgeMessageType = 'success';
    }
}

// -----------------------------------------------------------------------
// POST: Ziel-Connection fuer einen Shop setzen (z.B. MGM) -- damit weiss die
// Merchant-Oberflaeche (_sync_mappings_body.php), mit welchem System
// Lagerbestand/Auftraege abgeglichen werden sollen.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_target_connection') {
    $targetShop = trim((string) ($_POST['shop'] ?? ''));
    $targetConnectionId = (int) ($_POST['target_connection_id'] ?? 0);

    // Shopify darf nie als Ziel-Connection gesetzt werden -- serverseitig
    // nochmal geprueft, nicht nur durch das gefilterte Dropdown.
    $isShopify = false;
    if ($targetConnectionId > 0) {
        $typeCheck = $pdo->prepare(
            "SELECT 1 FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.id = :id AND t.type_key = 'shopify'"
        );
        $typeCheck->execute(['id' => $targetConnectionId]);
        $isShopify = $typeCheck->fetchColumn() !== false;
    }

    if ($isShopify) {
        $bridgeMessage = 'Shopify kann nicht als Ziel-System gewählt werden (Shopify ist immer die Quelle).';
        $bridgeMessageType = 'danger';
    } else {
        $update = $pdo->prepare(
            'UPDATE connect_shops SET target_connection_id = :target_connection_id WHERE shop = :shop'
        );
        $update->execute([
            'target_connection_id' => $targetConnectionId > 0 ? $targetConnectionId : null,
            'shop' => $targetShop,
        ]);

        $bridgeMessage = "Ziel-Connection für \"{$targetShop}\" aktualisiert.";
        $bridgeMessageType = 'success';
    }
}

// -----------------------------------------------------------------------
// Routing-Schalter alte Welt <-> Connect (Migration 0050, Spalte
// connect_shops.live_in_connect). Die Spalte wird VOR dem Zugriff geprueft: laeuft
// dieser Code, bevor die Migration eingespielt ist, bleibt die Seite benutzbar
// (ohne Schalter) statt mit einem SQL-Fehler abzubrechen.
// -----------------------------------------------------------------------
$hasLiveColumn = $pdo->query("SHOW COLUMNS FROM connect_shops LIKE 'live_in_connect'")->fetch() !== false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_live_in_connect') {
    $liveShop = trim((string) ($_POST['shop'] ?? ''));
    $makeLive = ($_POST['live'] ?? '') === '1';

    if (!$hasLiveColumn) {
        $bridgeMessage = 'Der Schalter braucht die Migration 0050 (php connect/bin/install.php).';
        $bridgeMessageType = 'danger';
    } elseif ($liveShop === '') {
        $bridgeMessage = 'Kein Shop angegeben.';
        $bridgeMessageType = 'danger';
    } else {
        $canSwitch = true;

        // Umstellen auf Connect nur mit funktionierender Shopify-Connection --
        // serverseitig geprueft, nicht nur durch den Button. Zurueck zur alten
        // Welt geht immer (Rueckweg darf nie blockiert sein).
        if ($makeLive) {
            $activeCheck = $pdo->prepare(
                "SELECT 1 FROM connect_connections c
                 JOIN connect_connector_types t ON t.id = c.connector_type_id
                 WHERE c.shop = :shop AND t.type_key = 'shopify' AND c.status = 'active' LIMIT 1"
            );
            $activeCheck->execute(['shop' => $liveShop]);
            if ($activeCheck->fetchColumn() === false) {
                $canSwitch = false;
                $bridgeMessage = "Shop \"{$liveShop}\" hat keine aktive Shopify-Connection. Bitte zuerst unter Connections \"Verbindung testen\".";
                $bridgeMessageType = 'danger';
            }
        }

        if ($canSwitch) {
            $liveUpdate = $pdo->prepare(
                'UPDATE connect_shops SET live_in_connect = :live, live_changed_at = CURRENT_TIMESTAMP WHERE shop = :shop'
            );
            $liveUpdate->execute(['live' => $makeLive ? 1 : 0, 'shop' => $liveShop]);

            if ($liveUpdate->rowCount() === 0) {
                $bridgeMessage = "Shop \"{$liveShop}\" nicht gefunden oder Zustand unverändert.";
                $bridgeMessageType = 'info';
            } else {
                $bridgeMessage = $makeLive
                    ? "Shop \"{$liveShop}\" läuft jetzt in Connect."
                    : "Shop \"{$liveShop}\" läuft wieder in der alten Welt.";
                $bridgeMessageType = 'success';
            }

            // Webhook-Abos beider Welten passend zum Schalter (siehe
            // Connect\Routing\LiveSwitch) -- auch wenn der Zustand schon
            // stimmte, damit ein erneuter Klick als "Abos reparieren" dient.
            try {
                $liveSwitch = new \Connect\Routing\LiveSwitch(
                    $pdo,
                    new Crypto($config->require('CONNECT_ENCRYPTION_KEY')),
                    new HttpClient(),
                    $config->require('CONNECT_APP_URL'),
                    $config->optional('CONNECT_SHOPIFY_API_VERSION', '2026-07')
                );
                $webhookNotes = $liveSwitch->syncWebhooks($liveShop);
                if ($webhookNotes !== []) {
                    $bridgeMessage .= ' Webhooks: ' . implode(' ', $webhookNotes);
                }
            } catch (\Throwable $e) {
                $bridgeMessage .= ' (Webhook-Abos konnten nicht abgeglichen werden: ' . $e->getMessage() . ')';
            }
        }
    }
}

$shops = $pdo->query(
    'SELECT s.shop, s.display_name, s.first_seen_at, s.last_seen_at, s.target_connection_id,
            ' . ($hasLiveColumn ? 's.live_in_connect, s.live_changed_at' : '0 AS live_in_connect, NULL AS live_changed_at') . ',
            (SELECT COUNT(*) FROM connect_connections c WHERE c.shop = s.shop) AS connection_count
     FROM connect_shops s
     ORDER BY s.last_seen_at DESC'
)->fetchAll();

// Abo je Shop (Migration 0079): plan = Name-Schluessel, plan_status = none -> Shopify meldet kein
// aktives Abo, NULL = noch nie abgefragt. Fehlt die Migration, bleibt die Spalte leer.
$plansByShop = [];
try {
    foreach ($pdo->query('SELECT shop, plan, plan_status, plan_checked_at FROM connect_shops')->fetchAll() as $planRow) {
        $plansByShop[(string) $planRow['shop']] = $planRow;
    }
} catch (\PDOException) {
    // Migration 0079 fehlt
}

// Alle Connections gruppiert nach Shop, fuer die Ziel-Connection-Auswahl
// unten (ein Shop hat meist nur eine Handvoll Connections, kein Problem,
// das komplett im Speicher zu gruppieren statt pro Zeile nachzuladen).
// Shopify bewusst ausgeschlossen -- das ist immer die feste Quelle, niemals
// ein gueltiges Ziel-System fuer Lagerbestand/Auftraege.
$connectionsByShop = [];
foreach ($pdo->query("SELECT c.id, c.shop, c.label, c.config, t.name AS type_name FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id WHERE t.type_key != 'shopify' ORDER BY c.label")->fetchAll() as $connectionRow) {
    $connectionsByShop[$connectionRow['shop']][] = $connectionRow;
}

// Verbindungsstatus (Heartbeat, Migration 0105): je Shop die Shopify-Connection und das Ziel-System.
// Ohne die Migration bleibt die Spalte leer.
$healthByShop = [];
$healthAvailable = true;
try {
    foreach ($pdo->query(
        "SELECT c.id, c.shop, c.label, c.health_status, c.health_checked_at, c.health_since, c.health_message, t.type_key, t.name AS type_name
         FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id"
    )->fetchAll() as $healthRow) {
        $healthByShop[(string) $healthRow['shop']][] = $healthRow;
    }
} catch (\PDOException) {
    $healthAvailable = false;
}

/**
 * Eine Statuszeile (Badge + Hinweis) fuer eine Connection oder "–", wenn es keine gibt.
 *
 * @param array<string, mixed>|null $connection
 */
$healthBadge = static function (string $name, ?array $connection): string {
    if ($connection === null) {
        return '<div class="small text-secondary text-nowrap">' . htmlspecialchars($name) . ': –</div>';
    }
    $status = $connection['health_status'];
    $since = $connection['health_since'] !== null ? (string) $connection['health_since'] : null;
    if ($status === 'ok') {
        $badge = '<span class="badge bg-green-lt">ok</span>';
        $hint = $connection['health_checked_at'] !== null ? 'geprüft ' . date('H:i', (int) strtotime((string) $connection['health_checked_at'])) : '';
        $title = '';
    } elseif ($status === 'error') {
        $badge = '<span class="badge bg-red-lt">Fehler</span>';
        $hint = $since !== null ? 'seit ' . date('d.m. H:i', (int) strtotime($since)) : '';
        $title = (string) ($connection['health_message'] ?? '');
    } else {
        $badge = '<span class="badge bg-secondary-lt">nicht geprüft</span>';
        $hint = '';
        $title = '';
    }

    return '<div class="small text-nowrap"' . ($title !== '' ? ' title="' . htmlspecialchars($title) . '"' : '') . '>'
        . htmlspecialchars($name) . ': ' . $badge . ($hint !== '' ? ' <span class="text-secondary">' . htmlspecialchars($hint) . '</span>' : '') . '</div>'
        . ($status === 'error' && $title !== '' ? '<div class="small text-danger" style="max-width:240px;white-space:normal">' . htmlspecialchars(mb_strimwidth($title, 0, 110, '…')) . '</div>' : '');
};

$pageTitle = 'Shops';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = []; // kein Shop-Bezug auf dieser Seite (Shop-Auswahl selbst)
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
$flashMessage = $bridgeMessage;
$flashType = $bridgeMessageType;

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Shops, die Connect nutzen</h3>
            </div>
            <?php if (count($shops) === 0): ?>
                <div class="card-body text-secondary">Noch kein Shop erfasst.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Shop</th>
                                <th>Connections</th>
                                <th>Abo</th>
                                <th>Zuständig</th>
                                <th>Verbindung</th>
                                <th>Ziel-System (Lagerbestand/Aufträge)</th>
                                <th>Zuletzt gesehen</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shops as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['shop']); ?></td>
                                    <td><?php echo (int) $row['connection_count']; ?></td>
                                    <td class="small">
                                        <?php
                                        $planRow = $plansByShop[$row['shop']] ?? null;
                                        if ($planRow === null || $planRow['plan_status'] === null): ?>
                                            <span class="text-secondary">unbekannt</span>
                                        <?php elseif ($planRow['plan_status'] === 'none'): ?>
                                            <?php $sinceDays = (int) floor((time() - (int) strtotime((string) $row['first_seen_at'])) / 86400); ?>
                                            <span class="badge bg-orange-lt">kein Abo</span>
                                            <div class="text-secondary">seit <?php echo $sinceDays; ?> Tagen dabei</div>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($planRow['plan'] !== null && isset(\Connect\Billing\Plans::PLANS[(string) $planRow['plan']]) ? \Connect\Billing\Plans::PLANS[(string) $planRow['plan']]['name'] : 'aktiv (Sondervereinbarung)'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$hasLiveColumn): ?>
                                            <span class="badge bg-yellow-lt" title="php connect/bin/install.php ausführen">Migration 0050 fehlt</span>
                                        <?php else: ?>
                                            <?php
                                            $isLive = (int) $row['live_in_connect'] === 1;
                                            $liveConfirmText = $isLive
                                                ? 'Shop "' . $row['shop'] . '" wieder der ALTEN Welt zuordnen? Connect verarbeitet ihn dann nicht mehr.'
                                                : 'Shop "' . $row['shop'] . '" auf CONNECT umstellen? Ab dann ist Connect für diesen Shop zuständig, die alte Welt verarbeitet ihn nicht mehr.';
                                            ?>
                                            <form method="post" class="d-flex align-items-center gap-2 m-0 text-nowrap" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode($liveConfirmText, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>);">
                                                <input type="hidden" name="action" value="set_live_in_connect">
                                                <input type="hidden" name="shop" value="<?php echo htmlspecialchars($row['shop']); ?>">
                                                <input type="hidden" name="live" value="<?php echo $isLive ? '0' : '1'; ?>">
                                                <span class="badge <?php echo $isLive ? 'bg-green-lt' : 'bg-secondary-lt'; ?>"><?php echo $isLive ? 'Connect' : 'Alte Welt'; ?></span>
                                                <button type="submit" class="btn btn-sm <?php echo $isLive ? 'btn-outline-secondary' : 'btn-outline-success'; ?>">
                                                    <?php echo $isLive ? 'Zurück zur alten Welt' : 'In Connect aktivieren'; ?>
                                                </button>
                                            </form>
                                            <?php if ($row['live_changed_at'] !== null): ?>
                                                <div class="text-secondary small mt-1">seit <?php echo htmlspecialchars((string) $row['live_changed_at']); ?></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$healthAvailable): ?>
                                            <span class="badge bg-yellow-lt" title="php connect/bin/install.php ausführen">Migration 0105 fehlt</span>
                                        <?php else: ?>
                                            <?php
                                            $shopConnections = $healthByShop[$row['shop']] ?? [];
                                            $shopifyHealth = null;
                                            $targetHealth = null;
                                            foreach ($shopConnections as $healthConnection) {
                                                if ($healthConnection['type_key'] === 'shopify' && $shopifyHealth === null) {
                                                    $shopifyHealth = $healthConnection;
                                                }
                                                if ($row['target_connection_id'] !== null && (int) $row['target_connection_id'] === (int) $healthConnection['id']) {
                                                    $targetHealth = $healthConnection;
                                                }
                                            }
                                            echo $healthBadge('Shopify', $shopifyHealth);
                                            echo $healthBadge($targetHealth !== null ? (string) $targetHealth['type_name'] : 'Ziel', $targetHealth);
                                            ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" class="d-flex gap-1">
                                            <input type="hidden" name="action" value="set_target_connection">
                                            <input type="hidden" name="shop" value="<?php echo htmlspecialchars($row['shop']); ?>">
                                            <select name="target_connection_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                                <option value="">-- keine --</option>
                                                <?php foreach (($connectionsByShop[$row['shop']] ?? []) as $connectionOption): ?>
                                                    <option value="<?php echo (int) $connectionOption['id']; ?>" <?php echo ((int) $row['target_connection_id'] === (int) $connectionOption['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($connectionOption['label'] . ' (' . $connectionOption['type_name'] . ')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                        <?php foreach (($connectionsByShop[$row['shop']] ?? []) as $connectionOption): ?>
                                            <?php if ((int) $row['target_connection_id'] === (int) $connectionOption['id']): ?>
                                                <?php $accountSummary = \Connect\Setup\ConnectionAccount::summary($connectionOption['config'] ?? null); ?>
                                                <?php if ($accountSummary !== ''): ?>
                                                    <div class="text-secondary small mt-1"><?php echo htmlspecialchars($accountSummary); ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="text-secondary"><?php echo htmlspecialchars((string) $row['last_seen_at']); ?></td>
                                    <td class="text-nowrap">
                                        <div class="d-flex gap-2 justify-content-end">
                                        <a href="connections.php?shop=<?php echo urlencode($row['shop']); ?>" class="btn btn-sm btn-outline-primary">
                                            Ansehen
                                        </a>
                                        <form method="post" class="m-0" onsubmit="return confirm('Shop \'<?php echo htmlspecialchars(addslashes($row['shop'])); ?>\' und ALLE zugehörigen Connect-Daten (Connections, Webhooks, Lookup-Tabellen) unwiderruflich entfernen?');">
                                            <input type="hidden" name="action" value="remove_shop">
                                            <input type="hidden" name="shop" value="<?php echo htmlspecialchars($row['shop']); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Entfernen</button>
                                        </form>
                                        </div>
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
                <h3 class="card-title">Shop aus der alten Welt übernehmen</h3>
            </div>
            <div class="card-body">
                <p class="text-secondary">
                    Übernimmt den bereits vorhandenen Shopify-Access-Token aus der alten oView-Installation
                    (<code>oview_stores</code>) 1:1 in eine neue Connect-Connection — ohne dass die App im
                    Shopify-Adminbereich geöffnet werden muss. Voraussetzung: die App ist für diesen Shop
                    bereits in der alten Welt installiert.
                </p>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="migrate_from_legacy">
                    <div class="col-auto">
                        <input type="text" name="shop" class="form-control" placeholder="z.B. crystalp oder crystalp.myshopify.com" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">Token übernehmen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php $newInstallsSetting = \Connect\Setup\Settings::get($pdo, \Connect\Setup\Settings::NEW_INSTALLS_TO_CONNECT); ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Neuinstallationen</h3>
                <div class="card-actions">
                    <span class="badge <?php echo $newInstallsSetting === '1' ? 'bg-green-lt' : 'bg-secondary-lt'; ?>"><?php echo $newInstallsSetting === '1' ? 'scharf' : 'aus'; ?></span>
                </div>
            </div>
            <div class="card-body">
                <p class="text-secondary">
                    Ist das an, wird ein Shop, der die App neu installiert und in der alten Welt noch keine Einstellungen hat,
                    beim ersten Öffnen automatisch in Connect angelegt, umgestellt (live) und landet im ersten Fenster
                    (Software wählen, Rechtstexte). Bestehende Shops der alten Welt sind nicht betroffen – die ziehst du weiter
                    über „Umzug“ um. Aus = Neuinstallationen landen wie bisher in der alten Welt.
                </p>
                <form method="post" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="action" value="save_new_installs">
                    <label class="form-check mb-0">
                        <input type="checkbox" name="new_installs_to_connect" class="form-check-input" <?php echo $newInstallsSetting === '1' ? 'checked' : ''; ?>>
                        <span class="form-check-label">Neuinstallationen direkt in Connect einrichten</span>
                    </label>
                    <button type="submit" class="btn btn-primary" onclick="return this.form.new_installs_to_connect.checked === <?php echo $newInstallsSetting === '1' ? 'true' : 'false'; ?> || confirm(this.form.new_installs_to_connect.checked ? 'Neuinstallationen ab sofort direkt in Connect einrichten?' : 'Neuinstallationen wieder in der alten Welt einrichten?');">Speichern</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
