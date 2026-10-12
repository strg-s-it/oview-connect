<?php

declare(strict_types=1);

/**
 * connect/public/admin/mapping_edit.php
 *
 * Detail einer Aktivierung (siehe mappings.php): zeigt, welche Vorlage mit
 * welchen Connections dieses Shops läuft (Feld-Zuordnungen selbst liegen in
 * der Vorlage, siehe mapping_template_edit.php -- Link von hier aus), plus
 * die beiden Testfunktionen. WICHTIG bevor eine Aktivierung scharf geschaltet
 * wird (siehe Plan "Mapping Engine v1"): "Vorschau" ruft nur MappingEngine
 * auf (kein Schreiben), "Einmal wirklich ausführen" schreibt tatsaechlich
 * ins Zielsystem (z.B. legt einen echten MGM-Auftrag an) -- beide gegen
 * einen echten, zuletzt gecachten Quell-Datensatz dieser Connection.
 */

use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Mapping\MappingEngine;
use Connect\Shopify\TokenRefresher;
use Connect\Sync\SyncRunner;

/**
 * Formatiert einen XML-String mit sauberer Einrueckung, rein fuer die
 * Lesbarkeit in der "Vorschau" -- die tatsaechlich an MGM gesendeten Bytes
 * bleiben unveraendert (das passiert erst bei "Einmal wirklich ausführen"
 * ueber denselben buildWriteBody()-Code, ohne diesen Formatierungsschritt).
 * Bei einem JSON-Ziel ist $input kein gueltiges XML -- dann unveraendert
 * zurueckgeben, kein Fehler.
 */
function connect_pretty_print_xml(string $input): string
{
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;

    // Das MGM-body_template (siehe Migration 0002) beginnt mit einer eigenen
    // XML-Deklaration ganz am Anfang, aber OHNE Encoding-Angabe darin. Ohne
    // explizites encoding="UTF-8" weiss DOMDocument beim Speichern nicht
    // sicher, dass die Bytes schon UTF-8 sind, und schreibt Umlaute dann als
    // &#xFC;-Entities statt roher UTF-8-Zeichen -- nur ein Anzeigeproblem
    // dieser Vorschau (die echten, an MGM gesendeten Bytes laufen nie durch
    // DOMDocument, siehe Kommentar oben). Eine vorhandene Deklaration wird
    // deshalb entfernt und immer durch eine mit explizitem UTF-8 ersetzt.
    $withoutDeclaration = preg_replace('/^\s*<\?xml[^>]*\?>\s*/', '', $input, 1) ?? $input;
    $xmlForParsing = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . $withoutDeclaration;

    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($xmlForParsing);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return $input;
    }

    return $dom->saveXML() ?: $input;
}

/**
 * Textausgabe der Beleg-Vorschau (Connect\Sync\Document\DocumentPipeline::preview()).
 *
 * @param array<string, mixed> $preview
 */
