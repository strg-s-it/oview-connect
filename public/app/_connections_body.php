<?php

declare(strict_types=1);

/**
 * connect/public/app/_connections_body.php
 *
 * Eigentliche Connections-Verwaltung (Connector Type waehlen, Zugangsdaten
 * dynamisch aus auth_field_schema rendern, speichern verschluesselt,
 * Verbindung testen). Ausgelagert aus connections.php, damit sowohl der
 * Merchant-Einstieg (app/connections.php, bootstrap.php) als auch die
 * Admin-Ansicht (admin/connections.php, admin_bootstrap.php) dieselbe Logik
 * nutzen, ohne sie zu duplizieren -- der Unterschied liegt ausschliesslich
 * darin, WELCHER Bootstrap $app geliefert hat.
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin
 * (bool, Default false) fuer die Navigation im Header.
 */

use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Shopify\TokenRefresher;

$isAdmin = $isAdmin ?? false;

$pdo = $app->pdo;
$shop = $app->shop;

$flashMessage = null;
$flashType = 'info';

// Eingefuegte Zugangsdaten bereinigen: aus Mails kopierte Tokens kommen oft
// umgebrochen an (live bei MGM: JWT ueber mehrere Zeilen -> "Invalid Token",
// E02). Tokens enthalten nie Whitespace, deshalb dort ALLES entfernen; bei
// allen anderen Feldern nur Anfang/Ende trimmen (Passwoerter duerfen innen
// Leerzeichen enthalten).
$normalizeCredential = static function (string $key, string $value): string {
    if (in_array($key, ['token', 'header_value'], true)) {
        return (string) preg_replace('/\s+/u', '', $value);
    }
    return trim($value);
};

// -----------------------------------------------------------------------
// POST: neue Connection anlegen -- admin-only (siehe Nutzer-Entscheidung:
// Merchant darf bestehende Connections pflegen/Zugangsdaten neu eintragen,
// aber keine neuen Connector Types/Connections selbst anlegen). Serverseitig
// geprueft, nicht nur durch Verstecken der Karte weiter unten -- ein POST
// mit dieser action waere sonst trotzdem ausfuehrbar.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_connection' && !$isAdmin) {
    $flashMessage = 'Neue Connections anlegen ist nur im Admin-Bereich moeglich.';
    $flashType = 'danger';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_connection') {
    $connectorTypeId = (int) ($_POST['connector_type_id'] ?? 0);
    $label = trim((string) ($_POST['label'] ?? ''));
    $credentials = is_array($_POST['credentials'] ?? null) ? $_POST['credentials'] : [];
    foreach ($credentials as $key => $value) {
        if (is_string($value)) {
            $credentials[$key] = $normalizeCredential((string) $key, $value);
        }
    }

    if ($connectorTypeId <= 0 || $label === '') {
        $flashMessage = 'Bitte Connector Type und Bezeichnung angeben.';
        $flashType = 'danger';
    } else {
        $encrypted = $app->crypto->encryptArray($credentials);

        $insert = $pdo->prepare(
            'INSERT INTO connect_connections (shop, connector_type_id, label, credentials, config, status, created_at)
             VALUES (:shop, :connector_type_id, :label, :credentials, :config, :status, CURRENT_TIMESTAMP)'
        );
        $insert->execute([
            'shop' => $shop,
            'connector_type_id' => $connectorTypeId,
            'label' => $label,
            'credentials' => $encrypted,
            'config' => json_encode([]),
            'status' => 'pending',
        ]);
        $newConnectionId = (int) $pdo->lastInsertId();

        $flashMessage = "Connection \"{$label}\" angelegt. Bitte jetzt die Verbindung testen.";
        $flashType = 'success';

        // Automatische Sync-Job-Aktivierung (connect_sync_job_templates.auto_activate,
        // Migration 0022) -- bisher NUR von der Shopify-Migrationsbruecke in
        // admin/shops.php genutzt (siehe dortiger Kommentar: "Auto-Aktivierung ist
        // Shopify-spezifisch"). Ab hier gilt sie fuer JEDE neu angelegte Connection,
        // egal welchen Connector Types: legt sofort eine laufende Sync-Mapping-Zeile an,
        // mit derselben Anlage-Logik wie "Job-Vorlage aktivieren" oben in dieser Datei
        // (Aktion create_mapping) -- z.B. HelloCash "Mitarbeiter" fuer die Kassierer-
        // Auswahl bei der Rechnungserstellung (Connect\Sync\InvoiceService), damit ein
        // Merchant das nicht selbst suchen und aktivieren muss.
        $autoTemplatesStmt = $pdo->prepare(
            'SELECT * FROM connect_sync_job_templates WHERE connector_type_id = :type_id AND auto_activate = 1'
        );
        $autoTemplatesStmt->execute(['type_id' => $connectorTypeId]);
        $autoActivatedNames = [];
        foreach ($autoTemplatesStmt->fetchAll() as $autoTemplate) {
            $pdo->prepare(
                'INSERT INTO connect_sync_mappings
                    (shop, name, source_connection_id, source_object_id, sync_job_template_id, trigger_type, schedule_interval_minutes, enabled, managed_by)
                 VALUES (:shop, :name, :source_connection_id, :source_object_id, :sync_job_template_id, \'schedule\', :interval_minutes, 1, \'system\')'
            )->execute([
                'shop' => $shop,
                'name' => $autoTemplate['name'],
                'source_connection_id' => $newConnectionId,
                'source_object_id' => $autoTemplate['object_id'],
                'sync_job_template_id' => (int) $autoTemplate['id'],
                'interval_minutes' => (int) $autoTemplate['default_interval_minutes'],
            ]);
            $autoActivatedNames[] = $autoTemplate['name'];
        }
        if ($autoActivatedNames !== []) {
            $flashMessage .= ' Automatisch aktiviert: ' . implode(', ', $autoActivatedNames) . '.';
        }
    }
}

