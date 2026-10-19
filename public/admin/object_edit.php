<?php

declare(strict_types=1);

/**
 * connect/public/admin/object_edit.php
 *
 * Bearbeitet ein Object: Stammdaten, alle Felder als EINE Tabelle mit
 * einem gemeinsamen "Alle speichern"-Button (statt vieler Einzelformulare),
 * plus Operationen-Liste mit Link zur Detailseite (operation_edit.php --
 * body_template kann sehr lang sein, braucht mehr Platz als eine Tabellenzelle).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Connector\ConnectorFactory;
use Connect\Database;
use Connect\Http\HttpClient;
use Connect\Security\Crypto;
use Connect\Shopify\TokenRefresher;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$objectId = (int) ($_GET['id'] ?? 0);
$objectStmt = $pdo->prepare('SELECT o.*, t.id AS connector_type_id, t.name AS connector_type_name, t.request_format FROM connect_objects o JOIN connect_connector_types t ON t.id = o.connector_type_id WHERE o.id = :id');
$objectStmt->execute(['id' => $objectId]);
$object = $objectStmt->fetch();

if ($object === false) {
    \Connect\Http\ErrorPage::show(
        404,
        'Object nicht gefunden',
        'Dieser Eintrag existiert nicht (mehr) oder wurde gelöscht.',
        ['label' => 'Zu den Connector Types', 'url' => 'connector_types.php']
    );
}

$flashMessage = null;
$flashType = 'info';

// -----------------------------------------------------------------------
// POST: Object-Stammdaten speichern
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_object') {
    $label = trim((string) ($_POST['label'] ?? ''));

    if ($label === '') {
        $flashMessage = 'Das Label darf nicht leer sein.';
        $flashType = 'danger';
    } else {
        $hydrateBatchSizeInput = trim((string) ($_POST['hydrate_batch_size'] ?? ''));

        $update = $pdo->prepare(
            'UPDATE connect_objects
             SET label = :label, settings_schema = :settings_schema,
                 hydrate_details_after_list = :hydrate_details_after_list, hydrate_batch_size = :hydrate_batch_size
             WHERE id = :id'
        );
        $update->execute([
            'label' => $label,
            'settings_schema' => ((string) ($_POST['settings_schema'] ?? '')) !== '' ? $_POST['settings_schema'] : null,
            'hydrate_details_after_list' => isset($_POST['hydrate_details_after_list']) ? 1 : 0,
            'hydrate_batch_size' => $hydrateBatchSizeInput !== '' ? (int) $hydrateBatchSizeInput : null,
            'id' => $objectId,
        ]);
        $flashMessage = 'Gespeichert.';
        $flashType = 'success';
    }
    $objectStmt->execute(['id' => $objectId]);
    $object = $objectStmt->fetch();
}

// -----------------------------------------------------------------------
// POST: Belegprofil speichern (connect_objects.document_profile, Migration
// 0067) -- Eigenheiten des Zielsystems beim Anlegen von Rechnungen/Belegen,
// siehe Connect\Sync\Document\DocumentProfile. Bewusst eigene Karte mit
// Formularfeldern statt freiem JSON.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_document_profile') {
    if (isset($_POST['document_profile_enabled'])) {
        $profile = \Connect\Sync\Document\DocumentProfile::fromJson((string) json_encode([
            'discount_mode' => (string) ($_POST['discount_mode'] ?? ''),
            'absolute_discount_per_unit' => ($_POST['absolute_discount_scope'] ?? 'line') === 'unit',
            'percent_decimals' => (int) ($_POST['percent_decimals'] ?? 2),
            'document_number_path' => (string) ($_POST['document_number_path'] ?? ''),
            'gross_total_path' => (string) ($_POST['gross_total_path'] ?? ''),
            'pdf_path' => (string) ($_POST['pdf_path'] ?? ''),
            'customer_object_key' => (string) ($_POST['customer_object_key'] ?? ''),
            'customer_search_param' => (string) ($_POST['customer_search_param'] ?? ''),
            'customer_id_field' => (string) ($_POST['customer_id_field'] ?? ''),
            'customer_email_field' => (string) ($_POST['customer_email_field'] ?? ''),
            'customer_first_name_field' => (string) ($_POST['customer_first_name_field'] ?? ''),
            'customer_last_name_field' => (string) ($_POST['customer_last_name_field'] ?? ''),
            'customer_create_template_id' => (int) ($_POST['customer_create_template_id'] ?? 0),
            'customer_created_id_path' => (string) ($_POST['customer_created_id_path'] ?? ''),
            'customer_zip_field' => (string) ($_POST['customer_zip_field'] ?? ''),
            'customer_city_field' => (string) ($_POST['customer_city_field'] ?? ''),
            'customer_street_field' => (string) ($_POST['customer_street_field'] ?? ''),
            'shipping_article_id' => (string) ($_POST['shipping_article_id'] ?? ''),
        ]));
        $profileJson = json_encode($profile->toArray(), JSON_UNESCAPED_SLASHES);
    } else {
        $profileJson = null;
    }

    $pdo->prepare('UPDATE connect_objects SET document_profile = :profile WHERE id = :id')
        ->execute(['profile' => $profileJson, 'id' => $objectId]);
    $flashMessage = $profileJson !== null ? 'Belegprofil gespeichert.' : 'Belegprofil entfernt.';
    $flashType = 'success';

    $objectStmt->execute(['id' => $objectId]);
    $object = $objectStmt->fetch();
}

// -----------------------------------------------------------------------
// POST: alle Felder auf einmal speichern (eine Tabelle, ein Button)
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_fields') {
    $update = $pdo->prepare(
        'UPDATE connect_object_fields
         SET label = :label, field_path = :field_path, data_type = :data_type,
             is_repeating_group = :is_repeating_group, is_identifier = :is_identifier, parent_field_id = :parent_field_id,
             comparison_role = :comparison_role, is_stock_quantity = :is_stock_quantity, max_length = :max_length
         WHERE id = :id AND object_id = :object_id'
    );
    foreach ((array) ($_POST['fields'] ?? []) as $fieldId => $values) {
        $fieldId = (int) $fieldId;
        $parentFieldId = ((string) ($values['parent_field_id'] ?? '')) !== '' ? (int) $values['parent_field_id'] : null;
        $comparisonRole = (string) ($values['comparison_role'] ?? '');
        $maxLength = (int) ($values['max_length'] ?? 0);

        // Ein Feld darf nicht sich selbst als Eltern-Feld haben -- sonst
        // wuerde mapRepeatingGroup() spaeter versuchen, ein Feld unter sich
        // selbst zu gruppieren.
        $update->execute([
            'label' => (string) ($values['label'] ?? ''),
            'field_path' => ((string) ($values['field_path'] ?? '')) !== '' ? $values['field_path'] : null,
            'data_type' => (string) ($values['data_type'] ?? 'string'),
            'is_repeating_group' => isset($values['is_repeating_group']) ? 1 : 0,
            'is_identifier' => isset($values['is_identifier']) ? 1 : 0,
            'parent_field_id' => $parentFieldId !== $fieldId ? $parentFieldId : null,
            'comparison_role' => in_array($comparisonRole, ['title', 'sku', 'barcode', 'price', 'price_net'], true) ? $comparisonRole : null,
            'is_stock_quantity' => isset($values['is_stock_quantity']) ? 1 : 0,
            'max_length' => $maxLength > 0 ? $maxLength : null,
            'id' => $fieldId,
            'object_id' => $objectId,
        ]);
    }
    $flashMessage = 'Felder gespeichert.';
    $flashType = 'success';
}

// -----------------------------------------------------------------------
// POST: neues Feld hinzufuegen
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_field') {
    $fieldKey = trim((string) ($_POST['field_key'] ?? ''));
    $label = trim((string) ($_POST['label'] ?? ''));

    if ($fieldKey === '' || $label === '') {
        $flashMessage = 'Bitte field_key und Label angeben.';
        $flashType = 'danger';
    } else {
        $parentFieldId = ((string) ($_POST['parent_field_id'] ?? '')) !== '' ? (int) $_POST['parent_field_id'] : null;

        $insert = $pdo->prepare(
            'INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, is_identifier, parent_field_id)
             VALUES (:object_id, :field_key, :label, :data_type, :field_path, 0, 0, :parent_field_id)'
        );
        $insert->execute([
            'object_id' => $objectId,
            'field_key' => $fieldKey,
            'label' => $label,
            'data_type' => (string) ($_POST['data_type'] ?? 'string'),
            'field_path' => ((string) ($_POST['field_path'] ?? '')) !== '' ? $_POST['field_path'] : null,
            'parent_field_id' => $parentFieldId,
        ]);
        $flashMessage = "Feld \"{$label}\" hinzugefügt.";
        $flashType = 'success';
    }
}

// -----------------------------------------------------------------------
// POST: Feld loeschen
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_field') {
    $pdo->prepare('DELETE FROM connect_object_fields WHERE id = :id AND object_id = :object_id')
        ->execute(['id' => (int) ($_POST['field_id'] ?? 0), 'object_id' => $objectId]);
    $flashMessage = 'Feld gelöscht.';
    $flashType = 'success';
}

// -----------------------------------------------------------------------
// POST: neue Operation anlegen
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_operation') {
    $operationType = (string) ($_POST['operation_type'] ?? 'read_list');
    $pathTemplate = trim((string) ($_POST['path_template'] ?? ''));

    // Ohne Pfad wuerde GenericHttpConnector die Anfrage direkt an die
    // nackte Basis-Adresse schicken (z.B. https://shop.myshopify.com statt
    // .../admin/api/.../graphql.json) -- das fuehrt zu verwirrenden Fehlern
    // (z.B. eine "Access denied"-Seite statt eines echten API-Fehlers), da
    // die Anfrage technisch "funktioniert", nur an der falschen Adresse.
    // Gilt aber NUR fuer JSON/REST/GraphQL-Systeme -- bei XML-Systemen wie
    // MGM ist ein leerer Pfad der Normalfall (komplette Anfrage geht per
    // POST direkt an die Basis-Adresse, siehe GenericHttpConnector).
    if ($pathTemplate === '' && $object['request_format'] !== 'xml') {
        $flashMessage = 'Bitte eine Adresse (path_template) angeben, bevor die Operation angelegt wird.';
        $flashType = 'danger';
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params, created_at)
             VALUES (:object_id, :operation_type, :http_method, :path_template, NULL, NULL, NULL, CURRENT_TIMESTAMP)'
        );
        $insert->execute([
            'object_id' => $objectId,
            'operation_type' => $operationType,
            'http_method' => (string) ($_POST['http_method'] ?? 'GET'),
            'path_template' => $pathTemplate,
        ]);
        $flashMessage = "Operation \"{$operationType}\" angelegt — bitte Details (Body/Response-Pfad) auf der Detailseite ergänzen.";
        $flashType = 'success';
    }
}

// -----------------------------------------------------------------------
// POST: Operation loeschen
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_operation') {
    $pdo->prepare('DELETE FROM connect_object_operations WHERE id = :id AND object_id = :object_id')
        ->execute(['id' => (int) ($_POST['operation_id'] ?? 0), 'object_id' => $objectId]);
    $flashMessage = 'Operation gelöscht.';
    $flashType = 'success';
}

// -----------------------------------------------------------------------
// POST: Felder anhand einer echten Beispiel-Antwort erkennen (siehe Plan,
// "Test & Felder erkennen"). Persistiert nichts direkt -- zeigt nur eine
// Liste moeglicher Kandidaten an, die einzeln per "Übernehmen" in das
// bestehende "Feld hinzufügen"-Formular unten uebernommen werden koennen.
// Nur fuer Objects mit einer read_list-Operation moeglich (schreibende
// Objects wie der MGM-Auftrag haben keine Beispieldaten zum Entdecken).
// -----------------------------------------------------------------------
$discoveredFields = null; // null = noch nicht versucht, [] = versucht aber leer
$discoveredGroups = [];
$discoverError = null;
$introspectedFields = null;
$introspectedTypeName = '';
$introspectError = null;

// Gemeinsam von "Felder erkennen" (fetchRawSample) und "Schema erkunden"
// (introspectType) genutzt -- beide brauchen einen fertig aufgebauten
// GenericHttpConnector fuer eine gewaehlte Connection.
$buildGenericConnector = function (int $connectionId) use ($pdo, $config): \Connect\Connector\Generic\GenericHttpConnector {
    $tokenRefresher = TokenRefresher::fromConfig($config);
    $factory = new ConnectorFactory($pdo, new Crypto($config->require('CONNECT_ENCRYPTION_KEY')), new HttpClient(), $tokenRefresher);
    $connector = $factory->forConnection($connectionId);

    if (!($connector instanceof \Connect\Connector\Generic\GenericHttpConnector)) {
        throw new \RuntimeException('Unerwarteter Connector-Typ.');
    }

    return $connector;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'discover_fields') {
    $discoverConnectionId = (int) ($_POST['discover_connection_id'] ?? 0);

    try {
        $connector = $buildGenericConnector($discoverConnectionId);
        $sample = $connector->fetchRawSample($object['object_key'], \Connect\Sync\ShopFields::paramsForConnection($pdo, $discoverConnectionId, (string) $object['object_key'], []));

        if ($sample === null) {
            $discoverError = 'Keine Beispieldaten gefunden -- prüfe, ob eine "Lesen (Liste)"-Operation existiert und die Connection Daten liefert.';
        } else {
            $existingPathsStmt = $pdo->prepare('SELECT field_path FROM connect_object_fields WHERE object_id = :id AND field_path IS NOT NULL');
            $existingPathsStmt->execute(['id' => $objectId]);
            $existingPaths = array_flip($existingPathsStmt->fetchAll(PDO::FETCH_COLUMN));

            $flattened = connect_flatten_sample($sample);
            $discoveredFields = array_diff_key($flattened['fields'], $existingPaths);
            foreach ($flattened['groups'] as $groupPath => $group) {
                $group['children'] = array_diff_key($group['children'], $existingPaths);
                $discoveredGroups[$groupPath] = $group;
            }
        }
    } catch (\Throwable $e) {
        $discoverError = 'Beispiel-Abruf fehlgeschlagen: ' . $e->getMessage();
    }
}

// -----------------------------------------------------------------------
// POST: GraphQL-Schema-Introspection -- zeigt ALLE Felder, die ein Typ
// (z.B. "Order") im Schema tatsaechlich anbietet, unabhaengig davon, was die
// eigenen Operationen aktuell abfragen. Siehe GenericHttpConnector::introspectType().
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'introspect_schema') {
    $discoverConnectionId = (int) ($_POST['discover_connection_id'] ?? 0);
    $introspectedTypeName = trim((string) ($_POST['type_name'] ?? ''));

    if ($introspectedTypeName === '') {
        $introspectError = 'Bitte einen Typnamen angeben (z. B. "Order").';
    } else {
        try {
            $connector = $buildGenericConnector($discoverConnectionId);
            $introspectedFields = $connector->introspectType($introspectedTypeName);
        } catch (\Throwable $e) {
            $introspectError = $e->getMessage();
        }
    }
}

/**
 * Flacht eine rohe Beispiel-Antwort (EIN Datensatz, VOR mapRowToFields) zu
 * moeglichen Feld-Kandidaten auf. Wiederholende Gruppen (Listen von
 * Objekten, z.B. Shopify "edges") werden erkannt: die Gruppe selbst wird als
 * eigener Kandidat gemeldet, ihre Kind-Felder werden RELATIV zu einem
 * Element der Liste aufgeloest -- das entspricht der bestehenden Konvention
 * (siehe z.B. line_sku: field_path "node.sku", nicht die volle Pfadkette ab
 * der Wurzel), damit GenericHttpConnector::mapRepeatingGroup() sie korrekt
 * pro Zeile extrahieren kann.
 *
 * @param array<string, mixed> $data
 * @return array{fields: array<string, mixed>, groups: array<string, array{count: int, children: array<string, mixed>}>}
 */
