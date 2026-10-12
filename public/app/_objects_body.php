<?php

declare(strict_types=1);

/**
 * connect/public/app/_objects_body.php
 *
 * Generische Objekt-Ansicht: Connection waehlen, Object waehlen, die
 * Datensaetze anzeigen. Zeigt standardmaessig die zwischengespeicherten
 * Daten aus connect_object_records (schnell, kein API-Aufruf) -- "Vorschau
 * aktualisieren" holt bei Bedarf frisch von der externen API und
 * aktualisiert den Cache (siehe Connect\Sync\ObjectCache). Damit muss die
 * Seite nicht bei jedem Aufruf zur externen API (Rate-Limits, Geschwindigkeit).
 *
 * WICHTIG: Diese Seite ist eine schnelle VORSCHAU, kein vollstaendiger
 * Sync -- "Vorschau aktualisieren" ist bewusst auf PREVIEW_LIMIT Datensaetze
 * begrenzt, auch wenn read() (dank Pagination) insgesamt viel mehr liefern
 * wuerde. Der vollstaendige, unbegrenzte Hintergrund-Sync ist Aufgabe des
 * Schedulers (naechster Baustein), nicht dieser manuellen Vorschau.
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Shopify\TokenRefresher;
use Connect\Sync\ObjectCache;

const PREVIEW_LIMIT = 100;

$isAdmin = $isAdmin ?? false;

$pdo = $app->pdo;
$shop = $app->shop;
$objectCache = new ObjectCache($pdo);

$connectionsStmt = $pdo->prepare(
    'SELECT c.id, c.label, c.connector_type_id, t.name AS connector_type_name
     FROM connect_connections c
     JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE c.shop = :shop
     ORDER BY c.label'
);
$connectionsStmt->execute(['shop' => $shop]);
$connections = $connectionsStmt->fetchAll();

$selectedConnectionId = isset($_GET['connection_id']) && $_GET['connection_id'] !== '' ? (int) $_GET['connection_id'] : null;
$selectedObjectKey = $_GET['object'] ?? null;

$objectsForSelectedConnection = [];
$fieldsForTable = [];
$repeatingGroupFields = [];
$rows = [];
$errorMessage = null;
$syncMessage = null;
$lastRefreshedAt = null;

/**
 * Baut den Connector fuer die gewaehlte Connection (mit Token-Refresh) --
 * an zwei Stellen gebraucht (Sync-Aktion + spaeter ggf. weitere), deshalb
 * als kleine lokale Hilfsfunktion statt Code-Duplizierung.
 */
$buildConnector = function () use ($pdo, $app, $selectedConnectionId): \Connect\Connector\ConnectorInterface {
    $tokenRefresher = TokenRefresher::fromConfig($app->config);
    $factory = new ConnectorFactory($pdo, $app->crypto, new HttpClient(), $tokenRefresher);

    return $factory->forConnection($selectedConnectionId);
};

// -----------------------------------------------------------------------
// POST: Jetzt synchronisieren -- holt frisch von der externen API und
// aktualisiert den Cache. Kein Redirect, faellt einfach in die normale
// Anzeige (die dann den frisch aktualisierten Cache zeigt).
// -----------------------------------------------------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_now'
    && $selectedConnectionId !== null && $selectedObjectKey !== null && $selectedObjectKey !== ''
) {
    $ownershipCheck = $pdo->prepare('SELECT id FROM connect_connections WHERE id = :id AND shop = :shop');
    $ownershipCheck->execute(['id' => $selectedConnectionId, 'shop' => $shop]);

    if ($ownershipCheck->fetchColumn() === false) {
        $errorMessage = 'Connection nicht gefunden.';
    } else {
        try {
            $connector = $buildConnector();
            $objectDefinition = null;
            foreach ($connector->getObjects() as $candidate) {
                if ($candidate->key === $selectedObjectKey) {
                    $objectDefinition = $candidate;
                    break;
                }
            }

            if ($objectDefinition === null) {
                $errorMessage = "Object \"{$selectedObjectKey}\" nicht gefunden.";
            } else {
                // Diese Seite ist bewusst eine schnelle VORSCHAU, kein
                // vollstaendiger Sync -- deshalb hartes Limit, unabhaengig
                // davon, wie viele Datensaetze read() insgesamt liefern
                // wuerde (kann bei grossen Katalogen sehr viel sein, siehe
                // Diskussion). Der vollstaendige Hintergrund-Sync ohne
                // Limit ist Aufgabe des Schedulers (naechster Baustein).
                $count = $objectCache->refresh($connector, $selectedConnectionId, $objectDefinition, [], PREVIEW_LIMIT)['processed'];
                $syncMessage = "{$count} Datensätze aktualisiert" . ($count >= PREVIEW_LIMIT ? ' (Vorschau-Limit ' . PREVIEW_LIMIT . ' erreicht, es gibt möglicherweise mehr)' : '') . '.';
            }
        } catch (\Throwable $e) {
            $errorMessage = 'Synchronisierung fehlgeschlagen: ' . $e->getMessage();
        }
    }
}