function connect_document_preview_text(array $preview, string $sourceExternalId): string
{
    $orderNumber = (string) ($preview['record']['order_number'] ?? '');
    $text = "Vorschau (NICHT geschrieben) für " . ($orderNumber !== '' ? "Bestellung {$orderNumber}, " : '') . "Quell-ID \"{$sourceExternalId}\"\n\n";
    if ($preview['skipped'] !== null) {
        $text .= "ÜBERSPRUNGEN: {$preview['skipped']}\n\n";
    }
    if ($preview['error'] !== null) {
        $text .= "WÜRDE ABBRECHEN: {$preview['error']}\n\n";
    }
    if ($preview['notes'] !== []) {
        $text .= "Hinweise:\n- " . implode("\n- ", $preview['notes']) . "\n\n";
    }
    if ($preview['body'] !== null) {
        $text .= "So würde die Anfrage ans Zielsystem aussehen:\n\n" . connect_pretty_print_xml($preview['body']) . "\n\n";
    }
    if ($preview['record'] !== null) {
        $text .= "---\nBestellung (aufbereitet) -- die Quellfelder der Vorlage:\n"
            . json_encode($preview['record'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    return $text;
}

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$pdo = $app->pdo;
$shop = $app->shop;

$mappingId = (int) ($_GET['id'] ?? 0);

// Die Rechnungs-Aktivierung (mapping_kind=invoice) hat kein eigenes Ziel-Object --
// dann gilt das Ziel-Object ihrer Vorlage (falls vorhanden), daher COALESCE + LEFT JOIN.
$mappingStmt = $pdo->prepare(
    'SELECT m.*, sc.label AS source_connection_label, so.object_key AS source_object_key, so.label AS source_object_label,
            tc.label AS target_connection_label, tobj.object_key AS target_object_key, tobj.label AS target_object_label,
            tobj.settings_schema AS target_settings_schema, mt.name AS template_name,
            tc.connector_type_id AS target_connector_type_id
     FROM connect_sync_mappings m
     JOIN connect_connections sc ON sc.id = m.source_connection_id
     JOIN connect_objects so ON so.id = m.source_object_id
     JOIN connect_connections tc ON tc.id = m.target_connection_id
     LEFT JOIN connect_mapping_templates mt ON mt.id = m.mapping_template_id
     LEFT JOIN connect_objects tobj ON tobj.id = COALESCE(m.target_object_id, mt.target_object_id)
     WHERE m.id = :id AND m.shop = :shop AND m.target_connection_id IS NOT NULL'
);
$mappingStmt->execute(['id' => $mappingId, 'shop' => $shop]);
$mapping = $mappingStmt->fetch();

if ($mapping === false) {
    \Connect\Http\ErrorPage::show(
        404,
        'Aktivierung nicht gefunden',
        'Dieser Eintrag existiert nicht (mehr) oder wurde gelöscht.',
        ['label' => 'Zu den Aktivierungen', 'url' => 'mappings.php?shop=' . urlencode((string) ($_GET['shop'] ?? ''))]
    );
}

$flashMessage = null;
$flashType = 'info';
$testOutput = null;

$buildFactory = function () use ($pdo, $app): ConnectorFactory {
    $tokenRefresher = TokenRefresher::fromConfig($app->config);

    return new ConnectorFactory($pdo, $app->crypto, new HttpClient(), $tokenRefresher);
};

// -----------------------------------------------------------------------
// POST: Aktivierungs-Einstellungen speichern (siehe connect_objects.
// settings_schema des Ziel-Objects) -- Werte wie HelloCash cashier_id, die
// pro SHOP unterschiedlich sein koennen, anders als die Feld-Zuordnungen
// selbst, die aus der geteilten Mapping-Vorlage kommen (siehe
// MappingEngine::translate()).
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_activation_settings') {
    $settingsSchema = $mapping['target_settings_schema'] !== null ? (json_decode((string) $mapping['target_settings_schema'], true) ?: []) : [];
    $newSettings = [];
    foreach ($settingsSchema as $field) {
        $key = (string) ($field['key'] ?? '');
        if ($key === '') {
            continue;
        }
        if (($field['type'] ?? 'text') === 'checkbox') {
            $newSettings[$key] = isset($_POST['settings'][$key]);
        } elseif (($field['type'] ?? 'text') === 'number') {
            $newSettings[$key] = ((string) ($_POST['settings'][$key] ?? '')) !== '' ? (float) $_POST['settings'][$key] : null;
        } else {
            $newSettings[$key] = (string) ($_POST['settings'][$key] ?? '');
        }
    }
    // Nachlauf-Start (settings.backfill_since, gesetzt beim Einschalten) nicht verlieren
    $oldSettings = $mapping['settings'] !== null ? (json_decode((string) $mapping['settings'], true) ?: []) : [];
    if (isset($oldSettings['backfill_since'])) {
        $newSettings['backfill_since'] = $oldSettings['backfill_since'];
    }

    $pdo->prepare('UPDATE connect_sync_mappings SET settings = :settings, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND shop = :shop')
        ->execute(['settings' => json_encode($newSettings, JSON_UNESCAPED_UNICODE), 'id' => $mappingId, 'shop' => $shop]);

    $flashMessage = 'Einstellungen gespeichert.';
    $flashType = 'success';
    $mappingStmt->execute(['id' => $mappingId, 'shop' => $shop]);
    $mapping = $mappingStmt->fetch();
}

// -----------------------------------------------------------------------
// POST: Vorlage dieser Aktivierung waehlen -- vor allem fuer die Rechnungs-
// Aktivierung (mapping_kind=invoice): mit einer Vorlage "Bestellung
// (aufbereitet) -> Rechnung" laeuft die Rechnung ueber die Oberflaeche
// (Connect\Sync\Document\DocumentPipeline), ohne Vorlage wie bisher ueber den
// fest programmierten InvoiceService (Rueckfallweg).
// -----------------------------------------------------------------------
$isInvoiceActivation = ($mapping['mapping_kind'] ?? 'default') === 'invoice';
$templateOptionsStmt = $pdo->prepare(
    "SELECT t.id, t.name, t.target_object_id,
            (sct.type_key = 'connect_internal' AND so.object_key = 'order_prepared') AS uses_prepared_order
     FROM connect_mapping_templates t
     JOIN connect_objects so ON so.id = t.source_object_id
     JOIN connect_connector_types sct ON sct.id = so.connector_type_id
     WHERE t.target_connector_type_id = :target_type_id
       AND (t.source_object_id = :source_object_id OR (sct.type_key = 'connect_internal' AND so.object_key = 'order_prepared'))
     ORDER BY t.name"
);
$templateOptionsStmt->execute(['target_type_id' => $mapping['target_connector_type_id'], 'source_object_id' => $mapping['source_object_id']]);
$templateOptions = $templateOptionsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_template') {
    $newTemplateId = ((string) ($_POST['mapping_template_id'] ?? '')) !== '' ? (int) $_POST['mapping_template_id'] : null;
    $allowedIds = array_map(static fn (array $t): int => (int) $t['id'], $templateOptions);

    if ($newTemplateId === null && !$isInvoiceActivation) {
        $flashMessage = 'Diese Aktivierung braucht eine Vorlage.';
        $flashType = 'danger';
    } elseif ($newTemplateId !== null && !in_array($newTemplateId, $allowedIds, true)) {
        $flashMessage = 'Diese Vorlage passt nicht zu dieser Aktivierung.';
        $flashType = 'danger';
    } else {
        $pdo->prepare('UPDATE connect_sync_mappings SET mapping_template_id = :template_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND shop = :shop')
            ->execute(['template_id' => $newTemplateId, 'id' => $mappingId, 'shop' => $shop]);
        $flashMessage = $newTemplateId !== null
            ? 'Vorlage zugeordnet.'
            : 'Vorlage entfernt — die Rechnung läuft wieder über die bisherige, fest programmierte HelloCash-Logik.';
        $flashType = 'success';
        $mappingStmt->execute(['id' => $mappingId, 'shop' => $shop]);
        $mapping = $mappingStmt->fetch();
    }
}

// -----------------------------------------------------------------------
// POST: Ausloeser einer bestehenden Aktivierung aendern (bisher nur beim
// Anlegen in admin/mappings.php waehlbar). Webhook = sofort bei "Bestellung
// bezahlt" (webhooks/order_paid.php sucht trigger_type 'webhook' + Topic),
// Zeitplan = Nachlauf (bin/scheduler.php, schedule_interval_minutes).
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_triggers') {
    $webhookEnabled = isset($_POST['webhook_enabled']);
    $scheduleEnabled = isset($_POST['schedule_enabled']);
    $intervalMinutes = (int) ($_POST['schedule_interval_minutes'] ?? 0);

    if (!$webhookEnabled && !$scheduleEnabled) {
        $flashMessage = 'Bitte mindestens einen Auslöser (Webhook und/oder Zeitplan) aktivieren.';
        $flashType = 'danger';
    } elseif ($scheduleEnabled && $intervalMinutes <= 0) {
        $flashMessage = 'Bitte ein Intervall in Minuten (größer 0) angeben.';
        $flashType = 'danger';
    } elseif ($webhookEnabled && $mapping['source_object_key'] !== 'order') {
        $flashMessage = 'Webhook-Auslöser ist aktuell nur für Aktivierungen mit Quelle "Bestellung" verfügbar.';
        $flashType = 'danger';
    } else {
        $pdo->prepare(
            'UPDATE connect_sync_mappings
             SET trigger_type = :trigger_type, trigger_topic = :trigger_topic, schedule_interval_minutes = :interval_minutes, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND shop = :shop'
        )->execute([
            'trigger_type' => $webhookEnabled ? 'webhook' : 'schedule',
            'trigger_topic' => $webhookEnabled ? 'orders/paid' : null,
            'interval_minutes' => $scheduleEnabled ? $intervalMinutes : null,
            'id' => $mappingId,
            'shop' => $shop,
        ]);
        $flashMessage = 'Auslöser gespeichert.';
        $flashType = 'success';
        $mappingStmt->execute(['id' => $mappingId, 'shop' => $shop]);
        $mapping = $mappingStmt->fetch();
    }
}