function connect_flatten_sample(array $data, string $prefix = ''): array
{
    $fields = [];
    $groups = [];

    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (is_array($value) && $value !== [] && array_is_list($value)) {
            $childSample = is_array($value[0] ?? null) ? $value[0] : null;
            $groups[$path] = [
                'count' => count($value),
                'children' => $childSample !== null ? connect_flatten_sample($childSample)['fields'] : [],
            ];
        } elseif (is_array($value)) {
            $nested = connect_flatten_sample($value, $path);
            $fields += $nested['fields'];
            $groups += $nested['groups'];
        } else {
            $fields[$path] = $value;
        }
    }

    return ['fields' => $fields, 'groups' => $groups];
}

$fields = $pdo->prepare('SELECT * FROM connect_object_fields WHERE object_id = :id ORDER BY id');
$fields->execute(['id' => $objectId]);
$fields = $fields->fetchAll();

$operations = $pdo->prepare('SELECT * FROM connect_object_operations WHERE object_id = :id ORDER BY id');
$operations->execute(['id' => $objectId]);
$operations = $operations->fetchAll();

// Fuers "Felder erkennen": irgendeine funktionierende Connection dieses
// Connector Types reicht, egal welcher Shop -- gleiches Prinzip wie beim
// "Live testen" in operation_edit.php.
$discoverConnectionsStmt = $pdo->prepare('SELECT id, shop, label FROM connect_connections WHERE connector_type_id = :ctid ORDER BY shop');
$discoverConnectionsStmt->execute(['ctid' => $object['connector_type_id']]);
$discoverConnections = $discoverConnectionsStmt->fetchAll();

