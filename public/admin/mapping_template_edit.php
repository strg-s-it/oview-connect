<?php

declare(strict_types=1);

/**
 * connect/public/admin/mapping_template_edit.php
 *
 * Feld-Mapping-Editor fuer EINE globale Mapping-Vorlage (siehe
 * mapping_templates.php). Pro Ziel-Feld: welches Quell-Feld (oder ein
 * statischer/berechneter Wert) liefert den Wert, und ueber welchen Transform
 * (direkt/statisch/Lookup/Verkettung/Datumsformat, siehe
 * Connect\Mapping\MappingEngine). Speichert bewusst als "alles auf einmal
 * loeschen und neu anlegen" -- es gibt keine stabile UI-Identitaet fuer eine
 * einzelne connect_field_mappings-Zeile ausser dem Ziel-Feld selbst.
 *
 * KEIN Testen hier (anders als zuvor) -- eine Vorlage hat keine konkrete
 * Connection/keine gecachten Beispieldaten, das ist Aufgabe der Aktivierung
 * pro Shop (siehe admin/mapping_edit.php).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Sync\ProductImportService;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$templateId = (int) ($_GET['id'] ?? 0);

$templateStmt = $pdo->prepare(
    'SELECT t.*, sct.name AS source_type_name, so.label AS source_object_label,
            tct.name AS target_type_name, tobj.label AS target_object_label
     FROM connect_mapping_templates t
     JOIN connect_connector_types sct ON sct.id = t.source_connector_type_id
     JOIN connect_objects so ON so.id = t.source_object_id
     JOIN connect_connector_types tct ON tct.id = t.target_connector_type_id
     JOIN connect_objects tobj ON tobj.id = t.target_object_id
     WHERE t.id = :id'
);
$templateStmt->execute(['id' => $templateId]);
$template = $templateStmt->fetch();

if ($template === false) {
    \Connect\Http\ErrorPage::show(
        404,
        'Mapping-Vorlage nicht gefunden',
        'Dieser Eintrag existiert nicht (mehr) oder wurde gelöscht.',
        ['label' => 'Zu den Mapping-Vorlagen', 'url' => 'mapping_templates.php']
    );
}

$flashMessage = null;
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_field_mappings') {
    $targetFieldRows = $pdo->prepare('SELECT id FROM connect_object_fields WHERE object_id = :id');
    $targetFieldRows->execute(['id' => $template['target_object_id']]);
    $targetFieldIds = array_map('intval', $targetFieldRows->fetchAll(PDO::FETCH_COLUMN));

    $pdo->prepare('DELETE FROM connect_field_mappings WHERE mapping_template_id = :id')->execute(['id' => $templateId]);

    $insert = $pdo->prepare(
        'INSERT INTO connect_field_mappings (mapping_template_id, source_field_id, target_field_id, transform_type, transform_config, sort_order)
         VALUES (:mapping_template_id, :source_field_id, :target_field_id, :transform_type, :transform_config, :sort_order)'
    );

    $sortOrder = 0;
    $savedCount = 0;

    foreach ($targetFieldIds as $targetFieldId) {
        $transformType = (string) ($_POST['transform_type'][$targetFieldId] ?? 'direct');
        $sourceFieldId = ((string) ($_POST['source_field_id'][$targetFieldId] ?? '')) !== ''
            ? (int) $_POST['source_field_id'][$targetFieldId]
            : null;

        if ($sourceFieldId === null && !in_array($transformType, ['static', 'manual_input', 'derived_price', 'text_template', 'shop_field'], true)) {
            continue;
        }

        $config = [];
        switch ($transformType) {
            case 'static':
                $value = trim((string) ($_POST['static_value'][$targetFieldId] ?? ''));
                if ($value === '') {
                    continue 2;
                }
                $config = ['value' => $value];
                break;

            case 'text_template':
                $textTemplate = trim((string) ($_POST['text_template'][$targetFieldId] ?? ''));
                if ($textTemplate === '') {
                    continue 2;
                }
                // Platzhalter brauchen kein Quellfeld -- ein trotzdem gewaehltes wird ignoriert
                $sourceFieldId = null;
                $config = ['template' => $textTemplate];
                break;

            case 'shop_field':
                $shopFieldKey = trim((string) ($_POST['shop_field_key'][$targetFieldId] ?? ''));
                if ($shopFieldKey === '') {
                    continue 2;
                }
                $sourceFieldId = null;
                $config = ['field_key' => $shopFieldKey];
                break;

            case 'manual_input':
                $manualLabel = trim((string) ($_POST['manual_label'][$targetFieldId] ?? ''));
                if ($manualLabel === '') {
                    continue 2;
                }
                $config = ['label' => $manualLabel];
                $manualDefaultSource = (string) ($_POST['manual_default_source'][$targetFieldId] ?? '');
                if (isset(ProductImportService::SHOP_SETTING_DEFAULT_SOURCES[$manualDefaultSource])) {
                    $config['default_source'] = $manualDefaultSource;
                }
                break;

            case 'derived_price':
                $role = ((string) ($_POST['derived_price_role'][$targetFieldId] ?? '')) === 'net' ? 'net' : 'gross';
                $taxRateTargetFieldId = (int) ($_POST['derived_price_tax_rate_field'][$targetFieldId] ?? 0);
                $basePriceTargetFieldId = (int) ($_POST['derived_price_base_field'][$targetFieldId] ?? 0);

                if ($taxRateTargetFieldId <= 0) {
                    continue 2;
                }
                // Der Basispreis kommt ENTWEDER direkt von einem Quellfeld
                // (links, z.B. Shopifys "Preis") ODER von einem bereits
                // anders berechneten Zielfeld (z.B. "Brutto-Verkaufspreis",
                // wenn DAS schon direkt von Shopify gemappt ist) -- braucht
                // mindestens eine der beiden Angaben, sonst gibt es nichts,
                // woraus gerechnet werden koennte.
                if ($sourceFieldId === null && $basePriceTargetFieldId <= 0) {
                    continue 2;
                }
                $config = ['role' => $role, 'tax_rate_target_field_id' => $taxRateTargetFieldId];
                if ($basePriceTargetFieldId > 0) {
                    $config['base_price_target_field_id'] = $basePriceTargetFieldId;
                }
                break;

            case 'lookup':
                $lookupTableId = (int) ($_POST['lookup_table_id'][$targetFieldId] ?? 0);
                if ($lookupTableId <= 0) {
                    continue 2;
                }
                $config = ['lookup_table_id' => $lookupTableId];
                $default = trim((string) ($_POST['lookup_default'][$targetFieldId] ?? ''));
                if ($default !== '') {
                    $config['default'] = $default;
                }
                break;

            case 'concat':
                $additionalFieldId = (int) ($_POST['concat_field_id'][$targetFieldId] ?? 0);
                if ($additionalFieldId <= 0) {
                    continue 2;
                }
                $config = ['additional_source_field_ids' => [$additionalFieldId]];
                $separator = (string) ($_POST['concat_separator'][$targetFieldId] ?? ' ');
                if ($separator !== ' ') {
                    $config['separator'] = $separator;
                }
                break;

            case 'date_format':
                $format = trim((string) ($_POST['date_format'][$targetFieldId] ?? ''));
                if ($format === '') {
                    continue 2;
                }
                $config = ['format' => $format];
                break;

            case 'differs_from':
                $compareFieldId = (int) ($_POST['compare_field_id'][$targetFieldId] ?? 0);
                if ($compareFieldId <= 0) {
                    continue 2;
                }
                $config = ['compare_field_id' => $compareFieldId];
                break;
        }

        // Gilt fuer jede Transform-Art, siehe MappingEngine::omitValue().
        if (isset($_POST['omit_if_empty'][$targetFieldId])) {
            $config['omit_if_empty'] = true;
        }

        $insert->execute([
            'mapping_template_id' => $templateId,
            'source_field_id' => $sourceFieldId,
            'target_field_id' => $targetFieldId,
            'transform_type' => $transformType,
            'transform_config' => count($config) > 0 ? json_encode($config, JSON_THROW_ON_ERROR) : null,
            'sort_order' => $sortOrder++,
        ]);
        $savedCount++;
    }

    $flashMessage = "{$savedCount} Feld-Zuordnung(en) gespeichert.";
    $flashType = 'success';
}

// -----------------------------------------------------------------------
// Daten fuer die Anzeige laden
// -----------------------------------------------------------------------
$sourceFieldsStmt = $pdo->prepare('SELECT * FROM connect_object_fields WHERE object_id = :id ORDER BY id');
$sourceFieldsStmt->execute(['id' => $template['source_object_id']]);
$sourceFields = $sourceFieldsStmt->fetchAll();

$targetFieldsStmt = $pdo->prepare('SELECT * FROM connect_object_fields WHERE object_id = :id ORDER BY id');
$targetFieldsStmt->execute(['id' => $template['target_object_id']]);
$targetFields = $targetFieldsStmt->fetchAll();

$existingMappingsStmt = $pdo->prepare('SELECT * FROM connect_field_mappings WHERE mapping_template_id = :id');
$existingMappingsStmt->execute(['id' => $templateId]);
$existingByTargetFieldId = [];
foreach ($existingMappingsStmt->fetchAll() as $row) {
    $existingByTargetFieldId[(int) $row['target_field_id']] = $row;
}

$lookupTables = $pdo->query('SELECT id, name FROM connect_lookup_tables ORDER BY name')->fetchAll();

$topLevelTargetFields = array_values(array_filter($targetFields, static fn ($f) => $f['parent_field_id'] === null && (int) $f['is_repeating_group'] === 0));
$targetGroups = array_values(array_filter($targetFields, static fn ($f) => (int) $f['is_repeating_group'] === 1));

$topLevelSourceOptions = array_values(array_filter($sourceFields, static fn ($f) => $f['parent_field_id'] === null && (int) $f['is_repeating_group'] === 0));

$transformLabels = [
    'direct' => 'Direkt (Wert 1:1 übernehmen)',
    'static' => 'Fester Wert',
    'text_template' => 'Text mit Platzhaltern',
    'shop_field' => 'Shop-Feld (je Shop definiert)',
    'manual_input' => 'Manuelle Eingabe beim Import',
    'derived_price' => 'Berechnet (Netto/Brutto aus Steuersatz)',
    'lookup' => 'Lookup-Tabelle',
    'concat' => 'Verketten (2 Felder)',
    'date_format' => 'Datumsformat',
    'differs_from' => 'Nur wenn abweichend von ...',
];

$pageTitle = 'Felder zuordnen: ' . $template['name'];
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$breadcrumbs = [
    ['mapping_templates.php', 'Mapping-Vorlagen'],
    [null, $template['name']],
];
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';

/**
 * Rendert eine Zielfeld-Zeile der Zuordnungs-Tabelle. $sourceOptions sind die
 * fuer DIESES Zielfeld waehlbaren Quellfelder (top-level Ziel -> top-level
 * Quelle; Gruppen-Kind-Ziel -> nur Kinder derselben Quell-Gruppe, siehe
 * Connect\Mapping\MappingEngine::translateGroup()). $allTopLevelTargetFields
 * nur fuer transform_type='derived_price' gebraucht (Steuersatz-Feld-Auswahl,
 * siehe Connect\Mapping\MappingEngine::applyDerivedPrice()) -- ein ANDERES
 * bereits konfiguriertes Zielfeld dieser Vorlage, nicht auf source_field_id
 * beschraenkt.
 */