$usesDocumentPipeline = $mapping['mapping_template_id'] !== null
    && \Connect\Sync\Document\DocumentPipeline::templateUsesPreparedOrder($pdo, (int) $mapping['mapping_template_id']);

// -----------------------------------------------------------------------
// POST: Testen -- Vorschau (kein Schreiben) oder scharfer Einzel-Lauf.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['preview_mapping', 'run_mapping_once'], true)) {
    $sourceExternalId = (string) ($_POST['sample_external_id'] ?? '');

    $sampleStmt = $pdo->prepare(
        'SELECT data FROM connect_object_records WHERE connection_id = :connection_id AND object_key = :object_key AND external_id = :external_id'
    );
    $sampleStmt->execute([
        'connection_id' => $mapping['source_connection_id'],
        'object_key' => $mapping['source_object_key'],
        'external_id' => $sourceExternalId,
    ]);
    $sampleData = $sampleStmt->fetchColumn();

    if ($sampleData === false) {
        $flashMessage = 'Bitte einen vorhandenen Beispiel-Datensatz auswählen.';
        $flashType = 'danger';
    } elseif (($_POST['action'] ?? '') === 'preview_mapping' && ((string) ($_POST['preview_template_id'] ?? '')) !== '') {
        // Vorschau mit einer NICHT zugeordneten Beleg-Vorlage -- zum Pruefen,
        // BEVOR sie zugeordnet (und damit live) wird.
        $previewTemplateId = (int) $_POST['preview_template_id'];
        $allowedPreviewIds = array_map(static fn (array $t): int => (int) $t['id'], array_filter($templateOptions, static fn (array $t): bool => (int) $t['uses_prepared_order'] === 1));
        if (!in_array($previewTemplateId, $allowedPreviewIds, true)) {
            $flashMessage = 'Diese Vorlage passt nicht zu dieser Aktivierung.';
            $flashType = 'danger';
        } else {
            try {
                $previewMapping = array_merge($mapping, ['mapping_template_id' => $previewTemplateId]);
                $preview = (new \Connect\Sync\Document\DocumentPipeline($pdo, $buildFactory()))->preview($previewMapping, json_decode((string) $sampleData, true));
                $testOutput = connect_document_preview_text($preview, $sourceExternalId);
                $flashMessage = $preview['error'] === null ? 'Vorschau erzeugt (Vorlage NICHT zugeordnet).' : 'Vorschau erzeugt — die Kontrollen würden den Beleg verhindern.';
                $flashType = $preview['error'] === null ? 'success' : 'warning';
            } catch (\Throwable $e) {
                $flashMessage = 'Testen fehlgeschlagen: ' . $e->getMessage();
                $flashType = 'danger';
            }
        }
    } elseif ($mapping['mapping_template_id'] === null && !$isInvoiceActivation) {
        $flashMessage = 'Diese Aktivierung hat keine Vorlage mehr (gelöscht?) — Testen nicht möglich.';
        $flashType = 'danger';
    } elseif ($mapping['mapping_template_id'] === null && ($_POST['action'] ?? '') === 'preview_mapping') {
        $flashMessage = 'Ohne Vorlage läuft die Rechnung über die fest programmierte HelloCash-Logik — dafür gibt es keine Vorschau. Bitte zuerst eine Vorlage zuordnen.';
        $flashType = 'warning';
    } else {
        $sourceRow = json_decode((string) $sampleData, true);

        try {
            $factory = $buildFactory();

            if (($_POST['action'] ?? '') === 'preview_mapping' && $usesDocumentPipeline) {
                // Belegvorlage: Aufbereitung + Kontrollen + Mapping, ohne zu schreiben
                // (ein Kunde wird nur gesucht, nie angelegt).
                $preview = (new \Connect\Sync\Document\DocumentPipeline($pdo, $factory))->preview($mapping, $sourceRow);
                $testOutput = connect_document_preview_text($preview, $sourceExternalId);
                $flashMessage = $preview['error'] === null ? 'Vorschau erzeugt.' : 'Vorschau erzeugt — die Kontrollen würden den Beleg verhindern.';
                $flashType = $preview['error'] === null ? 'success' : 'warning';
            } elseif (($_POST['action'] ?? '') === 'preview_mapping') {
                $sourceFieldsById = $factory->fieldsByIdForObject((int) $mapping['source_object_id']);
                $targetFieldsById = $factory->fieldsByIdForObject((int) $mapping['target_object_id']);

                $fieldMappingsStmt = $pdo->prepare('SELECT * FROM connect_field_mappings WHERE mapping_template_id = :id ORDER BY sort_order, id');
                $fieldMappingsStmt->execute(['id' => $mapping['mapping_template_id']]);
                $fieldMappingRows = $fieldMappingsStmt->fetchAll();

                $activationSettings = $mapping['settings'] !== null ? (json_decode((string) $mapping['settings'], true) ?: []) : [];
                $data = (new MappingEngine($pdo))->translate($sourceRow, $fieldMappingRows, $sourceFieldsById, $targetFieldsById, $activationSettings);

                // Zeigt das ECHTE Zielformat (z.B. das fertig gerenderte MGM-XML),
                // nicht nur das generische JSON-Zwischenformat der Mapping Engine
                // -- gebaut ueber denselben Code-Pfad wie ein echtes Schreiben
                // (GenericHttpConnector::buildWriteBodyForPreview()), nur ohne
                // die Anfrage abzuschicken.
                $targetConnector = $factory->forConnection((int) $mapping['target_connection_id']);
                $targetObject = $factory->objectDefinitionById((int) $mapping['target_object_id']);
                $renderedBody = connect_pretty_print_xml($targetConnector->buildWriteBodyForPreview($targetObject->key, 'create', $data));

                $testOutput = "Vorschau (NICHT geschrieben) für Quell-ID \"{$sourceExternalId}\" -- so würde die Anfrage ans Zielsystem aussehen:\n\n"
                    . $renderedBody
                    . "\n\n---\nZur Kontrolle, welches Feld welchen Wert liefert (Mapping-Engine-Zwischenformat):\n"
                    . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $flashMessage = 'Vorschau erzeugt.';
                $flashType = 'success';
            } else {
                $runner = new SyncRunner($pdo, $factory);
                $runner->runTranslationForRecord($mapping, $sourceRow);
                $flashMessage = "Einzel-Lauf für Quell-ID \"{$sourceExternalId}\" ausgeführt — Details in Jobs → Laufhistorie.";
                $flashType = 'success';
            }
        } catch (\Throwable $e) {
            $flashMessage = 'Testen fehlgeschlagen: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

// Die Einfuege-Reihenfolge (id) sagt nichts ueber das Alter aus: ein Listen-
// Abruf mit "reverse: true" fuegt die NEUESTE Bestellung zuerst ein. Deshalb
// mehr Datensaetze holen und in PHP nach created_at sortieren (MariaDB 5.x
// kennt kein JSON_EXTRACT); ohne created_at bleibt die id-Reihenfolge.
$sampleRecordsStmt = $pdo->prepare(
    'SELECT external_id, data FROM connect_object_records
     WHERE connection_id = :connection_id AND object_key = :object_key
     ORDER BY id DESC LIMIT 500'
);
$sampleRecordsStmt->execute(['connection_id' => $mapping['source_connection_id'], 'object_key' => $mapping['source_object_key']]);
$sampleRecords = [];
foreach ($sampleRecordsStmt->fetchAll() as $position => $record) {
    $decoded = json_decode((string) $record['data'], true);
    $label = is_array($decoded) ? (string) ($decoded['order_number'] ?? $decoded['name'] ?? $record['external_id']) : $record['external_id'];
    $sortKey = is_array($decoded) ? (string) ($decoded['created_at'] ?? '') : '';
    $sampleRecords[] = ['external_id' => $record['external_id'], 'label' => $label, 'sort_key' => $sortKey, 'position' => $position];
}
usort($sampleRecords, static fn (array $a, array $b): int => [$b['sort_key'], $a['position']] <=> [$a['sort_key'], $b['position']]);
$sampleRecords = array_slice($sampleRecords, 0, 50);

$pageTitle = 'Aktivierung: ' . ($mapping['template_name'] ?? $mapping['name']);
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = require dirname(__DIR__) . '/app/_shop_nav.php';
$breadcrumbs = [
    ['mappings.php?shop=' . urlencode($shop), 'Mappings'],
    [null, $mapping['template_name'] ?? $mapping['name']],
];
$adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?php echo htmlspecialchars($mapping['source_connection_label'] . ' — ' . $mapping['source_object_label']); ?> &rarr; <?php echo htmlspecialchars($mapping['target_connection_label'] . ' — ' . ($mapping['target_object_label'] ?? 'Rechnung')); ?></h3>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-3">Mapping-Vorlage</dt>
                    <dd class="col-9">
                        <?php if ($mapping['mapping_template_id'] !== null): ?>
                            <a href="mapping_template_edit.php?id=<?php echo (int) $mapping['mapping_template_id']; ?>"><?php echo htmlspecialchars($mapping['template_name']); ?></a>
                            (Feld-Zuordnungen dort bearbeiten — gilt für alle Shops, die diese Vorlage nutzen)
                            <?php if ($usesDocumentPipeline): ?>
                                <div class="text-secondary small mt-1">Belegerstellung über die Oberfläche: Quelle ist die aufbereitete Bestellung, Kontrollen und Rabatt-Darstellung laut Belegprofil des Ziel-Objects.</div>
                            <?php endif; ?>
                        <?php elseif ($isInvoiceActivation): ?>
                            <span class="text-secondary">Keine — die Rechnung läuft über die bisherige, fest programmierte HelloCash-Logik.</span>
                        <?php else: ?>
                            <span class="text-secondary">Vorlage wurde gelöscht.</span>
                        <?php endif; ?>
                        <?php if (count($templateOptions) > 0 || $isInvoiceActivation): ?>
                            <form method="post" class="d-flex gap-2 mt-2" style="max-width: 640px">
                                <input type="hidden" name="action" value="set_template">
                                <select name="mapping_template_id" class="form-select form-select-sm">
                                    <?php if ($isInvoiceActivation): ?>
                                        <option value="">— keine (bisherige HelloCash-Logik) —</option>
                                    <?php endif; ?>
                                    <?php foreach ($templateOptions as $templateOption): ?>
                                        <option value="<?php echo (int) $templateOption['id']; ?>" <?php echo (int) ($mapping['mapping_template_id'] ?? 0) === (int) $templateOption['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($templateOption['name'] . ((int) $templateOption['uses_prepared_order'] === 1 ? ' (Bestellung aufbereitet)' : '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Vorlage übernehmen</button>
                            </form>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-3">Auslöser</dt>
                    <dd class="col-9">
                        <?php if ($mapping['trigger_topic'] !== null): ?>
                            <div>Webhook (<?php echo htmlspecialchars((string) $mapping['trigger_topic']); ?>)</div>
                        <?php endif; ?>
                        <?php if ($mapping['schedule_interval_minutes'] !== null): ?>
                            <div>Zeitplan (alle <?php echo (int) $mapping['schedule_interval_minutes']; ?> Min.)</div>
                        <?php endif; ?>
                        <form method="post" class="d-flex flex-wrap gap-3 align-items-center mt-2">
                            <input type="hidden" name="action" value="save_triggers">
                            <?php if ($mapping['source_object_key'] === 'order'): ?>
                                <label class="form-check mb-0">
                                    <input type="checkbox" class="form-check-input" name="webhook_enabled" <?php echo ($mapping['trigger_type'] ?? '') === 'webhook' && $mapping['trigger_topic'] !== null ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Webhook „Bestellung bezahlt“ (sofort)</span>
                                </label>
                            <?php endif; ?>
                            <label class="form-check mb-0">
                                <input type="checkbox" class="form-check-input" name="schedule_enabled" <?php echo $mapping['schedule_interval_minutes'] !== null ? 'checked' : ''; ?>>
                                <span class="form-check-label">Zeitplan-Nachlauf alle</span>
                            </label>
                            <input type="number" name="schedule_interval_minutes" class="form-control form-control-sm" style="width: 90px" min="1" value="<?php echo (int) ($mapping['schedule_interval_minutes'] ?? 15); ?>">
                            <span class="text-secondary">Min.</span>
                            <button type="submit" class="btn btn-sm btn-outline-primary">Auslöser speichern</button>
                        </form>
                        <?php if ($mapping['source_object_key'] === 'order'): ?>
                            <div class="text-secondary small mt-1">Der Webhook braucht ein Shopify-Abo „Bestellung bezahlt“ auf Connect (Betrieb → Webhooks) – wird beim Umschalten des Shops nach Connect automatisch angelegt. Der Zeitplan bleibt als Sicherheitsnetz sinnvoll, doppelte Belege verhindert die Dedup-Sperre.</div>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-3">Status</dt>
                    <dd class="col-9">
                        <span class="badge <?php echo ((int) $mapping['enabled']) === 1 ? 'bg-green-lt' : 'bg-secondary-lt'; ?>">
                            <?php echo ((int) $mapping['enabled']) === 1 ? 'aktiv' : 'inaktiv'; ?>
                        </span>
                        — Ändern über <a href="mappings.php?shop=<?php echo urlencode($shop); ?>">Mappings</a>-Liste.
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <?php $activationSettingsSchema = $mapping['target_settings_schema'] !== null ? (json_decode((string) $mapping['target_settings_schema'], true) ?: []) : []; ?>
    <?php if (count($activationSettingsSchema) > 0): ?>
        <?php $currentActivationSettings = $mapping['settings'] !== null ? (json_decode((string) $mapping['settings'], true) ?: []) : []; ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Einstellungen für diesen Shop</h3>
                </div>
                <div class="card-body">
                    <p class="text-secondary">
                        Werte, die nur für <strong><?php echo htmlspecialchars($shop); ?></strong> gelten (anders als die
                        Feld-Zuordnungen, die aus der geteilten Mapping-Vorlage kommen und für alle Shops gleich sind, die
                        sie aktivieren) — z. B. welcher Kassierer oder welche Zahlungsart bei <?php echo htmlspecialchars((string) ($mapping['target_object_label'] ?? '')); ?> verwendet wird.
                    </p>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="save_activation_settings">
                        <?php foreach ($activationSettingsSchema as $field): ?>
                            <?php $key = (string) ($field['key'] ?? ''); ?>
                            <?php if ($key === '') continue; ?>
                            <?php $currentValue = $currentActivationSettings[$key] ?? null; ?>
                            <div class="col-md-4">
                                <label class="form-label">
                                    <?php echo htmlspecialchars((string) ($field['label'] ?? $key)); ?>
                                    <?php if (!empty($field['required'])): ?><span class="text-danger">*</span><?php endif; ?>
                                </label>
                                <?php if (($field['type'] ?? 'text') === 'checkbox'): ?>
                                    <div class="form-check form-switch pt-1">
                                        <input type="checkbox" class="form-check-input" name="settings[<?php echo htmlspecialchars($key); ?>]" <?php echo !empty($currentValue) ? 'checked' : ''; ?>>
                                    </div>
                                <?php else: ?>
                                    <input
                                        type="<?php echo ($field['type'] ?? 'text') === 'number' ? 'number' : 'text'; ?>"
                                        step="any"
                                        name="settings[<?php echo htmlspecialchars($key); ?>]"
                                        class="form-control"
                                        placeholder="<?php echo htmlspecialchars((string) ($field['placeholder'] ?? '')); ?>"
                                        value="<?php echo htmlspecialchars($currentValue !== null ? (string) $currentValue : ''); ?>"
                                        <?php echo !empty($field['required']) ? 'required' : ''; ?>
                                    >
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Testen</h3>
            </div>
            <div class="card-body">
                <?php if (count($sampleRecords) === 0): ?>
                    <div class="text-secondary">Noch keine gecachten Quell-Datensätze vorhanden — zuerst unter "Daten ansehen" welche laden.</div>
                <?php else: ?>
                    <div class="mb-2">
                        <label class="form-label">Beispiel-Datensatz</label>
                        <select id="test-sample-select" class="form-select" style="max-width: 320px">
                            <?php // nach dem Absenden den getesteten Datensatz wieder auswaehlen -- sonst steht oben der neueste, unten die Vorschau eines anderen ?>
                            <?php $selectedSampleId = (string) ($_POST['sample_external_id'] ?? ''); ?>
                            <?php foreach ($sampleRecords as $sample): ?>
                                <option value="<?php echo htmlspecialchars($sample['external_id']); ?>" <?php echo $sample['external_id'] === $selectedSampleId ? 'selected' : ''; ?>><?php echo htmlspecialchars($sample['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php $previewTemplateOptions = array_filter($templateOptions, static fn (array $t): bool => (int) $t['uses_prepared_order'] === 1); ?>
                    <form method="post" class="d-inline-flex gap-2 me-2 align-items-center">
                        <input type="hidden" name="action" value="preview_mapping">
                        <input type="hidden" name="sample_external_id" class="test-sample-hidden-input">
                        <?php if (count($previewTemplateOptions) > 0): ?>
                            <select name="preview_template_id" class="form-select" style="max-width: 320px" title="Vorschau auch mit einer noch NICHT zugeordneten Beleg-Vorlage möglich">
                                <option value="">mit der zugeordneten Vorlage</option>
                                <?php foreach ($previewTemplateOptions as $previewTemplateOption): ?>
                                    <option value="<?php echo (int) $previewTemplateOption['id']; ?>">mit: <?php echo htmlspecialchars($previewTemplateOption['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-outline-primary text-nowrap">Vorschau (schreibt nicht)</button>
                    </form>
                    <form method="post" class="d-inline-block" onsubmit="return confirm('Wirklich einmal echt ausführen? Das schreibt tatsächlich ins Zielsystem (z. B. legt einen echten Auftrag an).');">
                        <input type="hidden" name="action" value="run_mapping_once">
                        <input type="hidden" name="sample_external_id" class="test-sample-hidden-input">
                        <button type="submit" class="btn btn-outline-danger">Einmal wirklich ausführen</button>
                    </form>
                    <script>
                        // Ein sichtbares Auswahlfeld fuer beide Test-Aktionen statt
                        // zwei nebeneinander gequetschten Formularen mit je eigenem
                        // Dropdown (sah abgeschnitten/ueberlappend aus) -- bei jedem
                        // Absenden wird der aktuell gewaehlte Wert in das jeweilige
                        // versteckte Feld uebernommen.
                        (function() {
                            var select = document.getElementById('test-sample-select');
                            var hiddenInputs = document.querySelectorAll('.test-sample-hidden-input');

                            function syncHiddenInputs() {
                                hiddenInputs.forEach(function(input) {
                                    input.value = select.value;
                                });
                            }
                            select.addEventListener('change', syncHiddenInputs);
                            syncHiddenInputs();
                        })();
                    </script>
                <?php endif; ?>
                <?php if ($testOutput !== null): ?>
                    <pre class="bg-dark text-light p-3 rounded mt-3" style="max-height:400px;overflow:auto;"><?php echo htmlspecialchars($testOutput); ?></pre>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