if ($selectedConnectionId !== null) {
    $ownershipCheck = $pdo->prepare('SELECT connector_type_id FROM connect_connections WHERE id = :id AND shop = :shop');
    $ownershipCheck->execute(['id' => $selectedConnectionId, 'shop' => $shop]);
    $connectorTypeId = $ownershipCheck->fetchColumn();

    if ($connectorTypeId !== false) {
        $objStmt = $pdo->prepare('SELECT object_key, label FROM connect_objects WHERE connector_type_id = :id ORDER BY label');
        $objStmt->execute(['id' => $connectorTypeId]);
        $objectsForSelectedConnection = $objStmt->fetchAll();

        if ($selectedObjectKey !== null && $selectedObjectKey !== '') {
            $objectIdStmt = $pdo->prepare('SELECT id FROM connect_objects WHERE connector_type_id = :ctid AND object_key = :key');
            $objectIdStmt->execute(['ctid' => $connectorTypeId, 'key' => $selectedObjectKey]);
            $objectId = $objectIdStmt->fetchColumn();

            if ($objectId !== false) {
                // Oberste, nicht-wiederholende Felder als normale Tabellenspalten.
                $fieldsStmt = $pdo->prepare(
                    'SELECT field_key, label FROM connect_object_fields
                     WHERE object_id = :id AND is_repeating_group = 0 AND parent_field_id IS NULL
                     ORDER BY id'
                );
                $fieldsStmt->execute(['id' => $objectId]);
                $fieldsForTable = $fieldsStmt->fetchAll();

                // Wiederholende Gruppen (z.B. Bestellpositionen) bekommen je EINE
                // zusaetzliche Spalte mit einer kompakten Zusammenfassung aller
                // Zeilen (GenericHttpConnector::mapRowToFields loest sie seit
                // Kurzem tatsaechlich auf, siehe mapRepeatingGroup()) -- eine
                // eigene Spalte pro Kindfeld waere hier nicht sinnvoll, da ein
                // einzelner Datensatz (eine Bestellung) mehrere Zeilen haben kann.
                $groupFieldsStmt = $pdo->prepare(
                    'SELECT id, field_key, label FROM connect_object_fields
                     WHERE object_id = :id AND is_repeating_group = 1
                     ORDER BY id'
                );
                $groupFieldsStmt->execute(['id' => $objectId]);
                $repeatingGroupFields = $groupFieldsStmt->fetchAll();

                foreach ($repeatingGroupFields as &$groupField) {
                    $childFieldsStmt = $pdo->prepare(
                        'SELECT field_key, label FROM connect_object_fields WHERE parent_field_id = :parent_id ORDER BY id'
                    );
                    $childFieldsStmt->execute(['parent_id' => $groupField['id']]);
                    $groupField['children'] = $childFieldsStmt->fetchAll();
                }
                unset($groupField);

                // Anzeige kommt IMMER aus dem Cache, nie live -- "Jetzt
                // synchronisieren" oben aktualisiert ihn bei Bedarf.
                $rows = $objectCache->getCached($selectedConnectionId, $selectedObjectKey);
                $lastRefreshedAt = $objectCache->lastRefreshedAt($selectedConnectionId, $selectedObjectKey);
            }
        }
    }
}

$pageTitle = 'Daten ansehen' . ($isAdmin ? ' — ' . $shop : '');

