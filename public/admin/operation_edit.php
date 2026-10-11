<?php

declare(strict_types=1);

/**
 * connect/public/admin/operation_edit.php
 *
 * Details einer Operation (body_template kann lange GraphQL-Queries oder
 * XML-Templates enthalten, braucht mehr Platz als eine Tabellenzelle).
 * Fuer read_list-Operationen gibt es zusaetzlich einen "Testen"-Knopf, der
 * die Operation direkt gegen eine echte Connection ausfuehrt -- genau das,
 * was uns bei den bisherigen GraphQL-Korrekturen mehrfach gefehlt hat.
 */

use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Shopify\TokenRefresher;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$operationId = (int) ($_GET['id'] ?? 0);
$operationStmt = $pdo->prepare(
    'SELECT op.*, o.id AS object_id, o.object_key, o.label AS object_label, o.connector_type_id, t.name AS connector_type_name, t.request_format
     FROM connect_object_operations op
     JOIN connect_objects o ON o.id = op.object_id
     JOIN connect_connector_types t ON t.id = o.connector_type_id
     WHERE op.id = :id'
);
$operationStmt->execute(['id' => $operationId]);
$operation = $operationStmt->fetch();

if ($operation === false) {
    \Connect\Http\ErrorPage::show(
        404,
        'Operation nicht gefunden',
        'Dieser Eintrag existiert nicht (mehr) oder wurde gelöscht.',
        ['label' => 'Zu den Connector Types', 'url' => 'connector_types.php']
    );
}