// -----------------------------------------------------------------------
// POST: Verbindung testen
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_connection') {
    $connectionId = (int) ($_POST['connection_id'] ?? 0);

    // Nur Connections des eigenen Shops testen duerfen -- gilt im
    // Admin-Kontext genauso, nur dass dort $shop der vom Admin ausgewaehlte
    // Shop ist, nicht der eigene.
    $ownershipCheck = $pdo->prepare(
        'SELECT c.label, c.credentials, t.type_key FROM connect_connections c
         JOIN connect_connector_types t ON t.id = c.connector_type_id
         WHERE c.id = :id AND c.shop = :shop'
    );
    $ownershipCheck->execute(['id' => $connectionId, 'shop' => $shop]);
    $owned = $ownershipCheck->fetch();

    if ($owned === false) {
        $flashMessage = 'Connection nicht gefunden.';
        $flashType = 'danger';
    } else {
        $tokenRefresher = TokenRefresher::fromConfig($app->config);
        $factory = new ConnectorFactory($pdo, $app->crypto, new HttpClient(), $tokenRefresher);

        $previousStatus = \Connect\Sync\JobScheduler::connectionStatus($pdo, $connectionId);
        try {
            $result = $factory->forConnection($connectionId)->testConnection();
        } catch (\Throwable $e) {
            $result = \Connect\Connector\TestResult::failure($e->getMessage());
        }

        $update = $pdo->prepare(
            'UPDATE connect_connections
             SET status = :status, last_test_at = CURRENT_TIMESTAMP, last_test_result = :result, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $update->execute([
            'status' => $result->success ? 'active' : 'error',
            'result' => $result->message,
            'id' => $connectionId,
        ]);
        if ($result->success) {
            \Connect\Setup\ConnectionAccount::refresh($pdo, $factory, $connectionId); // Firmenname als Bezeichnung
            // Daten-Jobs (z.B. Mitarbeiter) sofort, wenn die Verbindung vorher nicht lief
            \Connect\Sync\JobScheduler::afterSuccessfulTest($pdo, $connectionId, $previousStatus);
        }

        $flashMessage = "Test fuer \"{$owned['label']}\": {$result->message}";
        $flashType = $result->success ? 'success' : 'danger';

        // products/update-Webhook ist immer abonniert, sobald eine
        // Shopify-Connection erfolgreich getestet wurde -- damit bleibt der
        // Produkt-Cache aktuell, ohne auf den naechsten stuendlichen Job zu
        // warten (siehe Connect\Sync\ObjectCache::refreshOne(),
        // webhooks/product_update.php). Ein fehlgeschlagenes Webhook-Abo
        // soll den (bereits erfolgreichen) Verbindungstest nicht als
        // fehlgeschlagen erscheinen lassen, deshalb eigenes try/catch.
        if ($result->success && $owned['type_key'] === 'shopify') {
            try {
                $registrar = new \Connect\Shopify\WebhookRegistrar(
                    $pdo,
                    new HttpClient(),
                    $app->config->require('CONNECT_APP_URL'),
                    $app->config->optional('CONNECT_SHOPIFY_API_VERSION', '2026-07')
                );

                // Erst pruefen, ob das Abo schon existiert (z.B. von einem
                // frueheren Test) -- Shopify lehnt eine zweite Registrierung
                // fuer dieselbe Adresse+Thema-Kombination mit HTTP 422 ab
                // ("address for this topic has already been taken"). Das ist
                // technisch harmlos (das bestehende Abo bleibt unveraendert
                // aktiv), sah bisher aber bei jedem erneuten Verbindungstest
                // faelschlich wie ein Fehler aus.
                if (!in_array('products/update', $registrar->subscribedTopics($connectionId), true)) {
                    $credentials = TokenRefresher::fromConfig($app->config)->freshCredentials($pdo, $app->crypto, $connectionId);
                    $shopDomain = str_replace(['https://', 'http://'], '', (string) ($credentials['base_url'] ?? ''));
                    $accessToken = (string) ($credentials['header_value'] ?? '');

                    $registrar->subscribe($connectionId, 'products/update', $shopDomain, $accessToken);
                }
            } catch (\Throwable $e) {
                $flashMessage .= ' (Webhook-Abo "Produkt aktualisiert" fehlgeschlagen: ' . $e->getMessage() . ')';
            }
        }
    }
}