// Repeating-Group-Felder dieses Objects -- fuer die "Übergeordnetes Feld"-
// Dropdowns (ein Kind-Feld kann nur eine bereits als solche markierte
// Gruppe als Eltern haben).
$repeatingGroupFieldsForParentSelect = array_values(array_filter($fields, static fn($f) => (int) $f['is_repeating_group'] === 1));

$dataTypes = ['string', 'number', 'date', 'bool', 'array'];

$pageTitle = 'Object: ' . $object['label'];
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = []; // kein Shop-Bezug auf dieser Seite
$breadcrumbs = [
    ['connector_types.php', 'Connector Types'],
    ['connector_type_edit.php?id=' . (int) $object['connector_type_id'], $object['connector_type_name']],
    [null, $object['label']],
];
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?php echo \Connect\Connector\ConnectorLogo::imgForTypeId($pdo, (int) $object['connector_type_id']); ?>Stammdaten <span class="text-secondary fw-normal">· <?php echo htmlspecialchars((string) $object['connector_type_name']); ?></span></h3>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="update_object">
                    <div class="col-md-6">
                        <label class="form-label">Label</label>
                        <input type="text" name="label" class="form-control" value="<?php echo htmlspecialchars($object['label']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">object_key (nicht änderbar)</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($object['object_key']); ?>" disabled>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Zusätzliche Einstellungen (settings_schema, optional)</label>
                        <textarea name="settings_schema" class="form-control font-monospace" rows="3"><?php echo htmlspecialchars((string) $object['settings_schema']); ?></textarea>
                        <small class="text-secondary">Nur relevant für die spätere Synchronisations-Einrichtung (z. B. "Rechnungs-Mail versenden?") — kann für den Anfang leer bleiben.</small>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch pt-4">
                            <input type="checkbox" class="form-check-input" id="hydrate-details-toggle" name="hydrate_details_after_list" <?php echo ((int) $object['hydrate_details_after_list']) === 1 ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="hydrate-details-toggle">Nach jedem Listen-Sync Details pro Datensatz nachladen</label>
                        </div>
                        <small class="text-secondary">Für Felder, die read_list nicht liefert, aber read_one schon (z. B. HelloCash "Lagerbestand", der nur bei GET /articles/{id} dabei ist). Braucht eine "Lesen (einzeln)"-Operation weiter unten.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Batch-Größe für das Nachladen</label>
                        <input type="number" name="hydrate_batch_size" class="form-control" min="1" placeholder="Standard: 200" value="<?php echo htmlspecialchars((string) ($object['hydrate_batch_size'] ?? '')); ?>">
                        <small class="text-secondary">Wie viele Datensätze pro Sync-Lauf per Einzel-Abruf aktualisiert werden (Rest folgt im nächsten Lauf) — wichtig bei großen Katalogen, um nicht tausende Anfragen in einem Lauf zu machen.</small>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php
    $documentProfileSet = ($object['document_profile'] ?? null) !== null && (string) $object['document_profile'] !== '';
    $documentProfile = \Connect\Sync\Document\DocumentProfile::fromJson($documentProfileSet ? (string) $object['document_profile'] : null);
    // Fuer den Kunden-Vorschritt: Objects desselben Connector Types + Vorlagen
    // "Bestellung (aufbereitet) -> <Object dieses Typs>" fuer die Neuanlage.
    $sameTypeObjectsStmt = $pdo->prepare('SELECT object_key, label FROM connect_objects WHERE connector_type_id = :type_id ORDER BY label');
    $sameTypeObjectsStmt->execute(['type_id' => $object['connector_type_id']]);
    $sameTypeObjects = $sameTypeObjectsStmt->fetchAll();
    $customerTemplatesStmt = $pdo->prepare(
        "SELECT t.id, t.name FROM connect_mapping_templates t
         JOIN connect_objects so ON so.id = t.source_object_id
         JOIN connect_connector_types sct ON sct.id = so.connector_type_id
         WHERE t.target_connector_type_id = :type_id AND sct.type_key = 'connect_internal' AND so.object_key = 'order_prepared'
           AND t.target_object_id != :object_id
         ORDER BY t.name"
    );
    $customerTemplatesStmt->execute(['type_id' => $object['connector_type_id'], 'object_id' => $objectId]);
    $customerTemplates = $customerTemplatesStmt->fetchAll();
    ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Belegprofil (Rechnungen/Aufträge)</h3>
            </div>
            <div class="card-body">
                <p class="text-secondary small">Nur für Objects, in die Connect Rechnungen oder Aufträge aus Shopify-Bestellungen schreibt (z. B. HelloCash „Rechnung“, MGM „Auftrag“). Beschreibt, was das Zielsystem kann — die Shopify-Seite (Rabatte, Steuern, Versand) bereitet Connect selbst auf („Bestellung (aufbereitet)“). Die Felder <code>out_price</code>/<code>out_discount_unit</code>/<code>out_discount_value</code> der aufbereiteten Bestellung richten sich nach diesen Einstellungen.</p>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="update_document_profile">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="document-profile-enabled" name="document_profile_enabled" <?php echo $documentProfileSet ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="document-profile-enabled">Dieses Object ist ein Beleg (Rechnung/Auftrag)</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Rabatt an der Position</label>
                        <select name="discount_mode" class="form-select">
                            <?php foreach ([
                                'none' => 'Kein Rabattfeld (rabattierter Preis)',
                                'percent' => 'Nur Prozent',
                                'absolute' => 'Nur Betrag',
                                'auto' => 'Prozent wenn glatt, sonst Betrag',
                            ] as $modeValue => $modeLabel): ?>
                                <option value="<?php echo $modeValue; ?>" <?php echo $documentProfile->discountMode === $modeValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($modeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-secondary">Ist ein Rabatt so nicht exakt darstellbar, schickt Connect automatisch den rabattierten Preis — die Summe stimmt immer.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Betragsrabatt gilt …</label>
                        <select name="absolute_discount_scope" class="form-select">
                            <option value="line" <?php echo !$documentProfile->absoluteDiscountPerUnit ? 'selected' : ''; ?>>für die ganze Position</option>
                            <option value="unit" <?php echo $documentProfile->absoluteDiscountPerUnit ? 'selected' : ''; ?>>pro Stück</option>
                        </select>
                        <small class="text-secondary">HelloCash: ganze Position (getestet: 2 × 100 €, Rabatt 10 → 190 €).</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Nachkommastellen beim Prozentrabatt</label>
                        <input type="number" name="percent_decimals" class="form-control" min="0" max="4" value="<?php echo $documentProfile->percentDecimals; ?>">
                        <small class="text-secondary">HelloCash: 0 (rundet sonst selbst, z. B. 25,9 % → 26 %).</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Versand-Artikel im Zielsystem</label>
                        <input type="text" name="shipping_article_id" class="form-control font-monospace" placeholder="leer = Versand ohne Artikel" value="<?php echo htmlspecialchars((string) $documentProfile->shippingArticleId); ?>">
                        <small class="text-secondary">Artikelnummer/-ID für die Versandposition (MGM Crystalp: 9900). Steht in der aufbereiteten Bestellung als <code>target_article_id</code> und <code>sku</code> der Versandposition.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Antwort: Pfad zur Belegnummer</label>
                        <input type="text" name="document_number_path" class="form-control font-monospace" placeholder="z. B. data.invoice_number" value="<?php echo htmlspecialchars((string) $documentProfile->documentNumberPath); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Antwort: Pfad zur Belegsumme</label>
                        <input type="text" name="gross_total_path" class="form-control font-monospace" placeholder="z. B. data.gross_total" value="<?php echo htmlspecialchars((string) $documentProfile->grossTotalPath); ?>">
                        <small class="text-secondary">Für die Nach-Kontrolle gegen Shopifys Gesamtbetrag. Leer = keine Nach-Kontrolle.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Antwort: Pfad zum PDF (Base64)</label>
                        <input type="text" name="pdf_path" class="form-control font-monospace" placeholder="z. B. output.pdf" value="<?php echo htmlspecialchars((string) $documentProfile->pdfPath); ?>">
                        <small class="text-secondary">Für den Rechnungsversand per Mail. Leer = kein PDF-Versand.</small>
                    </div>

                    <div class="col-12 mt-3">
                        <h4 class="mb-1">Kunde zuordnen (optional)</h4>
                        <p class="text-secondary small mb-2">Vor dem Beleg den Kunden im Zielsystem suchen — zuerst per E-Mail, dann per Vor- und Nachname — und sonst über eine Vorlage „Bestellung (aufbereitet) → Kunde“ neu anlegen. Die gefundene/neue ID steht in der aufbereiteten Bestellung als <code>target_customer_id</code> zur Verfügung. Die Lesen-Operation des Kunden-Objects braucht dafür einen Platzhalter für den Suchbegriff (z. B. <code>customers?search={{search}}</code>).</p>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Kunden-Object</label>
                        <select name="customer_object_key" class="form-select">
                            <option value="">— kein Kunden-Vorschritt —</option>
                            <?php foreach ($sameTypeObjects as $sameTypeObject): ?>
                                <option value="<?php echo htmlspecialchars($sameTypeObject['object_key']); ?>" <?php echo $documentProfile->customerObjectKey === $sameTypeObject['object_key'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sameTypeObject['label'] . ' (' . $sameTypeObject['object_key'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Platzhalter für den Suchbegriff</label>
                        <input type="text" name="customer_search_param" class="form-control font-monospace" value="<?php echo htmlspecialchars($documentProfile->customerSearchParam); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Feld mit der Kunden-ID (Suchergebnis)</label>
                        <input type="text" name="customer_id_field" class="form-control font-monospace" value="<?php echo htmlspecialchars($documentProfile->customerIdField); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Feld mit der E-Mail (Suchergebnis)</label>
                        <input type="text" name="customer_email_field" class="form-control font-monospace" placeholder="leer = nicht per E-Mail suchen" value="<?php echo htmlspecialchars((string) $documentProfile->customerEmailField); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Feld mit dem Vornamen (Suchergebnis)</label>
                        <input type="text" name="customer_first_name_field" class="form-control font-monospace" placeholder="leer = nicht per Name suchen" value="<?php echo htmlspecialchars((string) $documentProfile->customerFirstNameField); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Feld mit dem Nachnamen (Suchergebnis)</label>
                        <input type="text" name="customer_last_name_field" class="form-control font-monospace" placeholder="leer = nicht per Name suchen" value="<?php echo htmlspecialchars((string) $documentProfile->customerLastNameField); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Feld mit der PLZ (Suchergebnis)</label>
                        <input type="text" name="customer_zip_field" class="form-control font-monospace" placeholder="z. B. postal_code" value="<?php echo htmlspecialchars((string) $documentProfile->customerZipField); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Feld mit dem Ort (Suchergebnis)</label>
                        <input type="text" name="customer_city_field" class="form-control font-monospace" placeholder="z. B. city" value="<?php echo htmlspecialchars((string) $documentProfile->customerCityField); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Feld mit der Straße (Suchergebnis)</label>
                        <input type="text" name="customer_street_field" class="form-control font-monospace" placeholder="z. B. street" value="<?php echo htmlspecialchars((string) $documentProfile->customerStreetField); ?>">
                    </div>
                    <div class="col-12">
                        <small class="text-secondary">Bei der Suche per Name müssen die eingetragenen Adressfelder zusätzlich passen (wie in der alten Welt) – gleichnamige Kunden werden so nicht verwechselt. Die Felder müssen am Kunden-Object angelegt sein. Leer = nur per Name.</small>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Vorlage für die Neuanlage</label>
                        <select name="customer_create_template_id" class="form-select">
                            <option value="">— nie neu anlegen —</option>
                            <?php foreach ($customerTemplates as $customerTemplate): ?>
                                <option value="<?php echo (int) $customerTemplate['id']; ?>" <?php echo $documentProfile->customerCreateTemplateId === (int) $customerTemplate['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($customerTemplate['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-secondary">Nur Vorlagen mit Quelle „Bestellung (aufbereitet)“ und Ziel in diesem System.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Antwort: Pfad zur ID des neuen Kunden</label>
                        <input type="text" name="customer_created_id_path" class="form-control font-monospace" value="<?php echo htmlspecialchars($documentProfile->customerCreatedIdPath); ?>">
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Belegprofil speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Felder</h3>
            </div>
            <?php if (count($fields) > 0): ?>
                <div class="card-body pb-0 text-secondary">
                    <small>
                        <strong>Pfad</strong>: wo dieser Wert in der Rohantwort steht (z. B. <code>node.title</code>).
                        <strong>Wiederholende Gruppe</strong>: für Felder, die mehrfach vorkommen können (z. B. Bestellpositionen).
                        <strong>Eindeutige Kennung</strong>: markiert das Feld, an dem ein Datensatz wiedererkannt wird (z. B. die Shopify-ID) — genau ein Feld pro Object.
                        <strong>Vergleichswert</strong> und <strong>Lagerbestand</strong>: nur für den automatischen Lagerbestand-Abgleich (Connect\Sync\StockSyncRunner) — markiert, welches Feld dem shop-weiten Vergleichswert (Titel/SKU/Barcode) bzw. dem Lagerbestand entspricht, je genau eines pro Object.
                        Die Rolle <strong>Preis</strong> ist ein Sonderfall: nicht als shop-weiter Vergleichswert wählbar (kein sinnvoller Vergleichswert), sondern nur dafür markiert, welches Feld beim Anlegen eines fehlenden Produkts in Shopify (Connect\Sync\ProductImportService) als Preis übernommen wird.
                    </small>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="update_fields">
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Interner Name</th>
                                    <th>Label</th>
                                    <th title="Wo dieser Wert in der Rohantwort steht, z. B. node.title">Pfad</th>
                                    <th>Typ</th>
                                    <th title="Für Felder, die mehrfach vorkommen können (z. B. Bestellpositionen)">Wiederholende Gruppe</th>
                                    <th title="Markiert das Feld, an dem ein Datensatz wiedererkannt wird (genau eines pro Object)">Eindeutige Kennung</th>
                                    <th title="Fuer den Lagerbestand-Abgleich/Produkt-Import: welchem shop-weiten Vergleichswert (Titel/SKU/Barcode) entspricht dieses Feld -- je Rolle genau ein Feld pro Object. Der &quot;Varianten-Vergleichswert&quot; fuer den Produkt-Import (siehe Einstellungen) nutzt ebenfalls eine dieser Rollen, nur eine andere als der aktive Vergleichswert. Die Rolle &quot;Preis&quot; ist ein Sonderfall (siehe Hinweistext oben) -- kein waehlbarer Vergleichswert, nur fuer die Preis-Uebernahme beim Anlegen in Shopify">Vergleichswert</th>
                                    <th title="Fuer den Lagerbestand-Abgleich: traegt dieses Feld den Lagerbestand -- genau eines pro Object">Lagerbestand</th>
                                    <th title="Optional: maximale Zeichenlaenge dieses Feldes im System (z. B. HelloCash Artikel-Code: 20). Die Produkte-Seite meldet Datensaetze des Gegensystems (gleiche Vergleichswert-Rolle), die dafuer zu lang waeren, und ein Import mit zu langem Wert wird abgebrochen. Leer = kein Limit">Max. Länge</th>
                                    <th title="Nur bei Feldern INNERHALB einer wiederholenden Gruppe (z. B. Menge/SKU einer Bestellposition) -- welche Gruppe dieses Feld enthält">Übergeordnetes Feld</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fields as $field): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($field['field_key']); ?></code></td>
                                        <td><input type="text" class="form-control form-control-sm" name="fields[<?php echo (int) $field['id']; ?>][label]" value="<?php echo htmlspecialchars($field['label']); ?>"></td>
                                        <td><input type="text" class="form-control form-control-sm font-monospace" name="fields[<?php echo (int) $field['id']; ?>][field_path]" value="<?php echo htmlspecialchars((string) $field['field_path']); ?>"></td>
                                        <td>
                                            <select class="form-select form-select-sm" name="fields[<?php echo (int) $field['id']; ?>][data_type]">
                                                <?php foreach ($dataTypes as $dt): ?>
                                                    <option value="<?php echo $dt; ?>" <?php echo $field['data_type'] === $dt ? 'selected' : ''; ?>><?php echo $dt; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input" name="fields[<?php echo (int) $field['id']; ?>][is_repeating_group]" <?php echo $field['is_repeating_group'] ? 'checked' : ''; ?>></td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input" name="fields[<?php echo (int) $field['id']; ?>][is_identifier]" <?php echo $field['is_identifier'] ? 'checked' : ''; ?>></td>
                                        <td>
                                            <select class="form-select form-select-sm" name="fields[<?php echo (int) $field['id']; ?>][comparison_role]">
                                                <?php foreach (['' => '–', 'title' => 'Titel', 'sku' => 'SKU', 'barcode' => 'Barcode', 'price' => 'Preis (brutto)', 'price_net' => 'Preis netto'] as $roleValue => $roleLabel): ?>
                                                    <option value="<?php echo $roleValue; ?>" <?php echo ((string) $field['comparison_role']) === $roleValue ? 'selected' : ''; ?>><?php echo $roleLabel; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input" name="fields[<?php echo (int) $field['id']; ?>][is_stock_quantity]" <?php echo $field['is_stock_quantity'] ? 'checked' : ''; ?>></td>
                                        <td><input type="number" min="1" class="form-control form-control-sm" style="width:80px" name="fields[<?php echo (int) $field['id']; ?>][max_length]" value="<?php echo ($field['max_length'] ?? null) !== null ? (int) $field['max_length'] : ''; ?>"></td>
                                        <td>
                                            <select class="form-select form-select-sm" name="fields[<?php echo (int) $field['id']; ?>][parent_field_id]">
                                                <option value="">-- keins --</option>
                                                <?php foreach ($repeatingGroupFieldsForParentSelect as $groupField): ?>
                                                    <?php if ((int) $groupField['id'] === (int) $field['id']) continue; ?>
                                                    <option value="<?php echo (int) $groupField['id']; ?>" <?php echo (int) ($field['parent_field_id'] ?? 0) === (int) $groupField['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($groupField['label']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <button type="submit" form="delete-field-<?php echo (int) $field['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Feld wirklich löschen?')">×</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">Alle Felder speichern</button>
                    </div>
                </form>
                <?php foreach ($fields as $field): ?>
                    <form id="delete-field-<?php echo (int) $field['id']; ?>" method="post" class="d-none">
                        <input type="hidden" name="action" value="delete_field">
                        <input type="hidden" name="field_id" value="<?php echo (int) $field['id']; ?>">
                    </form>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="card-body text-secondary">Noch keine Felder definiert.</div>
            <?php endif; ?>
            <div class="card-footer">
                <form method="post" class="row g-2" id="add-field-form">
                    <input type="hidden" name="action" value="add_field">
                    <div class="col-auto">
                        <input type="text" name="field_key" id="add-field-key" class="form-control" placeholder="Interner Name, z.B. sku" required>
                    </div>
                    <div class="col-auto">
                        <input type="text" name="label" id="add-field-label" class="form-control" placeholder="Anzeigename, z.B. Artikelnummer" required>
                    </div>
                    <div class="col-auto">
                        <input type="text" name="field_path" id="add-field-path" class="form-control font-monospace" placeholder="Pfad in der Antwort, z.B. node.title">
                    </div>
                    <div class="col-auto">
                        <select name="data_type" id="add-field-data-type" class="form-select">
                            <?php foreach ($dataTypes as $dt): ?>
                                <option value="<?php echo $dt; ?>"><?php echo $dt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <select name="parent_field_id" id="add-field-parent" class="form-select">
                            <option value="">-- kein übergeordnetes Feld --</option>
                            <?php foreach ($repeatingGroupFieldsForParentSelect as $groupField): ?>
                                <option value="<?php echo (int) $groupField['id']; ?>"><?php echo htmlspecialchars($groupField['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-outline-primary">Feld hinzufügen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Felder erkennen</h3>
            </div>
            <div class="card-body">
                <p class="text-secondary">
                    Holt einen echten Beispiel-Datensatz live von der Quelle und zeigt, welche Felder darin stecken --
                    inkl. wiederholender Gruppen (z. B. Bestellpositionen). Nur möglich, wenn eine "Lesen (Liste)"-Operation
                    existiert (bei reinen Schreib-Objects wie dem MGM-Auftrag nicht verfügbar).
                </p>
                <?php if (count($discoverConnections) === 0): ?>
                    <div class="text-secondary">Keine Connection dieses Connector Types vorhanden zum Testen.</div>
                <?php else: ?>
                    <form method="post" class="row g-2 mb-3">
                        <input type="hidden" name="action" value="discover_fields">
                        <div class="col-auto">
                            <select name="discover_connection_id" class="form-select">
                                <?php foreach ($discoverConnections as $connection): ?>
                                    <option value="<?php echo (int) $connection['id']; ?>"><?php echo htmlspecialchars($connection['shop'] . ' — ' . $connection['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-outline-primary">Beispiel laden</button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($discoverError !== null): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($discoverError); ?></div>
                <?php elseif ($discoveredFields !== null): ?>
                    <?php if (count($discoveredFields) === 0 && count($discoveredGroups) === 0): ?>
                        <div class="text-secondary">Keine neuen Felder gefunden — vermutlich sind schon alle vorhandenen Felder erkannt.</div>
                    <?php else: ?>
                        <?php if (count($discoveredFields) > 0): ?>
                            <table class="table table-vcenter table-sm">
                                <thead>
                                    <tr>
                                        <th>Pfad</th>
                                        <th>Beispielwert</th>
                                        <th class="w-1"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($discoveredFields as $path => $value): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($path); ?></code></td>
                                            <td class="text-secondary"><?php echo htmlspecialchars(mb_substr((string) $value, 0, 80)); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary"
                                                    onclick="connectAdoptField('<?php echo htmlspecialchars($path, ENT_QUOTES); ?>')">
                                                    Übernehmen
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <?php foreach ($discoveredGroups as $groupPath => $group): ?>
                            <h4 class="mt-3">Wiederholende Gruppe: <code><?php echo htmlspecialchars($groupPath); ?></code> (<?php echo (int) $group['count']; ?> Beispiel-Einträge)</h4>
                            <p class="text-secondary mb-1">
                                Zuerst dieses Gruppen-Feld selbst übernehmen (Pfad wie angezeigt, "Wiederholende Gruppe"
                                danach in der Felder-Tabelle oben anhaken), dann erst die Kind-Felder darunter — die
                                brauchen das Gruppen-Feld als "Übergeordnetes Feld".
                            </p>
                            <button type="button" class="btn btn-sm btn-outline-primary mb-2"
                                onclick="connectAdoptField('<?php echo htmlspecialchars($groupPath, ENT_QUOTES); ?>')">
                                Gruppen-Feld übernehmen
                            </button>
                            <?php if (count($group['children']) > 0): ?>
                                <table class="table table-vcenter table-sm">
                                    <thead>
                                        <tr>
                                            <th>Pfad (relativ zu einem Element)</th>
                                            <th>Beispielwert</th>
                                            <th class="w-1"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($group['children'] as $childPath => $value): ?>
                                            <tr>
                                                <td><code><?php echo htmlspecialchars($childPath); ?></code></td>
                                                <td class="text-secondary"><?php echo htmlspecialchars(mb_substr((string) $value, 0, 80)); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                                        onclick="connectAdoptField('<?php echo htmlspecialchars($childPath, ENT_QUOTES); ?>')">
                                                        Übernehmen
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($object['request_format'] === 'json'): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Schema erkunden</h3>
                </div>
                <div class="card-body">
                    <p class="text-secondary">
                        Fragt per GraphQL-Introspection direkt beim Schema der Quelle ab, welche Felder ein Typ
                        <strong>tatsächlich anbietet</strong> — unabhängig davon, was die eigenen Operationen aktuell
                        abfragen. Z. B. "Order" für alle möglichen Bestellungs-Felder, oder "Customer"/"MailingAddress"
                        für verschachtelte Unterobjekte.
                    </p>
                    <?php if (count($discoverConnections) === 0): ?>
                        <div class="text-secondary">Keine Connection dieses Connector Types vorhanden zum Testen.</div>
                    <?php else: ?>
                        <form method="post" class="row g-2 mb-3" id="introspect-form">
                            <input type="hidden" name="action" value="introspect_schema">
                            <div class="col-auto">
                                <select name="discover_connection_id" class="form-select">
                                    <?php foreach ($discoverConnections as $connection): ?>
                                        <option value="<?php echo (int) $connection['id']; ?>"><?php echo htmlspecialchars($connection['shop'] . ' — ' . $connection['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <input type="text" name="type_name" id="introspect-type-name" class="form-control font-monospace"
                                    value="<?php echo htmlspecialchars($introspectedTypeName !== '' ? $introspectedTypeName : ucfirst($object['object_key'])); ?>"
                                    placeholder="z. B. Order">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-outline-primary">Schema laden</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($introspectError !== null): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($introspectError); ?></div>
                    <?php elseif ($introspectedFields !== null): ?>
                        <?php if (count($introspectedFields) === 0): ?>
                            <div class="text-secondary">Dieser Typ hat keine Felder.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-vcenter table-sm">
                                    <thead>
                                        <tr>
                                            <th>Feldname</th>
                                            <th>Typ</th>
                                            <th>Beschreibung</th>
                                            <th class="w-1"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($introspectedFields as $field): ?>
                                            <?php
                                            // Ein "erkundbarer" Typ ist ein einfacher Objektname ohne
                                            // GraphQL-Wrapper-Zeichen (! oder []) und kein eingebauter
                                            // Skalar -- fuer den lohnt sich ein Klick zum Weitererkunden
                                            // (z.B. "Customer", nicht "String!" oder "[LineItem!]!").
                                            $bareType = str_replace(['!', '[', ']'], '', $field['typeLabel']);
                                            $isExplorable = $bareType === $field['typeLabel']
                                                && !in_array($bareType, ['String', 'Int', 'Float', 'Boolean', 'ID'], true);
                                            ?>
                                            <tr>
                                                <td><code><?php echo htmlspecialchars($field['name']); ?></code></td>
                                                <td class="text-secondary"><?php echo htmlspecialchars($field['typeLabel']); ?></td>
                                                <td class="text-secondary"><?php echo htmlspecialchars(mb_substr((string) ($field['description'] ?? ''), 0, 100)); ?></td>
                                                <td class="text-nowrap">
                                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                                        onclick="connectAdoptField('<?php echo htmlspecialchars($field['name'], ENT_QUOTES); ?>')">
                                                        Übernehmen
                                                    </button>
                                                    <?php if ($isExplorable): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                            onclick="connectExploreType('<?php echo htmlspecialchars($bareType, ENT_QUOTES); ?>')">
                                                            Typ erkunden
                                                        </button>
                                                    <?php endif; ?>
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
    <?php endif; ?>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Operationen</h3>
            </div>
            <?php if (count($operations) === 0): ?>
                <div class="card-body text-secondary">Noch keine Operation definiert.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Typ</th>
                                <th>Methode</th>
                                <th>Pfad</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $opLabels = ['read_list' => 'Lesen (Liste)', 'read_list_supplement' => 'Lesen (Zusatzliste)', 'read_one' => 'Lesen (einzeln)', 'create' => 'Anlegen', 'update' => 'Aktualisieren', 'update_price' => 'Preis aktualisieren', 'read_pdf' => 'PDF abrufen']; ?>
                            <?php foreach ($operations as $operation): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($opLabels[$operation['operation_type']] ?? $operation['operation_type']); ?></td>
                                    <td><?php echo htmlspecialchars($operation['http_method']); ?></td>
                                    <td><code><?php echo htmlspecialchars($operation['path_template']); ?></code></td>
                                    <td class="text-end text-nowrap">
                                        <a href="operation_edit.php?id=<?php echo (int) $operation['id']; ?>" class="btn btn-sm btn-outline-primary">Bearbeiten</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Operation wirklich entfernen?');">
                                            <input type="hidden" name="action" value="delete_operation">
                                            <input type="hidden" name="operation_id" value="<?php echo (int) $operation['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Entfernen</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <div class="card-footer">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="create_operation">
                    <div class="col-auto">
                        <select name="operation_type" class="form-select">
                            <option value="read_list">Lesen, Liste (read_list)</option>
                            <option value="read_one">Lesen, einzeln (read_one)</option>
                            <option value="read_list_supplement">Lesen, Zusatzliste (read_list_supplement)</option>
                            <option value="create">Anlegen (create)</option>
                            <option value="update">Aktualisieren (update)</option>
                            <option value="update_price">Preis aktualisieren (update_price)</option>
                            <option value="read_pdf">PDF abrufen (read_pdf)</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <select name="http_method" class="form-select">
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                            <option value="PUT">PUT</option>
                            <option value="DELETE">DELETE</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <input type="text" name="path_template" class="form-control font-monospace" placeholder="Pfad, z.B. admin/api/2026-07/graphql.json (bei XML-Systemen wie MGM leer lassen möglich)" style="min-width:280px">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-outline-primary">Operation hinzufügen</button>
                    </div>
                    <div class="col-12">
                        <small class="text-secondary">
                            Bei GraphQL-Systemen (z. B. Shopify) immer <strong>POST</strong> wählen, auch zum Lesen — Details danach auf der Bearbeiten-Seite der neuen Operation ergänzen.
                        </small>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Uebernimmt einen erkannten Feld-Kandidaten in das "Feld hinzufügen"-
    // Formular unten -- fuellt nur aus, sendet nicht automatisch ab, damit
    // field_key/Label noch von Hand geprueft/verschoenert werden koennen
    // (siehe Diskussion: sprechende Namen wie "shopify_id" statt "id" lassen
    // sich nicht zuverlaessig automatisch erraten).
    function connectAdoptField(path) {
        var segments = path.split('.');
        var lastSegment = segments[segments.length - 1];
        // camelCase -> snake_case als Vorschlag, z.B. "firstName" -> "first_name".
        var suggestedKey = lastSegment.replace(/([a-z0-9])([A-Z])/g, '$1_$2').toLowerCase();

        document.getElementById('add-field-key').value = suggestedKey;
        document.getElementById('add-field-label').value = '';
        document.getElementById('add-field-path').value = path;
        document.getElementById('add-field-form').scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
        document.getElementById('add-field-label').focus();
    }

    // Weiter-Erkunden: fuellt den Typnamen (z.B. "Customer") in die
    // Introspection-Form und sendet sie gleich ab -- so laesst sich das
    // Schema Schritt fuer Schritt durch verschachtelte Objekte durchklicken,
    // ohne den Typnamen von Hand abzutippen.
    function connectExploreType(typeName) {
        document.getElementById('introspect-type-name').value = typeName;
        document.getElementById('introspect-form').submit();
    }
</script>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>