$flashMessage = null;
$flashType = 'info';
$testOutput = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_operation') {
    $pathTemplate = trim((string) ($_POST['path_template'] ?? ''));
    $staticParams = trim((string) ($_POST['static_params'] ?? ''));

    if ($pathTemplate === '' && $operation['request_format'] !== 'xml') {
        // Ohne Pfad wuerde die Anfrage an die nackte Basis-Adresse gehen
        // statt an den eigentlichen API-Endpunkt -- das ist bei JSON/REST/
        // GraphQL-Systemen (Shopify) immer ein Fehler (siehe Kommentar in
        // object_edit.php, der ursprüngliche "Access denied"-Fall). Bei
        // XML-Systemen wie MGM ist ein LEERER Pfad dagegen der Normalfall:
        // die komplette Anfrage geht per POST direkt an die Basis-Adresse
        // (siehe GenericHttpConnector::executeRequest()) -- dort also nicht
        // erzwingen.
        $flashMessage = 'Die Adresse (path_template) darf nicht leer sein.';
        $flashType = 'danger';
    } elseif ($staticParams !== '') {
        json_decode($staticParams);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $flashMessage = 'static_params ist kein gültiges JSON: ' . json_last_error_msg();
            $flashType = 'danger';
        }
    }

    if ($flashMessage === null) {
        $update = $pdo->prepare(
            'UPDATE connect_object_operations
             SET http_method = :http_method, path_template = :path_template, body_template = :body_template,
                 response_records_path = :response_records_path, static_params = :static_params
             WHERE id = :id'
        );
        $update->execute([
            'http_method' => (string) ($_POST['http_method'] ?? 'GET'),
            'path_template' => $pathTemplate,
            'body_template' => ((string) ($_POST['body_template'] ?? '')) !== '' ? $_POST['body_template'] : null,
            'response_records_path' => ((string) ($_POST['response_records_path'] ?? '')) !== '' ? $_POST['response_records_path'] : null,
            'static_params' => $staticParams !== '' ? $staticParams : null,
            'id' => $operationId,
        ]);
        $flashMessage = 'Gespeichert.';
        $flashType = 'success';

        $operationStmt->execute(['id' => $operationId]);
        $operation = $operationStmt->fetch();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_operation') {
    $testConnectionId = (int) ($_POST['test_connection_id'] ?? 0);

    if ($testConnectionId <= 0) {
        $flashMessage = 'Bitte eine Connection zum Testen auswählen.';
        $flashType = 'danger';
    } else {
        try {
            $tokenRefresher = new TokenRefresher(
                new HttpClient(),
                $config->optional('CONNECT_SHOPIFY_API_KEY'),
                $config->optional('CONNECT_SHOPIFY_API_SECRET')
            );
            $factory = new ConnectorFactory($pdo, new \Connect\Security\Crypto($config->require('CONNECT_ENCRYPTION_KEY')), new HttpClient(), $tokenRefresher);
            $connector = $factory->forConnection($testConnectionId);

            if ($operation['operation_type'] === 'read_list_supplement' && $connector instanceof \Connect\Connector\Generic\GenericHttpConnector) {
                // Zusatzliste: alle Zeilen lesen (nur lesend), 3 als Beispiel, und zaehlen, wie viele
                // zu gecachten Datensaetzen dieser Connection passen -- Schreiben passiert hier nicht.
                $mergeConfig = $connector->supplementConfig((string) $operation['object_key']);
                $rowsTotal = 0;
                $sample = [];
                $keys = [];
                foreach ($connector->readSupplement((string) $operation['object_key']) as $row) {
                    $rowsTotal++;
                    if (count($sample) < 3) {
                        $sample[] = $row;
                    }
                    if ($mergeConfig !== null && is_array($row)) {
                        $key = $connector::valueAtPath($row, $mergeConfig['match']);
                        if (is_scalar($key) && (string) $key !== '') {
                            $keys[(string) $key] = true;
                        }
                    }
                }
                $cachedIds = $pdo->prepare("SELECT external_id FROM connect_object_records WHERE connection_id = :c AND object_key = :o AND status = 'active'");
                $cachedIds->execute(['c' => $testConnectionId, 'o' => (string) $operation['object_key']]);
                $cached = $cachedIds->fetchAll(\PDO::FETCH_COLUMN);
                $matchedCount = count(array_filter($cached, static fn ($id): bool => isset($keys[(string) $id])));
                // Feldschluessel aus _merge gegen die Felder des Objects pruefen (z.B. Lagerbestand-Feld)
                $objectFields = $pdo->prepare('SELECT field_key, is_stock_quantity FROM connect_object_fields WHERE object_id = :id');
                $objectFields->execute(['id' => (int) $operation['object_id']]);
                $objectFieldRows = $objectFields->fetchAll();
                $fieldKeys = array_column($objectFieldRows, 'field_key');
                $stockKeys = array_column(array_filter($objectFieldRows, static fn (array $f): bool => (int) $f['is_stock_quantity'] === 1), 'field_key');
                $fieldWarning = '';
                if ($mergeConfig !== null) {
                    $unknownKeys = array_diff(array_keys($mergeConfig['fields']), $fieldKeys);
                    if ($unknownKeys !== []) {
                        $fieldWarning .= 'ACHTUNG: Feld(er) ' . implode(', ', $unknownKeys) . " gibt es an diesem Object nicht.\n";
                    }
                    if ($stockKeys !== [] && array_intersect($stockKeys, array_keys($mergeConfig['fields'])) === []) {
                        $fieldWarning .= 'Hinweis: Das Lagerbestand-Feld dieses Objects heißt "' . implode(', ', $stockKeys) . "\" – soll die Zusatzliste den Bestand liefern, diesen Schlüssel in _merge.fields verwenden.\n";
                    }
                }
                $testOutput = "Erfolgreich: {$rowsTotal} Zeilen gelesen.\n"
                    . ($mergeConfig === null
                        ? "ACHTUNG: static_params enthält kein gültiges \"_merge\" – es wird nichts übernommen.\n"
                        : "Zuordnung über \"{$mergeConfig['match']}\": {$matchedCount} von " . count($cached) . " gecachten Datensätzen dieser Connection passen.\n"
                            . 'Felder: ' . json_encode($mergeConfig['fields'], JSON_UNESCAPED_UNICODE) . "\n" . $fieldWarning)
                    . "\n3 Beispielzeilen (roh):\n" . json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $flashMessage = 'Test erfolgreich.';
                $flashType = 'success';
                throw new \Connect\Sync\TestDone();
            }

            $count = 0;
            $sample = [];
            // Shopify-Produkt: Metafield-Plaetze der Shop-Felder muessen mitgeschickt werden (Migration 0074)
            $testParams = \Connect\Sync\ShopFields::paramsForConnection($pdo, $testConnectionId, (string) $operation['object_key'], []);
            foreach ($connector->read($operation['object_key'], $testParams) as $row) {
                $sample[] = $row;
                $count++;
                if ($count >= 3) {
                    break;
                }
            }
            $testOutput = "Erfolgreich, {$count} Beispiel-Datensätze:\n" . json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $flashMessage = 'Test erfolgreich.';
            $flashType = 'success';
        } catch (\Connect\Sync\TestDone) {
            // Zusatzliste getestet, Ausgabe steht schon
        } catch (\Throwable $e) {
            $testOutput = $e->getMessage();
            $flashMessage = 'Test fehlgeschlagen — siehe Ausgabe unten.';
            $flashType = 'danger';
        }
    }
}