// -----------------------------------------------------------------------
// POST: TLS-Zertifikatspruefung fuer eine Connection an-/abschalten
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_connection_config') {
    $connectionId = (int) ($_POST['connection_id'] ?? 0);
    $verifySsl = isset($_POST['verify_ssl']);

    $ownershipCheck = $pdo->prepare('SELECT id, label, config FROM connect_connections WHERE id = :id AND shop = :shop');
    $ownershipCheck->execute(['id' => $connectionId, 'shop' => $shop]);
    $owned = $ownershipCheck->fetch();

    // Die TLS-Pruefung abzuschalten macht eine Verbindung abfangbar (Man-in-the-Middle) -- deshalb nur im
    // Admin-Bereich, serverseitig geprueft, nicht nur durch das Verstecken der Checkbox.
    if (!$isAdmin) {
        $flashMessage = 'Die TLS-Prüfung kann nur im Admin-Bereich geändert werden.';
        $flashType = 'danger';
    } elseif ($owned === false) {
        $flashMessage = 'Connection nicht gefunden.';
        $flashType = 'danger';
    } else {
        $config = json_decode((string) $owned['config'], true) ?: [];
        $config['verify_ssl'] = $verifySsl;

        $update = $pdo->prepare('UPDATE connect_connections SET config = :config, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $update->execute(['config' => json_encode($config), 'id' => $connectionId]);

        $flashMessage = "TLS-Zertifikatspruefung fuer \"{$owned['label']}\" " . ($verifySsl ? 'aktiviert.' : 'deaktiviert -- nur fuer interne/selbstsignierte Server verwenden, die Verbindung ist dann anfaellig fuer Man-in-the-Middle-Angriffe.');
        $flashType = $verifySsl ? 'success' : 'warning';
    }
}