function render_field_mapping_row(array $targetField, array $sourceOptions, array $existingByTargetFieldId, array $lookupTables, array $transformLabels, array $allTopLevelTargetFields = []): void
{
    $targetFieldId = (int) $targetField['id'];
    $existing = $existingByTargetFieldId[$targetFieldId] ?? null;
    $selectedTransform = $existing['transform_type'] ?? 'direct';
    $config = $existing !== null && $existing['transform_config'] !== null ? (json_decode((string) $existing['transform_config'], true) ?: []) : [];
    ?>
    <tr>
        <td><strong><?php echo htmlspecialchars($targetField['label']); ?></strong><br><small class="text-secondary font-monospace"><?php echo htmlspecialchars($targetField['field_key']); ?></small></td>
        <td>
            <select name="source_field_id[<?php echo $targetFieldId; ?>]" class="form-select form-select-sm">
                <option value="">-- kein Quellfeld --</option>
                <?php foreach ($sourceOptions as $sourceField): ?>
                    <option value="<?php echo (int) $sourceField['id']; ?>" <?php echo (int) ($existing['source_field_id'] ?? 0) === (int) $sourceField['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($sourceField['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <select name="transform_type[<?php echo $targetFieldId; ?>]" class="form-select form-select-sm transform-type-select" onchange="mappingConfigToggle(this)">
                <?php foreach ($transformLabels as $value => $label): ?>
                    <option value="<?php echo $value; ?>" <?php echo $selectedTransform === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="transform-config-cell">
            <div class="transform-config" data-transform="static">
                <input type="text" name="static_value[<?php echo $targetFieldId; ?>]" class="form-control form-control-sm" placeholder="Fester Wert" value="<?php echo $selectedTransform === 'static' ? htmlspecialchars((string) ($config['value'] ?? '')) : ''; ?>">
            </div>

            <div class="transform-config" data-transform="text_template">
                <input type="text" name="text_template[<?php echo $targetFieldId; ?>]" class="form-control form-control-sm font-monospace" placeholder="z. B. Shopify {{order_number}}" value="<?php echo $selectedTransform === 'text_template' ? htmlspecialchars((string) ($config['template'] ?? '')) : ''; ?>">
                <small class="text-secondary d-block mt-1">
                    Kein Quellfeld nötig. <code>{{feld}}</code> wird durch den Wert ersetzt, <code>{{feld|d.m.Y}}</code> formatiert ein Datum.
                    Verfügbar:
                    <?php foreach ($sourceOptions as $i => $sourceField): ?><?php echo $i > 0 ? ', ' : ''; ?><code title="<?php echo htmlspecialchars($sourceField['label']); ?>">{{<?php echo htmlspecialchars($sourceField['field_key']); ?>}}</code><?php endforeach; ?>
                </small>
            </div>

            <div class="transform-config" data-transform="shop_field">
                <input type="text" name="shop_field_key[<?php echo $targetFieldId; ?>]" list="shop-field-keys" class="form-control form-control-sm font-monospace" placeholder="Schlüssel, z. B. net_purchase_price" value="<?php echo $selectedTransform === 'shop_field' ? htmlspecialchars((string) ($config['field_key'] ?? '')) : ''; ?>">
                <small class="text-secondary d-block mt-1">Kein Quellfeld nötig. Wert kommt aus dem gleichnamigen Shop-Feld (Shop → Shop-Felder). Shops ohne dieses Feld senden nichts, wenn „Nur senden, wenn Wert vorhanden“ an ist.</small>
            </div>

            <div class="transform-config" data-transform="manual_input">
                <input type="text" name="manual_label[<?php echo $targetFieldId; ?>]" class="form-control form-control-sm" placeholder="Anzeigename für den Merchant, z. B. Steuersatz (%)" value="<?php echo $selectedTransform === 'manual_input' ? htmlspecialchars((string) ($config['label'] ?? '')) : ''; ?>">
                <select name="manual_default_source[<?php echo $targetFieldId; ?>]" class="form-select form-select-sm mt-1">
                    <option value="">-- keine Vorbelegung --</option>
                    <?php foreach (ProductImportService::SHOP_SETTING_DEFAULT_SOURCES as $sourceKey => $source): ?>
                        <option value="<?php echo htmlspecialchars($sourceKey); ?>" <?php echo $selectedTransform === 'manual_input' && ($config['default_source'] ?? '') === $sourceKey ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($source['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-secondary d-block mt-1">Erscheint auf der Produkte-Seite als zusätzliches Eingabefeld bei "Fehlt im Zielsystem" -- der Merchant trägt den Wert beim Import selbst ein (z. B. weil er sich pro Produkt/Land unterscheidet und weder aus Shopify ausgelesen noch fest hinterlegt werden kann). Optional vorbelegt aus einer Shop-Einstellung, bleibt pro Produkt änderbar.</small>
            </div>

            <div class="transform-config" data-transform="derived_price">
                <select name="derived_price_role[<?php echo $targetFieldId; ?>]" class="form-select form-select-sm">
                    <option value="net" <?php echo $selectedTransform === 'derived_price' && ($config['role'] ?? '') === 'net' ? 'selected' : ''; ?>>Netto</option>
                    <option value="gross" <?php echo $selectedTransform === 'derived_price' && ($config['role'] ?? 'gross') === 'gross' ? 'selected' : ''; ?>>Brutto (inkl. Steuer)</option>
                </select>
                <select name="derived_price_tax_rate_field[<?php echo $targetFieldId; ?>]" class="form-select form-select-sm mt-1">
                    <option value="">-- Steuersatz-Feld --</option>
                    <?php foreach ($allTopLevelTargetFields as $rateField): ?>
                        <?php if ((int) $rateField['id'] === $targetFieldId) continue; ?>
                        <option value="<?php echo (int) $rateField['id']; ?>" <?php echo $selectedTransform === 'derived_price' && (int) ($config['tax_rate_target_field_id'] ?? 0) === (int) $rateField['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rateField['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="derived_price_base_field[<?php echo $targetFieldId; ?>]" class="form-select form-select-sm mt-1">
                    <option value="">-- Basispreis stattdessen aus Zielfeld -- (optional, falls kein Quellfeld gewählt)</option>
                    <?php foreach ($allTopLevelTargetFields as $baseField): ?>
                        <?php if ((int) $baseField['id'] === $targetFieldId) continue; ?>
                        <option value="<?php echo (int) $baseField['id']; ?>" <?php echo $selectedTransform === 'derived_price' && (int) ($config['base_price_target_field_id'] ?? 0) === (int) $baseField['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($baseField['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-secondary d-block mt-1">Basispreis kommt ENTWEDER vom Quellfeld links (Shopifys EINER Preis, Netto ODER Brutto je nach Einstellungen "taxesIncluded") ODER -- falls dort "-- kein Quellfeld --" gewählt ist -- von einem bereits anders gemappten Zielfeld (z. B. wenn "Brutto-Verkaufspreis" direkt von Shopify kommt, hier "Brutto-Verkaufspreis" als Basispreis-Feld wählen). Steuersatz-Feld muss einen Prozentwert liefern (z. B. ein Zielfeld mit "Manuelle Eingabe beim Import").</small>
            </div>

            <div class="transform-config" data-transform="lookup">
                <select name="lookup_table_id[<?php echo $targetFieldId; ?>]" class="form-select form-select-sm">
                    <option value="">-- Lookup-Tabelle --</option>
                    <?php foreach ($lookupTables as $lookupTable): ?>
                        <option value="<?php echo (int) $lookupTable['id']; ?>" <?php echo $selectedTransform === 'lookup' && (int) ($config['lookup_table_id'] ?? 0) === (int) $lookupTable['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($lookupTable['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="lookup_default[<?php echo $targetFieldId; ?>]" class="form-control form-control-sm mt-1" placeholder="Fallback, falls kein Treffer" value="<?php echo $selectedTransform === 'lookup' ? htmlspecialchars((string) ($config['default'] ?? '')) : ''; ?>">
            </div>

            <div class="transform-config" data-transform="concat">
                <select name="concat_field_id[<?php echo $targetFieldId; ?>]" class="form-select form-select-sm">
                    <option value="">-- 2. Quellfeld zum Verketten --</option>
                    <?php foreach ($sourceOptions as $sourceField): ?>
                        <?php $additionalIds = $config['additional_source_field_ids'] ?? []; ?>
                        <option value="<?php echo (int) $sourceField['id']; ?>" <?php echo $selectedTransform === 'concat' && in_array((int) $sourceField['id'], $additionalIds, true) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sourceField['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="concat_separator[<?php echo $targetFieldId; ?>]" class="form-control form-control-sm mt-1" placeholder="Trennzeichen (Standard: Leerzeichen)" value="<?php echo $selectedTransform === 'concat' ? htmlspecialchars((string) ($config['separator'] ?? '')) : ''; ?>">
            </div>

            <div class="transform-config" data-transform="date_format">
                <input type="text" name="date_format[<?php echo $targetFieldId; ?>]" class="form-control form-control-sm" placeholder="z. B. d.m.Y" value="<?php echo $selectedTransform === 'date_format' ? htmlspecialchars((string) ($config['format'] ?? '')) : ''; ?>">
            </div>

            <div class="transform-config" data-transform="differs_from">
                <select name="compare_field_id[<?php echo $targetFieldId; ?>]" class="form-select form-select-sm">
                    <option value="">-- Vergleichsfeld --</option>
                    <?php foreach ($sourceOptions as $sourceField): ?>
                        <option value="<?php echo (int) $sourceField['id']; ?>" <?php echo $selectedTransform === 'differs_from' && (int) ($config['compare_field_id'] ?? 0) === (int) $sourceField['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sourceField['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-secondary d-block mt-1">Quellfeld (links) wird nur übernommen, wenn es sich vom hier gewählten Vergleichsfeld unterscheidet — sonst bleibt das Zielfeld leer.</small>
            </div>

            <div class="form-check mt-1">
                <input type="checkbox" class="form-check-input" id="omit-if-empty-<?php echo $targetFieldId; ?>" name="omit_if_empty[<?php echo $targetFieldId; ?>]" <?php echo !empty($config['omit_if_empty']) ? 'checked' : ''; ?>>
                <label class="form-check-label small" for="omit-if-empty-<?php echo $targetFieldId; ?>">Nur senden, wenn Wert vorhanden</label>
            </div>
        </td>
    </tr>
    <?php
}
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <?php echo htmlspecialchars($template['source_type_name'] . ' — ' . $template['source_object_label']); ?>
                    &rarr;
                    <?php echo htmlspecialchars($template['target_type_name'] . ' — ' . $template['target_object_label']); ?>
                </h3>
            </div>
            <div class="card-body">
                <p class="text-secondary">
                    Für jedes Zielfeld: welches Quellfeld liefert den Wert (oder ein fester/berechneter Wert), und über
                    welche Umrechnung. Leer gelassene Zielfelder werden beim Schreiben nicht mitgesendet.
                    Diese Vorlage gilt für ALLE Shops, die sie unter "Mappings" aktivieren.
                    Lookup-Tabellen verwalten: <a href="lookup_tables.php">Lookup-Tabellen</a>.
                </p>
                <?php
                // Vorschlaege fuer die Umrechnung "Shop-Feld": alle Schluessel, die irgendein Shop definiert hat
                try {
                    $knownShopFields = $pdo->query('SELECT field_key, MIN(label) AS label, COUNT(DISTINCT shop) AS shops FROM connect_shop_fields GROUP BY field_key ORDER BY field_key')->fetchAll();
                } catch (\Throwable) {
                    $knownShopFields = [];
                }
                ?>
                <datalist id="shop-field-keys">
                    <?php foreach ($knownShopFields as $knownShopField): ?>
                        <option value="<?php echo htmlspecialchars((string) $knownShopField['field_key']); ?>"><?php echo htmlspecialchars($knownShopField['label'] . ' · ' . $knownShopField['shops'] . ' Shop(s)'); ?></option>
                    <?php endforeach; ?>
                </datalist>
                <form method="post">
                    <input type="hidden" name="action" value="save_field_mappings">
                    <div class="table-responsive">
                        <table class="table table-vcenter">
                            <thead>
                                <tr>
                                    <th style="width:20%">Zielfeld</th>
                                    <th style="width:20%">Quellfeld</th>
                                    <th style="width:15%">Umrechnung</th>
                                    <th>Konfiguration</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topLevelTargetFields as $targetField): ?>
                                    <?php render_field_mapping_row($targetField, $topLevelSourceOptions, $existingByTargetFieldId, $lookupTables, $transformLabels, $topLevelTargetFields); ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php foreach ($targetGroups as $targetGroup): ?>
                        <?php
                        $targetGroupChildren = array_values(array_filter($targetFields, static fn ($f) => (int) ($f['parent_field_id'] ?? 0) === (int) $targetGroup['id']));
                        $sourceGroup = null;
                        foreach ($sourceFields as $f) {
                            if ((int) $f['is_repeating_group'] === 1) {
                                $sourceGroup = $f;
                                break;
                            }
                        }
                        $groupSourceOptions = $sourceGroup !== null
                            ? array_values(array_filter($sourceFields, static fn ($f) => (int) ($f['parent_field_id'] ?? 0) === (int) $sourceGroup['id']))
                            : [];
                        ?>
                        <h4 class="mt-4"><?php echo htmlspecialchars($targetGroup['label']); ?> (Zeilenpositionen)</h4>
                        <?php if ($sourceGroup === null): ?>
                            <div class="alert alert-warning">Die Quelle hat keine wiederholende Gruppe (z. B. Zeilenpositionen) — diese Gruppe kann nicht befüllt werden.</div>
                        <?php else: ?>
                            <p class="text-secondary">Quelle: <?php echo htmlspecialchars($sourceGroup['label']); ?> (<?php echo count($groupSourceOptions); ?> Feld(er))</p>
                            <div class="table-responsive">
                                <table class="table table-vcenter">
                                    <thead>
                                        <tr>
                                            <th style="width:20%">Zielfeld</th>
                                            <th style="width:20%">Quellfeld (je Zeile)</th>
                                            <th style="width:15%">Umrechnung</th>
                                            <th>Konfiguration</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($targetGroupChildren as $childField): ?>
                                            <?php render_field_mapping_row($childField, $groupSourceOptions, $existingByTargetFieldId, $lookupTables, $transformLabels); ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <button type="submit" class="btn btn-primary mt-2">Alle Zuordnungen speichern</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Zeigt je Zeile nur die zur gewaehlten Umrechnung passende Konfiguration
    // (Fester Wert / Lookup-Tabelle / Verketten / Datumsformat) -- gleiches
    // Prinzip wie showAuthFields() in _connections_body.php. Vorher standen
    // alle fuenf Eingaben immer gleichzeitig da, was verwirrend war (siehe
    // Nutzer-Rueckmeldung).
    function mappingConfigToggle(selectEl) {
        var cell = selectEl.closest('tr').querySelector('.transform-config-cell');
        cell.querySelectorAll('.transform-config').forEach(function (div) {
            div.hidden = div.dataset.transform !== selectEl.value;
        });
    }

    document.querySelectorAll('.transform-type-select').forEach(mappingConfigToggle);
</script>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