// Connections desselben Connector Type, egal welcher Shop -- fuers Testen
// reicht irgendeine funktionierende Connection.
$testConnections = $pdo->prepare(
    'SELECT id, shop, label FROM connect_connections WHERE connector_type_id = :ctid ORDER BY shop'
);
$testConnections->execute(['ctid' => $operation['connector_type_id']]);
$testConnections = $testConnections->fetchAll();

$operationTypeLabels = ['read_list' => 'Lesen (Liste)', 'read_list_supplement' => 'Lesen (Zusatzliste)', 'read_one' => 'Lesen (einzeln)', 'create' => 'Anlegen', 'update' => 'Aktualisieren', 'update_price' => 'Preis aktualisieren', 'read_pdf' => 'PDF abrufen'];
$operationTypeLabel = $operationTypeLabels[$operation['operation_type']] ?? $operation['operation_type'];

$pageTitle = 'Operation: ' . $operationTypeLabel . ' (' . $operation['object_label'] . ')';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = []; // kein Shop-Bezug auf dieser Seite
$breadcrumbs = [
    ['connector_types.php', 'Connector Types'],
    ['connector_type_edit.php?id=' . (int) $operation['connector_type_id'], $operation['connector_type_name']],
    ['object_edit.php?id=' . (int) $operation['object_id'], $operation['object_label']],
    [null, $operationTypeLabel],
];
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Details</h3>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="update_operation">
                    <div class="col-md-3">
                        <label class="form-label">HTTP-Methode</label>
                        <select name="http_method" class="form-select">
                            <?php foreach (['GET', 'POST', 'PUT', 'DELETE'] as $m): ?>
                                <option value="<?php echo $m; ?>" <?php echo $operation['http_method'] === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-secondary">
                            <strong>Wichtig bei GraphQL</strong> (z. B. Shopify): hier <strong>immer POST</strong> wählen, auch beim
                            Lesen — ob gelesen oder geschrieben wird, entscheidet die Query selbst (oben), nicht die HTTP-Methode.
                            Nur bei einfachen Nicht-GraphQL-Abfragen (z. B. die MGM-Steuerabfrage) ist GET zum Lesen üblich.
                        </small>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Adresse (path_template)</label>
                        <input type="text" name="path_template" class="form-control font-monospace" value="<?php echo htmlspecialchars($operation['path_template']); ?>">
                        <small class="text-secondary">Der Teil der URL nach der Basis-Adresse der Connection, z. B. <code>admin/api/2026-07/graphql.json</code>. Bei XML-Systemen wie MGM leer lassen, wenn die komplette Anfrage direkt an die Basis-Adresse geht. Eine vollständige Adresse mit <code>https://</code> wird unverändert verwendet (z. B. für eine andere API-Version).</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Anfrage-Inhalt (body_template)</label>
                        <small class="text-secondary d-block mb-1">
                            Was beim Aufruf mitgeschickt wird.
                            <?php if ($operation['http_method'] === 'POST'): ?>
                                Bei GraphQL (json): die Abfrage als Text mit <code>$variablen</code>, z. B. <code>query($cursor: String) { ... }</code> — Shopify füllt <code>$cursor</code> dann selbst.
                                Bei XML: ein Text-Muster mit <code>{{platzhalter}}</code>, die durch echte Werte ersetzt werden, und <code>{{#each gruppe}}...{{/each}}</code> für wiederholende Abschnitte (z. B. mehrere Bestellpositionen).
                            <?php else: ?>
                                Bei einer einfachen Abfrage (GET) meist leer lassen.
                            <?php endif; ?>
                        </small>
                        <textarea name="body_template" class="form-control font-monospace" rows="8"><?php echo htmlspecialchars((string) $operation['body_template']); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Wo die Liste in der Antwort beginnt (response_records_path)</label>
                        <input type="text" name="response_records_path" class="form-control font-monospace" value="<?php echo htmlspecialchars((string) $operation['response_records_path']); ?>" placeholder="z.B. data.products.edges">
                        <small class="text-secondary">Die Antwort ist meist verschachtelt (z. B. <code>{"data":{"products":{"edges":[...]}}}</code>) — hier steht der Pfad zur eigentlichen Liste, mit Punkten getrennt.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Feste Zusatzwerte (static_params)</label>
                        <input type="text" name="static_params" class="form-control font-monospace" value="<?php echo htmlspecialchars((string) $operation['static_params']); ?>" placeholder='{"GfKreis":"001"}'>
                        <small class="text-secondary">Werte, die bei <strong>jedem</strong> Aufruf automatisch mitgeschickt werden, unabhängig von den eigentlichen Daten — z. B. ein bei MGM immer gleicher Code. Als JSON, z. B. <code>{"GfKreis":"001"}</code>. Meist leer lassen.</small>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($operation['operation_type'] === 'read_list_supplement'): ?>
        <div class="col-12">
            <div class="alert alert-info mb-0">
                <strong>Zusatzliste:</strong> Wird bei jedem Abgleich dieses Objects direkt nach der normalen Liste gelesen. Je Zeile sucht Connect den gecachten Datensatz mit derselben Kennung und setzt dort die angegebenen Felder – für Werte, die die normale Liste nicht enthält (sonst nur per Einzelabruf).
                Konfiguration in <code>static_params</code>:
                <ul class="mb-1">
                    <li><code>_merge.match</code> – Pfad der Kennung in der Zeile (muss der Kennung des Objects entsprechen)</li>
                    <li><code>_merge.fields</code> – <code>{"Feldschlüssel des Objects": "Pfad in der Zeile"}</code></li>
                    <li><code>_page_size</code> – optional: weiterblättern per <code>{{offset}}</code>, solange eine volle Seite kommt</li>
                    <li><code>_offset_mode</code> – optional <code>"page"</code>: <code>{{offset}}</code> ist die Seitennummer ab 1 (helloCash v1) statt der Anzahl übersprungener Datensätze ab 0</li>
                </ul>
                Beispiel helloCash-Bestand aus der v1-Liste: Adresse <code>https://api.hellocash.business/api/v1/articles?limit=1000&amp;offset={{offset}}</code>, Liste <code>articles</code>,
                static_params <code>{"_page_size":1000,"_offset_mode":"page","_merge":{"match":"article_id","fields":{"&lt;Bestandsfeld&gt;":"article_stock"}}}</code>.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($operation['operation_type'] === 'read_pdf'): ?>
        <div class="col-12">
            <div class="alert alert-info mb-0">
                <strong>PDF abrufen:</strong> Für den Knopf „PDF“ in der Rechnungsübersicht (Merchant und Admin). Platzhalter in der Adresse:
                <code>{{id}}</code> = interne ID des Belegs aus der Antwort beim Anlegen (<code>data.id</code> bzw. <code>id</code>),
                <code>{{number}}</code> = Belegnummer laut Belegprofil (Spalte „Rechnungs-Nr.“). Connect reicht das PDF direkt weiter – gespeichert wird nichts.
                Antwortet das System mit JSON statt mit dem PDF, trägst du bei <code>response_records_path</code> den Pfad zum Base64-Wert ein.
                Beispiel helloCash v2: GET <code>invoices/{{id}}/pdf?locale=de_AT</code>, <code>response_records_path</code> leer.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($operation['operation_type'] === 'read_list' || $operation['operation_type'] === 'read_list_supplement'): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Testen</h3>
                </div>
                <div class="card-body">
                    <?php if (count($testConnections) === 0): ?>
                        <div class="text-secondary">Keine Connection dieses Connector Type vorhanden zum Testen.</div>
                    <?php else: ?>
                        <form method="post" class="row g-2 mb-3">
                            <input type="hidden" name="action" value="test_operation">
                            <div class="col-auto">
                                <select name="test_connection_id" class="form-select">
                                    <?php foreach ($testConnections as $tc): ?>
                                        <option value="<?php echo (int) $tc['id']; ?>"><?php echo htmlspecialchars($tc['shop'] . ' — ' . $tc['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-outline-primary">Live testen (max. 3 Datensätze)</button>
                            </div>
                        </form>
                    <?php endif; ?>
                    <?php if ($testOutput !== null): ?>
                        <pre class="bg-dark text-light p-3 rounded" style="max-height:400px;overflow:auto;"><?php echo htmlspecialchars($testOutput); ?></pre>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