// -----------------------------------------------------------------------
// POST: Zugangsdaten einer bestehenden Connection neu eintragen (z.B. ein
// abgelaufenes/falsches Token). Nur die Passwort-/Token-Felder werden im
// Formular NIE angezeigt und gelten als "unveraendert lassen", wenn sie
// leer abgeschickt werden (wie ein uebliches "Passwort aendern"-Formular) --
// alle anderen Felder (z.B. Basis-URL) sind im Formular vorausgefuellt und
// werden 1:1 aus dem POST uebernommen, auch wenn sie geleert wurden.
// Setzt den Status zurueck auf "pending": ein neu eingetragenes Token gilt
// erst als funktionierend, nachdem "Verbindung testen" erneut gelaufen ist
// (gleiches Prinzip wie beim "Token uebernehmen" in admin/shops.php).
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_connection_credentials') {
    $connectionId = (int) ($_POST['connection_id'] ?? 0);
    $submittedCredentials = is_array($_POST['credentials'] ?? null) ? $_POST['credentials'] : [];

    $ownershipCheck = $pdo->prepare(
        'SELECT c.id, c.label, c.credentials, t.auth_field_schema
         FROM connect_connections c
         JOIN connect_connector_types t ON t.id = c.connector_type_id
         WHERE c.id = :id AND c.shop = :shop'
    );
    $ownershipCheck->execute(['id' => $connectionId, 'shop' => $shop]);
    $owned = $ownershipCheck->fetch();

    if ($owned === false) {
        $flashMessage = 'Connection nicht gefunden.';
        $flashType = 'danger';
    } else {
        $existingCredentials = $app->crypto->decryptArray((string) $owned['credentials']);

        $passwordFieldKeys = array_column(
            array_filter(json_decode((string) $owned['auth_field_schema'], true) ?: [], static fn ($f) => ($f['type'] ?? 'text') === 'password'),
            'key'
        );

        // Nur Felder uebernehmen, die das Schema der Connection kennt (bisher wurde JEDES abgeschickte Feld
        // gespeichert), und alles, was nicht im Formular steht (z.B. refresh_token, Ablaufzeit), unveraendert
        // lassen. Die Basis-URL darf ein Merchant nicht aendern: Connect schickt Zugangsdaten (bei Shopify
        // auch das App-Secret) an diese Adresse -- eine frei gewaehlte Adresse waere ein Weg, sie abzugreifen.
        $schemaFieldKeys = array_column(json_decode((string) $owned['auth_field_schema'], true) ?: [], 'key');

        $mergedCredentials = $existingCredentials;
        foreach ($schemaFieldKeys as $key) {
            if (!array_key_exists($key, $submittedCredentials) || !is_string($submittedCredentials[$key])) {
                continue;
            }
            if ($key === 'base_url' && !$isAdmin) {
                continue;
            }
            $submittedValue = $normalizeCredential((string) $key, $submittedCredentials[$key]);
            // Leere Passwort-/Token-Felder bedeuten "unveraendert lassen".
            if ($submittedValue === '' && in_array($key, $passwordFieldKeys, true)) {
                continue;
            }
            $mergedCredentials[$key] = $submittedValue;
        }

        $encrypted = $app->crypto->encryptArray($mergedCredentials);

        $update = $pdo->prepare(
            'UPDATE connect_connections
             SET credentials = :credentials, status = :status, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $update->execute([
            'credentials' => $encrypted,
            'status' => 'pending',
            'id' => $connectionId,
        ]);

        $flashMessage = "Zugangsdaten fuer \"{$owned['label']}\" aktualisiert. Bitte die Verbindung erneut testen.";
        $flashType = 'success';
    }
}

// -----------------------------------------------------------------------
// Daten fuer die Anzeige laden
// -----------------------------------------------------------------------
// "Connect intern" (Belegaufbereitung, Migration 0067) ist kein Zielsystem und bekommt keine Connection.
$connectorTypes = $pdo->query("SELECT * FROM connect_connector_types WHERE type_key != 'connect_internal' ORDER BY name")->fetchAll();

$connectionsStmt = $pdo->prepare(
    'SELECT c.*, t.name AS connector_type_name, t.auth_field_schema
     FROM connect_connections c
     JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE c.shop = :shop
     ORDER BY c.id DESC'
);
$connectionsStmt->execute(['shop' => $shop]);
$connections = $connectionsStmt->fetchAll();

$statusBadge = [
    'active' => 'bg-green-lt',
    'error' => 'bg-red-lt',
    'pending' => 'bg-yellow-lt',
];

