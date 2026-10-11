<?php

declare(strict_types=1);

/**
 * connect/public/admin/mappings.php
 *
 * Zwei grundverschiedene Nutzungsarten von Mapping-Vorlagen (siehe
 * mapping_templates.php), beide hier auf einer Seite:
 *
 *   - AUTOMATISCH (Webhook/Zeitplan-Nachlauf, z.B. Shopify-Bestellung -> MGM-
 *     Auftrag): braucht eine "Aktivierung" -- WELCHE Connections dieses Shops,
 *     WELCHER Ausloeser (siehe "Aktivierte Mapping-Vorlagen" unten). Nur
 *     Vorlagen, deren Ziel-Object eine "Anlegen"-Operation hat, koennen das
 *     ueberhaupt (Connect\Sync\SyncRunner::translateAndWrite() schreibt immer
 *     ueber "create").
 *   - DIREKT bei einem gezielten Merchant-Klick (z.B. "Importieren" auf der
 *     Produkte-Seite, Connect\Sync\ProductImportService) -- braucht KEINE
 *     Aktivierung: die Vorlage wird direkt ueber ihr Objektpaar aufgeloest,
 *     Connections kommen aus dem Shop-Kontext. Gilt fuer JEDES Ziel-System
 *     gleichermassen (kein Sonderfall mehr fuer Shopify o.ae.).
 *
 * Die Karte "Mapping-Vorlagen für diesen Shop" oben zeigt ALLE fuer diesen
 * Shop relevanten Vorlagen (beide Richtungen, beide Nutzungsarten) auf einen
 * Blick -- der Status pro Zeile sagt, ob sie automatisch laeuft (und wo das
 * verwaltet wird) oder direkt verwendet wird (keine weitere Einrichtung noetig).
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$pdo = $app->pdo;
$shop = $app->shop;

$isAdmin = true;
$flashMessage = null;
$flashType = 'info';

// -----------------------------------------------------------------------
// POST: Vorlage fuer diesen Shop aktivieren (Quelle+Ziel-Connection + Auslöser).
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_activation') {
    $templateId = (int) ($_POST['mapping_template_id'] ?? 0);
    $sourceConnectionId = (int) ($_POST['source_connection_id'] ?? 0);
    $targetConnectionId = (int) ($_POST['target_connection_id'] ?? 0);
    $webhookEnabled = isset($_POST['webhook_enabled']);
    $scheduleEnabled = isset($_POST['schedule_enabled']);
    $intervalMinutes = (int) ($_POST['schedule_interval_minutes'] ?? 0);
    $triggerTopic = trim((string) ($_POST['trigger_topic'] ?? ''));

    $templateStmt = $pdo->prepare(
        'SELECT t.*, so.object_key AS source_object_key,
                (SELECT COUNT(*) FROM connect_object_operations op WHERE op.object_id = t.target_object_id AND op.operation_type = \'create\') > 0 AS target_has_create_operation
         FROM connect_mapping_templates t
         JOIN connect_objects so ON so.id = t.source_object_id
         WHERE t.id = :id'
    );
    $templateStmt->execute(['id' => $templateId]);
    $template = $templateStmt->fetch();

    // Beleg-Vorlage (Quelle "Bestellung (aufbereitet)", Migration 0067): die
    // Aktivierung selbst liest weiterhin die Shopify-Bestellung (Webhook,
    // Beispiel-Datensaetze), die Aufbereitung uebernimmt Connect\Sync\Document\
    // DocumentPipeline -- Quelle der Aktivierung ist deshalb Shopify "order".
    if ($template !== false && \Connect\Sync\Document\DocumentPipeline::templateUsesPreparedOrder($pdo, (int) $template['id'])) {
        $shopifyOrderStmt = $pdo->query(
            "SELECT o.id, o.connector_type_id FROM connect_objects o
             JOIN connect_connector_types t ON t.id = o.connector_type_id
             WHERE t.type_key = 'shopify' AND o.object_key = 'order'"
        );
        $shopifyOrder = $shopifyOrderStmt->fetch();
        if ($shopifyOrder !== false) {
            $template['source_object_id'] = $shopifyOrder['id'];
            $template['source_connector_type_id'] = $shopifyOrder['connector_type_id'];
            $template['source_object_key'] = 'order';
        }
    }

    if ($template === false) {
        $flashMessage = 'Bitte eine Mapping-Vorlage auswählen.';
        $flashType = 'danger';
    } elseif (!$template['target_has_create_operation']) {
        // Aktivierung braucht IMMER eine "Anlegen"-Operation am Ziel (siehe
        // Dateikommentar) -- eine Vorlage ohne diese Operation (z.B. ein
        // Ziel wie Shopify "product" mit fester Zwei-Schritt-Anlage-Logik,
        // siehe Connect\Sync\ProductImportService) wird stattdessen direkt
        // ohne Aktivierung verwendet, dafuer gibt es hier nichts einzurichten.
        $flashMessage = "\"{$template['name']}\" hat kein Ziel mit \"Anlegen\"-Operation -- kann nicht automatisch ausgefuehrt werden. Wird stattdessen direkt (ohne Aktivierung) von der jeweiligen Funktion verwendet, siehe \"Mapping-Vorlagen für diesen Shop\" oben.";
        $flashType = 'info';
    } elseif ($sourceConnectionId <= 0 || $targetConnectionId <= 0) {
        $flashMessage = 'Bitte Quell- und Ziel-Connection auswählen.';
        $flashType = 'danger';
    } elseif (!$webhookEnabled && !$scheduleEnabled) {
        $flashMessage = 'Bitte mindestens einen Auslöser (Webhook und/oder Zeitplan) aktivieren.';
        $flashType = 'danger';
    } elseif ($scheduleEnabled && $intervalMinutes <= 0) {
        $flashMessage = 'Bitte ein Intervall in Minuten (größer 0) angeben.';
        $flashType = 'danger';
    } elseif ($webhookEnabled && ($template['source_object_key'] !== 'order' || $triggerTopic !== 'orders/paid')) {
        // V1-Einschraenkung, siehe Plan "Mapping Engine v1": webhook-
        // getriggerte Uebersetzung ist aktuell nur fuer Bestellungen
        // verdrahtet (webhooks/order_paid.php).
        $flashMessage = 'Webhook-Auslöser ist aktuell nur für Vorlagen mit Quelle "Bestellung" + Thema "Bestellung bezahlt" verfügbar.';
        $flashType = 'danger';
    } else {
        // Beide Connections muessen zu diesem Shop UND zum jeweils von der
        // Vorlage erwarteten Connector Type gehoeren -- sonst waeren
        // Quelle/Ziel-Objekte (die die Vorlage fest vorgibt) inkonsistent.
        $sourceCheckStmt = $pdo->prepare('SELECT 1 FROM connect_connections WHERE id = :id AND shop = :shop AND connector_type_id = :type_id');
        $sourceCheckStmt->execute(['id' => $sourceConnectionId, 'shop' => $shop, 'type_id' => $template['source_connector_type_id']]);
        $sourceOk = $sourceCheckStmt->fetchColumn() !== false;

        $targetCheckStmt = $pdo->prepare('SELECT 1 FROM connect_connections WHERE id = :id AND shop = :shop AND connector_type_id = :type_id');
        $targetCheckStmt->execute(['id' => $targetConnectionId, 'shop' => $shop, 'type_id' => $template['target_connector_type_id']]);
        $targetOk = $targetCheckStmt->fetchColumn() !== false;

        if (!$sourceOk || !$targetOk) {
            $flashMessage = 'Quell- oder Ziel-Connection passt nicht zum in der Vorlage erwarteten System.';
            $flashType = 'danger';
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO connect_sync_mappings
                    (shop, name, source_connection_id, source_object_id, target_connection_id, target_object_id,
                     mapping_template_id, trigger_type, trigger_topic, schedule_interval_minutes, enabled, managed_by)
                 VALUES
                    (:shop, :name, :source_connection_id, :source_object_id, :target_connection_id, :target_object_id,
                     :mapping_template_id, :trigger_type, :trigger_topic, :interval_minutes, 0, \'system\')'
            );
            $insert->execute([
                'shop' => $shop,
                'name' => $template['name'],
                'source_connection_id' => $sourceConnectionId,
                'source_object_id' => $template['source_object_id'],
                'target_connection_id' => $targetConnectionId,
                'target_object_id' => $template['target_object_id'],
                'mapping_template_id' => $templateId,
                'trigger_type' => $webhookEnabled ? 'webhook' : ($scheduleEnabled ? 'schedule' : 'manual'),
                'trigger_topic' => $webhookEnabled ? $triggerTopic : null,
                'interval_minutes' => $scheduleEnabled ? $intervalMinutes : null,
            ]);

            header('Location: mapping_edit.php?id=' . $pdo->lastInsertId() . '&shop=' . urlencode($shop));
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_mapping') {
    $mappingId = (int) ($_POST['mapping_id'] ?? 0);

    $ownershipCheck = $pdo->prepare('SELECT enabled, name FROM connect_sync_mappings WHERE id = :id AND shop = :shop AND target_connection_id IS NOT NULL');
    $ownershipCheck->execute(['id' => $mappingId, 'shop' => $shop]);
    $mapping = $ownershipCheck->fetch();

    if ($mapping === false) {
        $flashMessage = 'Aktivierung nicht gefunden.';
        $flashType = 'danger';
    } else {
        $newEnabled = ((int) $mapping['enabled']) === 1 ? 0 : 1;
        $pdo->prepare('UPDATE connect_sync_mappings SET enabled = :enabled, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['enabled' => $newEnabled, 'id' => $mappingId]);

        $flashMessage = "\"{$mapping['name']}\" ist jetzt " . ($newEnabled === 1 ? 'aktiv' : 'inaktiv') . '.';
        $flashType = 'success';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_mapping') {
    $mappingId = (int) ($_POST['mapping_id'] ?? 0);

    $pdo->prepare('DELETE FROM connect_sync_mappings WHERE id = :id AND shop = :shop AND target_connection_id IS NOT NULL')
        ->execute(['id' => $mappingId, 'shop' => $shop]);

    $flashMessage = 'Aktivierung gelöscht.';
    $flashType = 'success';
}

// -----------------------------------------------------------------------
// Daten fuer die Anzeige laden
// -----------------------------------------------------------------------
$mappingsStmt = $pdo->prepare(
    'SELECT m.*, sc.label AS source_connection_label, tc.label AS target_connection_label,
            mt.name AS template_name,
            (SELECT r.status FROM connect_sync_runs r WHERE r.sync_mapping_id = m.id ORDER BY r.id DESC LIMIT 1) AS last_run_status,
            (SELECT r.started_at FROM connect_sync_runs r WHERE r.sync_mapping_id = m.id ORDER BY r.id DESC LIMIT 1) AS last_run_started_at
     FROM connect_sync_mappings m
     JOIN connect_connections sc ON sc.id = m.source_connection_id
     JOIN connect_connections tc ON tc.id = m.target_connection_id
     LEFT JOIN connect_mapping_templates mt ON mt.id = m.mapping_template_id
     WHERE m.shop = :shop AND m.target_connection_id IS NOT NULL
     ORDER BY m.id DESC'
);
$mappingsStmt->execute(['shop' => $shop]);
$mappings = $mappingsStmt->fetchAll();

// Fuer die "Vorlage aktivieren"-Auswahl: NUR Vorlagen mit einer "Anlegen"-
// Operation am Ziel (siehe Dateikommentar) -- alles andere kann ohnehin nie
// automatisch laufen, würde hier also nur eine nutzlose Aktivierung erzeugen.
$templates = $pdo->query(
    "SELECT t.id, t.name, t.source_connector_type_id, t.target_connector_type_id,
            sct.name AS source_type_name, tct.name AS target_type_name
     FROM connect_mapping_templates t
     JOIN connect_connector_types sct ON sct.id = t.source_connector_type_id
     JOIN connect_connector_types tct ON tct.id = t.target_connector_type_id
     WHERE (SELECT COUNT(*) FROM connect_object_operations op WHERE op.object_id = t.target_object_id AND op.operation_type = 'create') > 0
     ORDER BY t.name"
)->fetchAll();

// -----------------------------------------------------------------------
// ALLE fuer diesen Shop relevanten Mapping-Vorlagen (beide Richtungen, beide
// Nutzungsarten, siehe Dateikommentar) -- "relevant" heisst: Quell- UND
// Ziel-Connector-Type haben je mindestens eine Connection dieses Shops.
//
// is_direct_product_import entscheidet die Nutzungsart -- BEWUSST NICHT
// (mehr) ueber "hat das Ziel eine Anlegen-Operation": das passte zufaellig
// fuer die Shopify-Richtung (Shopify "product" hat keine schlichte
// "create"-Operation, nur die feste Zwei-Schritt-Sequenz), war aber falsch
// fuer die andere Richtung (Ziel-System MIT "create"-Operation, z.B.
// HelloCash "Artikel" -- wurde faelschlich als "muss aktiviert werden"
// angezeigt, obwohl Connect\Sync\ProductImportService::importIntoTarget()
// sie genauso direkt ohne Aktivierung nutzt). Das tatsaechlich
// entscheidende Kriterium: ist Shopify "product" auf der Quell- ODER der
// Ziel-Seite beteiligt -- GENAU diese beiden Faelle deckt
// ProductImportService ab (importIntoShopify()/importIntoTarget()),
// unabhaengig davon, ob die jeweils andere Seite eine "create"-Operation hat.
//
// target_has_create_operation bleibt trotzdem noetig -- fuer alle NICHT
// direkt genutzten Vorlagen (z.B. eine kuenftige "Shopify-Bestellung ->
// MGM-Auftrag"), um zu wissen, ob sie ueberhaupt aktiviert werden KANN.
// activation_count sagt, ob/wie oft sie fuer DIESEN Shop bereits aktiviert
// ist (siehe "Aktivierte Mapping-Vorlagen" unten).
// -----------------------------------------------------------------------
$relevantTemplatesStmt = $pdo->prepare(
    "SELECT t.id, t.name,
            sct.name AS source_type_name, so.label AS source_object_label,
            tct.name AS target_type_name, tobj.label AS target_object_label,
            (SELECT COUNT(*) FROM connect_field_mappings fm WHERE fm.mapping_template_id = t.id) AS field_count,
            (SELECT COUNT(*) FROM connect_sync_mappings m WHERE m.mapping_template_id = t.id AND m.shop = :shop1) AS activation_count,
            (SELECT COUNT(*) FROM connect_object_operations op WHERE op.object_id = t.target_object_id AND op.operation_type = 'create') > 0 AS target_has_create_operation,
            ((sct.type_key = 'shopify' AND so.object_key = 'product') OR (tct.type_key = 'shopify' AND tobj.object_key = 'product')) AS is_direct_product_import,
            (SELECT f.id FROM connect_functions f WHERE f.mapping_template_id = t.id LIMIT 1) AS function_id,
            (SELECT f.function_kind FROM connect_functions f WHERE f.mapping_template_id = t.id LIMIT 1) AS function_kind,
            (SELECT f.label FROM connect_functions f WHERE f.mapping_template_id = t.id LIMIT 1) AS function_label
     FROM connect_mapping_templates t
     JOIN connect_objects so ON so.id = t.source_object_id
     JOIN connect_objects tobj ON tobj.id = t.target_object_id
     JOIN connect_connector_types sct ON sct.id = t.source_connector_type_id
     JOIN connect_connector_types tct ON tct.id = t.target_connector_type_id
     WHERE t.source_connector_type_id IN (SELECT connector_type_id FROM connect_connections WHERE shop = :shop2)
       AND t.target_connector_type_id IN (SELECT connector_type_id FROM connect_connections WHERE shop = :shop3)
     ORDER BY t.name"
);
$relevantTemplatesStmt->execute(['shop1' => $shop, 'shop2' => $shop, 'shop3' => $shop]);
$relevantTemplates = $relevantTemplatesStmt->fetchAll();

$connectionsStmt = $pdo->prepare(
    'SELECT c.id, c.label, c.connector_type_id, t.name AS type_name
     FROM connect_connections c
     JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE c.shop = :shop
     ORDER BY c.label'
);
$connectionsStmt->execute(['shop' => $shop]);
$connections = $connectionsStmt->fetchAll();

$statusBadge = [
    'success' => 'bg-green-lt',
    'error' => 'bg-red-lt',
    'running' => 'bg-yellow-lt',
    'partial' => 'bg-yellow-lt',
];

$pageTitle = 'Mappings — ' . $shop;
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
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Mapping-Vorlagen für diesen Shop</h3>
            </div>
            <p class="text-secondary px-3 pt-2">
                Alle Mapping-Vorlagen, die zu den Systemen dieses Shops passen (beide Richtungen) — unabhängig davon,
                ob sie automatisch laufen oder nur bei einem gezielten Klick (z. B. "Importieren" auf der
                Produkte-Seite) direkt verwendet werden.
            </p>
            <?php if (count($relevantTemplates) === 0): ?>
                <div class="card-body text-secondary">
                    Keine passende <a href="mapping_templates.php">Mapping-Vorlage</a> für die Systeme dieses Shops gefunden.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Vorlage</th>
                                <th>Quelle</th>
                                <th>Ziel</th>
                                <th>Felder</th>
                                <th>Nutzung</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($relevantTemplates as $relevantTemplate): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($relevantTemplate['name']); ?></td>
                                    <td><?php echo htmlspecialchars($relevantTemplate['source_type_name'] . ' — ' . $relevantTemplate['source_object_label']); ?></td>
                                    <td><?php echo htmlspecialchars($relevantTemplate['target_type_name'] . ' — ' . $relevantTemplate['target_object_label']); ?></td>
                                    <td>
                                        <?php if ((int) $relevantTemplate['field_count'] === 0): ?>
                                            <span class="badge bg-red-lt">keine</span>
                                        <?php else: ?>
                                            <?php echo (int) $relevantTemplate['field_count']; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-secondary">
                                        <?php if ($relevantTemplate['is_direct_product_import']): ?>
                                            <span class="badge bg-blue-lt">Direkt bei Bedarf</span>
                                            <div>Kein Aktivierungsschritt nötig.</div>
                                            <?php if ($relevantTemplate['function_id'] !== null): ?>
                                                <div>Funktion: <a href="functions.php"><?php echo htmlspecialchars($relevantTemplate['function_label'] . ' (' . $relevantTemplate['function_kind'] . ')'); ?></a></div>
                                            <?php else: ?>
                                                <div>Noch keine Funktion hinterlegt — es gilt die zuletzt angelegte Vorlage.
                                                    <a href="functions.php?mapping_template_id=<?php echo (int) $relevantTemplate['id']; ?>">Als Funktion festlegen</a>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif (!$relevantTemplate['target_has_create_operation']): ?>
                                            <span class="badge bg-red-lt">Nicht nutzbar</span>
                                            <div>Ziel hat keine "Anlegen"-Operation.</div>
                                        <?php elseif ((int) $relevantTemplate['activation_count'] > 0): ?>
                                            <span class="badge bg-green-lt">Automatisch aktiv</span>
                                            <div>Siehe "Aktivierte Mapping-Vorlagen" unten.</div>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-lt">Nicht aktiviert</span>
                                            <div>Kann unten aktiviert werden, für automatische Ausführung.</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="mapping_template_edit.php?id=<?php echo (int) $relevantTemplate['id']; ?>" class="btn btn-sm btn-outline-primary">Felder bearbeiten</a>
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
                <h3 class="card-title">Aktivierte Mapping-Vorlagen</h3>
            </div>
            <p class="text-secondary px-3 pt-2">
                Übersetzt Datensätze aus einem System in ein anderes und schreibt sie dort neu an (z. B. eine bezahlte
                Shopify-Bestellung als Auftrag in MGM) — im Unterschied zu "Jobs", das nur den eigenen Datenbestand
                eines Systems aktuell hält. Die Feld-Zuordnungen selbst werden in den
                <a href="mapping_templates.php">Mapping-Vorlagen</a> gepflegt (gelten für alle Shops), hier wird nur
                festgelegt, MIT WELCHEN Connections dieses Shops eine Vorlage läuft.
            </p>
            <?php if (count($mappings) === 0): ?>
                <div class="card-body text-secondary">Noch keine Vorlage für diesen Shop aktiviert.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Vorlage</th>
                                <th>Quelle</th>
                                <th>Ziel</th>
                                <th>Auslöser</th>
                                <th>Aktiv</th>
                                <th>Letzter Lauf</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mappings as $mapping): ?>
                                <tr>
                                    <td>
                                        <?php if ($mapping['template_name'] !== null): ?>
                                            <?php echo htmlspecialchars($mapping['template_name']); ?>
                                        <?php else: ?>
                                            <span class="text-secondary" title="Vorlage wurde gelöscht">(keine Vorlage mehr)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($mapping['source_connection_label']); ?></td>
                                    <td><?php echo htmlspecialchars($mapping['target_connection_label']); ?></td>
                                    <td class="text-secondary">
                                        <?php if ($mapping['trigger_topic'] !== null): ?>
                                            <div>Webhook (<?php echo htmlspecialchars((string) $mapping['trigger_topic']); ?>)</div>
                                        <?php endif; ?>
                                        <?php if ($mapping['schedule_interval_minutes'] !== null): ?>
                                            <div>Zeitplan (<?php echo (int) $mapping['schedule_interval_minutes']; ?> Min.)</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo ((int) $mapping['enabled']) === 1 ? 'bg-green-lt' : 'bg-secondary-lt'; ?>">
                                            <?php echo ((int) $mapping['enabled']) === 1 ? 'aktiv' : 'inaktiv'; ?>
                                        </span>
                                    </td>
                                    <td class="text-secondary">
                                        <?php if ($mapping['last_run_started_at'] !== null): ?>
                                            <span class="badge <?php echo htmlspecialchars($statusBadge[$mapping['last_run_status']] ?? 'bg-secondary-lt'); ?>">
                                                <?php echo htmlspecialchars($mapping['last_run_status']); ?>
                                            </span>
                                            <?php echo htmlspecialchars($mapping['last_run_started_at']); ?>
                                        <?php else: ?>
                                            noch nie gelaufen
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="mapping_edit.php?id=<?php echo (int) $mapping['id']; ?>&shop=<?php echo urlencode($shop); ?>" class="btn btn-sm btn-outline-primary">Details / Testen</a>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="toggle_mapping">
                                            <input type="hidden" name="mapping_id" value="<?php echo (int) $mapping['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                <?php echo ((int) $mapping['enabled']) === 1 ? 'Deaktivieren' : 'Aktivieren'; ?>
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Aktivierung wirklich löschen?');">
                                            <input type="hidden" name="action" value="delete_mapping">
                                            <input type="hidden" name="mapping_id" value="<?php echo (int) $mapping['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Entfernen</button>
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

    <?php if (count($templates) === 0): ?>
        <div class="col-12">
            <div class="alert alert-info">
                Es gibt noch keine <a href="mapping_templates.php">Mapping-Vorlage</a> — zuerst dort eine anlegen.
            </div>
        </div>
    <?php elseif (count($connections) === 0): ?>
        <div class="col-12">
            <div class="alert alert-info">
                Es gibt noch keine Connection für diesen Shop — zuerst unter "Connections" eine anlegen.
            </div>
        </div>
    <?php else: ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Vorlage für diesen Shop aktivieren</h3>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="create_activation">
                        <div class="col-md-4">
                            <label class="form-label">Mapping-Vorlage</label>
                            <select name="mapping_template_id" id="mapping-template-select" class="form-select" required onchange="mappingToggleTrigger()">
                                <option value="">-- auswählen --</option>
                                <?php foreach ($templates as $template): ?>
                                    <option value="<?php echo (int) $template['id']; ?>">
                                        <?php echo htmlspecialchars($template['name'] . ' (' . $template['source_type_name'] . ' → ' . $template['target_type_name'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quelle (welche Connection dieses Shops?)</label>
                            <select name="source_connection_id" class="form-select" required>
                                <option value="">-- auswählen --</option>
                                <?php foreach ($connections as $connection): ?>
                                    <option value="<?php echo (int) $connection['id']; ?>"><?php echo htmlspecialchars($connection['label'] . ' (' . $connection['type_name'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ziel (welche Connection dieses Shops?)</label>
                            <select name="target_connection_id" class="form-select" required>
                                <option value="">-- auswählen --</option>
                                <?php foreach ($connections as $connection): ?>
                                    <option value="<?php echo (int) $connection['id']; ?>"><?php echo htmlspecialchars($connection['label'] . ' (' . $connection['type_name'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <small class="text-secondary d-block mt-1">
                            Quelle und Ziel müssen zum jeweils in der Vorlage erwarteten System passen (z. B. Quelle = eine
                            Shopify-Connection, Ziel = eine MGM-Connection) — wird beim Speichern geprüft.
                        </small>

                        <div class="col-12 mt-3" id="mapping-trigger-section">
                            <label class="form-label">Auslöser (beide gleichzeitig möglich)</label>
                            <div class="row g-2">
                                <div class="col-auto">
                                    <label class="form-check">
                                        <input type="checkbox" name="webhook_enabled" id="mapping-webhook-checkbox" class="form-check-input" onchange="mappingToggleTrigger()">
                                        <span class="form-check-label">Webhook (sofort)</span>
                                    </label>
                                </div>
                                <div class="col-auto" id="mapping-trigger-webhook" style="display:none">
                                    <select name="trigger_topic" class="form-select">
                                        <option value="orders/paid">Bestellung bezahlt (orders/paid)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row g-2 mt-1">
                                <div class="col-auto">
                                    <label class="form-check">
                                        <input type="checkbox" name="schedule_enabled" id="mapping-schedule-checkbox" class="form-check-input" checked onchange="mappingToggleTrigger()">
                                        <span class="form-check-label">Zeitplan-Nachlauf</span>
                                    </label>
                                </div>
                                <div class="col-auto" id="mapping-trigger-schedule">
                                    <input type="number" name="schedule_interval_minutes" class="form-control" value="15" min="1" style="width:100px">
                                    <small class="text-secondary">Minuten</small>
                                </div>
                            </div>
                            <small class="text-secondary d-block mt-1">
                                <strong>Webhook</strong>: übersetzt sofort bei Eintreffen — aktuell nur für Vorlagen mit Quelle
                                "Bestellung" + Thema "Bestellung bezahlt" verdrahtet.
                                <strong>Zeitplan-Nachlauf</strong>: prüft regelmäßig zuletzt gecachte Datensätze und übersetzt
                                noch nicht übersetzte nach — sinnvoll als Sicherheitsnetz für vom Webhook verpasste oder
                                fehlgeschlagene Fälle, kann aber auch alleine (ohne Webhook) verwendet werden.
                            </small>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Aktivieren</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    // Vereinfacht: die Vorlagen-Auswahl enthaelt jetzt nur noch Vorlagen mit
    // "Anlegen"-Operation am Ziel (siehe PHP-Query oben), Auslöser sind also
    // immer moeglich -- hier nur noch das Ein-/Ausblenden der jeweiligen
    // Auslöser-Detailfelder, kein hasCreate-Sonderfall mehr noetig.
    function mappingToggleTrigger() {
        var webhookCheckbox = document.getElementById('mapping-webhook-checkbox');
        var scheduleCheckbox = document.getElementById('mapping-schedule-checkbox');

        document.getElementById('mapping-trigger-webhook').style.display = webhookCheckbox.checked ? 'block' : 'none';
        document.getElementById('mapping-trigger-schedule').style.display = scheduleCheckbox.checked ? 'block' : 'none';
    }
    mappingToggleTrigger();
</script>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>