// Diese Seite ("Daten ansehen") ist bewusst nicht mehr Teil der Merchant-
// Navigation (siehe _shop_nav.php) -- nur Admins sehen hier die
// Connections/Objects/Mappings-Links, Merchants nur Dashboard + Einstellungen.
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

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Connection &amp; Object wählen</h3>
            </div>
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <input type="hidden" name="shop" value="<?php echo htmlspecialchars($shop); ?>">
                    <div class="col-auto">
                        <label class="form-label">Connection</label>
                        <select name="connection_id" class="form-select" onchange="this.form.submit()">
                            <option value="">-- auswählen --</option>
                            <?php foreach ($connections as $connection): ?>
                                <option value="<?php echo (int) $connection['id']; ?>" <?php echo $selectedConnectionId === (int) $connection['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($connection['label'] . ' (' . $connection['connector_type_name'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php
                    $selectedTypeLogo = '';
                    foreach ($connections as $connection) {
                        if ($selectedConnectionId === (int) $connection['id']) {
                            $selectedTypeLogo = \Connect\Connector\ConnectorLogo::imgForTypeId($pdo, (int) $connection['connector_type_id'], 32, '');
                        }
                    }
                    ?>
                    <?php if ($selectedTypeLogo !== ''): ?>
                        <div class="col-auto pb-1"><?php echo $selectedTypeLogo; ?></div>
                    <?php endif; ?>
                    <?php if (count($objectsForSelectedConnection) > 0): ?>
                        <div class="col-auto">
                            <label class="form-label">Object</label>
                            <select name="object" class="form-select" onchange="this.form.submit()">
                                <option value="">-- auswählen --</option>
                                <?php foreach ($objectsForSelectedConnection as $object): ?>
                                    <option value="<?php echo htmlspecialchars($object['object_key']); ?>" <?php echo $selectedObjectKey === $object['object_key'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($object['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <?php if ($errorMessage !== null): ?>
        <div class="col-12">
            <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($syncMessage !== null): ?>
        <div class="col-12">
            <div class="alert alert-success"><?php echo htmlspecialchars($syncMessage); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($selectedObjectKey !== null && $selectedObjectKey !== ''): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between w-100">
                    <h3 class="card-title mb-0">
                        Vorschau (max. <?php echo PREVIEW_LIMIT; ?> Datensätze)
                        <span class="text-secondary fw-normal">
                            (<?php echo $lastRefreshedAt !== null ? 'zuletzt aktualisiert: ' . htmlspecialchars($lastRefreshedAt) : 'noch nie geladen'; ?>)
                        </span>
                    </h3>
                    <form method="post">
                        <input type="hidden" name="action" value="sync_now">
                        <input type="hidden" name="connection_id" value="<?php echo (int) $selectedConnectionId; ?>">
                        <input type="hidden" name="object" value="<?php echo htmlspecialchars($selectedObjectKey); ?>">
                        <button type="submit" class="btn btn-sm btn-primary">Vorschau aktualisieren</button>
                    </form>
                </div>
                <?php if (count($rows) === 0): ?>
                    <div class="card-body text-secondary">
                        Noch keine Daten im Cache — auf "Vorschau aktualisieren" klicken, um sie erstmalig zu holen.
                    </div>
                <?php else: ?>
                    <div class="card-body pb-0">
                        <input type="text" id="objects-search" class="form-control" placeholder="Suchen … (durchsucht alle sichtbaren Spalten)" autocomplete="off">
                        <small class="text-secondary" id="objects-search-count"></small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table table-striped" id="objects-table">
                            <thead>
                                <tr>
                                    <?php foreach ($fieldsForTable as $field): ?>
                                        <th><?php echo htmlspecialchars($field['label']); ?></th>
                                    <?php endforeach; ?>
                                    <?php foreach ($repeatingGroupFields as $groupField): ?>
                                        <th><?php echo htmlspecialchars($groupField['label']); ?></th>
                                    <?php endforeach; ?>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php $isOrphaned = ($row['_status'] ?? 'active') === 'orphaned'; ?>
                                    <tr class="<?php echo $isOrphaned ? 'text-secondary' : ''; ?>">
                                        <?php foreach ($fieldsForTable as $field): ?>
                                            <td><?php echo htmlspecialchars((string) ($row[$field['field_key']] ?? '')); ?></td>
                                        <?php endforeach; ?>
                                        <?php foreach ($repeatingGroupFields as $groupField): ?>
                                            <td>
                                                <?php
                                                $items = is_array($row[$groupField['field_key']] ?? null) ? $row[$groupField['field_key']] : [];
                                                $lines = [];
                                                foreach ($items as $item) {
                                                    $parts = [];
                                                    foreach ($groupField['children'] as $childField) {
                                                        $parts[] = $childField['label'] . ': ' . (string) ($item[$childField['field_key']] ?? '');
                                                    }
                                                    $lines[] = htmlspecialchars(implode(', ', $parts));
                                                }
                                                echo implode('<br>', $lines);
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td>
                                            <?php if ($isOrphaned): ?>
                                                <span class="badge bg-red-lt" title="Bei der letzten vollständigen Synchronisierung nicht mehr gefunden — vermutlich in der Quelle gelöscht/archiviert.">fehlt in Quelle</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <script>
                        // Bewusst schlichtes Vanilla-JS statt einer Formular-/Tabellen-
                        // Bibliothek (gleiches Prinzip wie showAuthFields() in
                        // _connections_body.php): filtert clientseitig, da die Tabelle
                        // ohnehin immer den KOMPLETTEN Cache-Inhalt auf einmal zeigt
                        // (kein Server-Paging) -- durchsucht also wirklich alles, was
                        // aktuell im Cache steht, nicht nur eine angezeigte Seite.
                        (function () {
                            var input = document.getElementById('objects-search');
                            var rows = document.querySelectorAll('#objects-table tbody tr');
                            var countLabel = document.getElementById('objects-search-count');

                            function updateCount(visible) {
                                countLabel.textContent = visible + ' von ' + rows.length + ' Zeilen';
                            }

                            input.addEventListener('keyup', function () {
                                var term = input.value.toLowerCase();
                                var visible = 0;
                                rows.forEach(function (row) {
                                    var matches = row.textContent.toLowerCase().indexOf(term) !== -1;
                                    row.hidden = !matches;
                                    if (matches) {
                                        visible++;
                                    }
                                });
                                updateCount(visible);
                            });

                            updateCount(rows.length);
                        })();
                    </script>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>