$pageTitle = 'Connections' . ($isAdmin ? ' — ' . $shop : '');

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
                <h3 class="card-title">Bestehende Connections</h3>
            </div>
            <?php if (count($connections) === 0): ?>
                <div class="card-body text-secondary">
                    Noch keine Connection angelegt. Weiter unten eine neue anlegen.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Bezeichnung</th>
                                <th>Connector Type</th>
                                <th>Status</th>
                                <th>Letzter Test</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($connections as $connection): ?>
                                <?php
                                $connectionConfig = json_decode((string) $connection['config'], true) ?: [];
                                $verifySslEnabled = ($connectionConfig['verify_ssl'] ?? true) !== false;
                                ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($connection['label']); ?>
                                        <?php $accountInfo = \Connect\Setup\ConnectionAccount::fromConfig($connection['config'] ?? null); ?>
                                        <?php $accountSummary = \Connect\Setup\ConnectionAccount::summary($connection['config'] ?? null); ?>
                                        <?php if ($accountSummary !== ''): ?>
                                            <div class="text-secondary small"><?php echo htmlspecialchars($accountSummary); ?></div>
                                        <?php endif; ?>
                                        <?php if ($isAdmin && $accountInfo['error'] !== null): ?>
                                            <div class="text-warning small"><?php echo htmlspecialchars($accountInfo['error']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($connection['connector_type_name']); ?></td>
                                    <td>
                                        <span class="badge <?php echo htmlspecialchars($statusBadge[$connection['status']] ?? 'bg-secondary-lt'); ?>">
                                            <?php echo htmlspecialchars($connection['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-secondary">
                                        <?php if ($connection['last_test_at'] !== null): ?>
                                            <?php echo htmlspecialchars($connection['last_test_at']); ?><br>
                                            <small><?php echo htmlspecialchars((string) $connection['last_test_result']); ?></small>
                                        <?php else: ?>
                                            noch nicht getestet
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="test_connection">
                                                <input type="hidden" name="connection_id" value="<?php echo (int) $connection['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Verbindung testen</button>
                                            </form>
                                            <?php if ($isAdmin): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="action" value="update_connection_config">
                                                    <input type="hidden" name="connection_id" value="<?php echo (int) $connection['id']; ?>">
                                                    <label class="form-check form-check-inline mb-0" title="Nur fuer interne/selbstsignierte Server deaktivieren -- die Verbindung ist dann anfaellig fuer Man-in-the-Middle-Angriffe.">
                                                        <input class="form-check-input" type="checkbox" name="verify_ssl" value="1" <?php echo $verifySslEnabled ? 'checked' : ''; ?> onchange="this.form.submit()">
                                                        <span class="form-check-label small">TLS prüfen</span>
                                                    </label>
                                                </form>
                                            <?php elseif (!$verifySslEnabled): ?>
                                                <span class="badge bg-yellow-lt" title="Vom Betreiber so eingestellt (z.B. On-Premise-Server mit eigenem Zertifikat).">TLS-Prüfung aus</span>
                                            <?php endif; ?>
                                        </div>
                                        <details class="mt-2">
                                            <summary class="text-secondary small" style="cursor:pointer;">Zugangsdaten neu eintragen</summary>
                                            <form method="post" class="mt-2" style="min-width:220px;" autocomplete="off">
                                                <input type="hidden" name="action" value="update_connection_credentials">
                                                <input type="hidden" name="connection_id" value="<?php echo (int) $connection['id']; ?>">
                                                <p class="text-secondary small">Passwort-/Token-Felder werden aus Sicherheitsgruenden nie angezeigt -- leer lassen, um sie unveraendert zu lassen. Alle anderen Felder sind mit den aktuellen Werten vorausgefuellt.</p>
                                                <?php $editAuthFields = json_decode((string) $connection['auth_field_schema'], true) ?: []; ?>
                                                <?php $existingCredentialsForEdit = $app->crypto->decryptArray((string) $connection['credentials']); ?>
                                                <?php foreach ($editAuthFields as $field): ?>
                                                    <?php $fieldType = $field['type'] ?? 'text'; ?>
                                                    <?php if ($fieldType === 'hidden'): ?>
                                                        <input type="hidden" name="credentials[<?php echo htmlspecialchars($field['key']); ?>]" value="<?php echo htmlspecialchars($field['value'] ?? ''); ?>">
                                                    <?php else: ?>
                                                        <div class="mb-2">
                                                            <label class="form-label small mb-0"><?php echo htmlspecialchars($field['label']); ?></label>
                                                            <input
                                                                type="<?php echo htmlspecialchars($fieldType); ?>"
                                                                name="credentials[<?php echo htmlspecialchars($field['key']); ?>]"
                                                                class="form-control form-control-sm"
                                                                value="<?php echo $fieldType === 'password' ? '' : htmlspecialchars((string) ($existingCredentialsForEdit[$field['key']] ?? '')); ?>"
                                                                placeholder="<?php echo $fieldType === 'password' ? 'unveraendert lassen' : htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                                                autocomplete="<?php echo $fieldType === 'password' ? 'new-password' : 'off'; ?>"
                                                                <?php echo (!empty($field['required']) && $fieldType !== 'password') ? 'required' : ''; ?>
                                                                <?php echo (($field['key'] ?? '') === 'base_url' && !$isAdmin) ? 'readonly title="Die Basis-Adresse ändert nur der Betreiber."' : ''; ?>
                                                            >
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">Speichern</button>
                                            </form>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Neue Connection anlegen</h3>
            </div>
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="create_connection">

                    <div class="mb-3">
                        <label class="form-label">Connector Type</label>
                        <select name="connector_type_id" class="form-select" onchange="showAuthFields(this.value)" required>
                            <option value="">-- auswaehlen --</option>
                            <?php foreach ($connectorTypes as $type): ?>
                                <option value="<?php echo (int) $type['id']; ?>"><?php echo htmlspecialchars($type['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Bezeichnung</label>
                        <input type="text" name="label" class="form-control" placeholder="z.B. MGM Crystalp" required>
                    </div>

                    <?php foreach ($connectorTypes as $type): ?>
                        <?php $authFields = json_decode((string) $type['auth_field_schema'], true) ?: []; ?>
                        <div id="auth-fields-<?php echo (int) $type['id']; ?>" class="auth-fields" style="display:none">
                            <?php foreach ($authFields as $field): ?>
                                <?php $fieldType = $field['type'] ?? 'text'; ?>
                                <?php if ($fieldType === 'hidden'): ?>
                                    <!-- Fixer Wert (z.B. Shopify: Header-Name X-Shopify-Access-Token) --
                                         wird mitgesendet, aber nicht vom Nutzer eingegeben. -->
                                    <input type="hidden" name="credentials[<?php echo htmlspecialchars($field['key']); ?>]" value="<?php echo htmlspecialchars($field['value'] ?? ''); ?>">
                                <?php else: ?>
                                    <div class="mb-3">
                                        <label class="form-label"><?php echo htmlspecialchars($field['label']); ?></label>
                                        <input
                                            type="<?php echo htmlspecialchars($fieldType); ?>"
                                            name="credentials[<?php echo htmlspecialchars($field['key']); ?>]"
                                            class="form-control"
                                            placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                            autocomplete="<?php echo $fieldType === 'password' ? 'new-password' : 'off'; ?>"
                                            <?php echo !empty($field['required']) ? 'required' : ''; ?>
                                        >
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>

                    <button type="submit" class="btn btn-primary">Connection anlegen</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
    // Bewusst schlichtes Vanilla-JS statt einer Formular-Bibliothek: zeigt
    // nur die Zugangsdaten-Felder des gerade gewaehlten Connector Type.
    function showAuthFields(connectorTypeId) {
        document.querySelectorAll('.auth-fields').forEach(function (el) {
            el.style.display = 'none';
            el.querySelectorAll('input').forEach(function (input) {
                input.disabled = true;
            });
        });

        if (!connectorTypeId) {
            return;
        }

        var target = document.getElementById('auth-fields-' + connectorTypeId);
        if (target) {
            target.style.display = 'block';
            target.querySelectorAll('input').forEach(function (input) {
                input.disabled = false;
            });
        }
    }
</script>

<?php require __DIR__ . '/_footer.php'; ?>
