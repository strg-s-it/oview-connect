<?php

declare(strict_types=1);

/**
 * connect/public/admin/connector_type_edit.php
 *
 * Bearbeitet einen Connector Type (Name, Auth, Format, auth_field_schema)
 * und listet seine Objects mit Moeglichkeit, neue anzulegen.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$typeId = (int) ($_GET['id'] ?? 0);
$typeStmt = $pdo->prepare('SELECT * FROM connect_connector_types WHERE id = :id');
$typeStmt->execute(['id' => $typeId]);
$type = $typeStmt->fetch();

if ($type === false) {
    \Connect\Http\ErrorPage::show(
        404,
        'Connector Type nicht gefunden',
        'Dieser Eintrag existiert nicht (mehr) oder wurde gelöscht.',
        ['label' => 'Zu den Connector Types', 'url' => 'connector_types.php']
    );
}

$flashMessage = null;
$flashType = 'info';

// -----------------------------------------------------------------------
// POST: Connector-Type-Stammdaten speichern
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_type') {
    $name = trim((string) ($_POST['name'] ?? ''));

    if ($name === '') {
        // HTML "required" reicht nicht allein -- laesst sich umgehen
        // (direkter POST, abgeschaltetes JS). Immer auch serverseitig pruefen.
        $flashMessage = 'Der Name darf nicht leer sein.';
        $flashType = 'danger';
    } else {
    // Anfrage-Limit (siehe Connect\Http\RateLimiter): beide Felder leer = kein Limit.
    // Nur "eins von beiden ausgefuellt" waere ein widerspruechlicher Zustand -- dann lieber
    // beide verwerfen und den Admin per Fehlermeldung darauf hinweisen, statt still zu raten.
    $rateLimitMaxRaw = trim((string) ($_POST['rate_limit_max_requests'] ?? ''));
    $rateLimitWindowRaw = trim((string) ($_POST['rate_limit_window_seconds'] ?? ''));
    $rateLimitMax = $rateLimitMaxRaw !== '' && ctype_digit($rateLimitMaxRaw) && (int) $rateLimitMaxRaw > 0 ? (int) $rateLimitMaxRaw : null;
    $rateLimitWindow = $rateLimitWindowRaw !== '' && ctype_digit($rateLimitWindowRaw) && (int) $rateLimitWindowRaw > 0 ? (int) $rateLimitWindowRaw : null;

    if (($rateLimitMax === null) !== ($rateLimitWindow === null)) {
        $flashMessage = 'Anfrage-Limit: bitte entweder beide Felder ausfuellen oder beide leer lassen.';
        $flashType = 'danger';
    } else {
    $update = $pdo->prepare(
        'UPDATE connect_connector_types
         SET name = :name, description = :description, auth_type = :auth_type,
             request_format = :request_format, base_url_template = :base_url_template,
             rate_limit_max_requests = :rate_limit_max_requests, rate_limit_window_seconds = :rate_limit_window_seconds,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $update->execute([
        'name' => $name,
        'description' => (string) ($_POST['description'] ?? ''),
        'auth_type' => (string) ($_POST['auth_type'] ?? 'none'),
        'request_format' => (string) ($_POST['request_format'] ?? 'json'),
        'base_url_template' => ((string) ($_POST['base_url_template'] ?? '')) !== '' ? $_POST['base_url_template'] : null,
        'rate_limit_max_requests' => $rateLimitMax,
        'rate_limit_window_seconds' => $rateLimitWindow,
        'id' => $typeId,
    ]);

    $flashMessage = 'Gespeichert.' . ($type['is_builtin'] ? ' Achtung: Dieser Connector Type ist "builtin" und wird von mehreren Shops genutzt.' : '');
    $flashType = 'success';

    // Feste Absender-IP (Migration 0081) getrennt -- fehlt die Spalte noch, bleibt der Rest gespeichert
    $outboundIp = trim((string) ($_POST['outbound_ip'] ?? ''));
    if ($outboundIp !== '' && filter_var($outboundIp, FILTER_VALIDATE_IP) === false) {
        $flashMessage = 'Gespeichert, aber die feste Absender-IP ist keine gültige IP-Adresse und wurde nicht übernommen.';
        $flashType = 'danger';
    } else {
        try {
            $pdo->prepare('UPDATE connect_connector_types SET outbound_ip = :ip WHERE id = :id')
                ->execute(['ip' => $outboundIp !== '' ? $outboundIp : null, 'id' => $typeId]);
        } catch (\PDOException) {
            if ($outboundIp !== '') {
                $flashMessage = 'Gespeichert, die feste Absender-IP aber erst nach Migration 0081 möglich.';
                $flashType = 'danger';
            }
        }
    }

    // Oeffentlich/privat fuer den Haendler-Assistenten (Migration 0096) getrennt -- fehlt die
    // Spalte noch, bleibt der Rest gespeichert
    $isPublic = isset($_POST['is_public']) ? 1 : 0;
    try {
        $pdo->prepare('UPDATE connect_connector_types SET is_public = :is_public WHERE id = :id')
            ->execute(['is_public' => $isPublic, 'id' => $typeId]);
    } catch (\PDOException) {
        if ($isPublic === 0) {
            $flashMessage = 'Gespeichert, „privat“ aber erst nach Migration 0096 möglich.';
            $flashType = 'danger';
        }
    }

    $typeStmt->execute(['id' => $typeId]);
    $type = $typeStmt->fetch();
    }
    }
}

/**
 * Baut den auth_field_schema-JSON-Eintrag fuer eine Zeile aus dem Formular.
 * "extra" ist je nach Typ entweder der Platzhalter-Text (text/password)
 * oder der feste Wert (hidden) -- EIN Eingabefeld im Formular, zwei
 * Bedeutungen je nach gewaehltem Typ.
 *
 * @return array<string, mixed>|null null, wenn kein Feldname angegeben wurde
 */
function build_auth_field_entry(string $key, string $label, string $type, bool $required, string $extra): ?array
{
    $key = trim($key);
    if ($key === '') {
        return null;
    }

    $entry = ['key' => $key, 'label' => trim($label), 'type' => $type];
    if ($required) {
        $entry['required'] = true;
    }
    if ($type === 'hidden') {
        $entry['value'] = $extra;
    } elseif ($extra !== '') {
        $entry['placeholder'] = $extra;
    }

    return $entry;
}

// -----------------------------------------------------------------------
// POST: alle Zugangsdaten-Felder auf einmal speichern (eine Tabelle, ein Button)
// -----------------------------------------------------------------------
// -----------------------------------------------------------------------
// POST: Logo fuer die Auswahl-Kacheln im ersten Fenster der Merchants
// (app/welcome.php, Migration 0076). Abgelegt unter public/assets/logos/.
// Nur Rasterbilder -- SVG bewusst nicht (kann Script enthalten).
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['upload_logo', 'remove_logo'], true)) {
    $logoDir = dirname(__DIR__) . '/assets/logos';
    $oldLogo = (string) ($type['logo_path'] ?? '');
    $newLogo = null;
    $logoError = null;

    if ($_POST['action'] === 'upload_logo') {
        $file = $_FILES['logo'] ?? null;
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $info = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK ? @getimagesize((string) $file['tmp_name']) : false;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $logoError = 'Bitte eine Bilddatei auswählen.';
        } elseif ((int) $file['size'] > 1024 * 1024) {
            $logoError = 'Das Bild ist größer als 1 MB.';
        } elseif ($info === false || !isset($allowed[$info['mime'] ?? ''])) {
            $logoError = 'Nur PNG, JPG, WebP oder GIF.';
        } else {
            if (!is_dir($logoDir) && !@mkdir($logoDir, 0755, true)) {
                $logoError = 'Ordner für Logos konnte nicht angelegt werden.';
            } else {
                $newLogo = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $type['type_key'])) . '-' . time() . '.' . $allowed[$info['mime']];
                if (!move_uploaded_file((string) $file['tmp_name'], $logoDir . '/' . $newLogo)) {
                    $logoError = 'Speichern fehlgeschlagen.';
                    $newLogo = null;
                }
            }
        }
    }

    if ($logoError !== null) {
        $flashMessage = $logoError;
        $flashType = 'danger';
    } else {
        try {
            $pdo->prepare('UPDATE connect_connector_types SET logo_path = :logo WHERE id = :id')->execute(['logo' => $newLogo, 'id' => $typeId]);
            if ($oldLogo !== '' && $oldLogo !== $newLogo && is_file($logoDir . '/' . basename($oldLogo))) {
                @unlink($logoDir . '/' . basename($oldLogo));
            }
            $flashMessage = $newLogo !== null ? 'Logo gespeichert.' : 'Logo entfernt.';
            $flashType = 'success';
        } catch (\Throwable $e) {
            $flashMessage = 'Logo konnte nicht gespeichert werden (Migration 0076 eingespielt?): ' . $e->getMessage();
            $flashType = 'danger';
        }
        $typeStmt->execute(['id' => $typeId]);
        $type = $typeStmt->fetch();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_auth_fields') {
    $newSchema = [];
    foreach ((array) ($_POST['auth_fields'] ?? []) as $row) {
        $entry = build_auth_field_entry(
            (string) ($row['key'] ?? ''),
            (string) ($row['label'] ?? ''),
            (string) ($row['type'] ?? 'text'),
            isset($row['required']),
            (string) ($row['extra'] ?? '')
        );
        if ($entry !== null) {
            $newSchema[] = $entry;
        }
    }

    $pdo->prepare('UPDATE connect_connector_types SET auth_field_schema = :schema, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['schema' => json_encode($newSchema, JSON_UNESCAPED_UNICODE), 'id' => $typeId]);

    $flashMessage = 'Zugangsdaten-Felder gespeichert.';
    $flashType = 'success';
    $typeStmt->execute(['id' => $typeId]);
    $type = $typeStmt->fetch();
}

// -----------------------------------------------------------------------
// POST: ein neues Zugangsdaten-Feld hinzufuegen
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_auth_field') {
    $entry = build_auth_field_entry(
        (string) ($_POST['key'] ?? ''),
        (string) ($_POST['label'] ?? ''),
        (string) ($_POST['type'] ?? 'text'),
        ($_POST['required'] ?? '') === '1',
        (string) ($_POST['extra'] ?? '')
    );

    if ($entry === null) {
        $flashMessage = 'Bitte einen Feldnamen angeben.';
        $flashType = 'danger';
    } else {
        $schema = json_decode((string) $type['auth_field_schema'], true) ?: [];
        $schema[] = $entry;
        $pdo->prepare('UPDATE connect_connector_types SET auth_field_schema = :schema, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['schema' => json_encode($schema, JSON_UNESCAPED_UNICODE), 'id' => $typeId]);
        $flashMessage = 'Feld hinzugefügt.';
        $flashType = 'success';
        $typeStmt->execute(['id' => $typeId]);
        $type = $typeStmt->fetch();
    }
}

// -----------------------------------------------------------------------
// POST: ein Zugangsdaten-Feld entfernen
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_auth_field') {
    $schema = json_decode((string) $type['auth_field_schema'], true) ?: [];
    $index = (int) ($_POST['index'] ?? -1);
    if (isset($schema[$index])) {
        unset($schema[$index]);
        $pdo->prepare('UPDATE connect_connector_types SET auth_field_schema = :schema, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['schema' => json_encode(array_values($schema), JSON_UNESCAPED_UNICODE), 'id' => $typeId]);
        $flashMessage = 'Feld entfernt.';
        $flashType = 'success';
        $typeStmt->execute(['id' => $typeId]);
        $type = $typeStmt->fetch();
    }
}

// -----------------------------------------------------------------------
// POST: neues Object anlegen
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_object') {
    $objectKey = trim((string) ($_POST['object_key'] ?? ''));
    $label = trim((string) ($_POST['label'] ?? ''));

    if ($objectKey === '' || $label === '') {
        $flashMessage = 'Bitte object_key und Label angeben.';
        $flashType = 'danger';
    } else {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO connect_objects (connector_type_id, object_key, label, created_at) VALUES (:ctid, :key, :label, CURRENT_TIMESTAMP)'
            );
            $insert->execute(['ctid' => $typeId, 'key' => $objectKey, 'label' => $label]);
            $flashMessage = "Object \"{$label}\" angelegt.";
            $flashType = 'success';
        } catch (\Throwable $e) {
            $flashMessage = 'Fehler: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

$objects = $pdo->prepare(
    'SELECT o.*,
            (SELECT COUNT(*) FROM connect_object_fields f WHERE f.object_id = o.id) AS field_count,
            (SELECT COUNT(*) FROM connect_object_operations op WHERE op.object_id = o.id) AS operation_count
     FROM connect_objects o
     WHERE o.connector_type_id = :ctid
     ORDER BY o.label'
);
$objects->execute(['ctid' => $typeId]);
$objects = $objects->fetchAll();

$pageTitle = 'Connector Type: ' . $type['name'];
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = []; // kein Shop-Bezug auf dieser Seite
$breadcrumbs = [
    ['connector_types.php', 'Connector Types'],
    [null, $type['name']],
];
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    Stammdaten
                    <?php if ($type['is_builtin']): ?>
                        <span class="badge bg-blue-lt">builtin — betrifft alle Shops mit diesem Connector Type</span>
                    <?php endif; ?>
                </h3>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="update_type">
                    <div class="col-md-6">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($type['name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">type_key (nicht änderbar)</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($type['type_key']); ?>" disabled>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Beschreibung</label>
                        <input type="text" name="description" class="form-control" value="<?php echo htmlspecialchars((string) $type['description']); ?>">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="type-is-public" name="is_public" <?php echo (int) ($type['is_public'] ?? 1) === 1 ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="type-is-public">Öffentlich — Händler können dieses System im Setup-Assistenten selbst auswählen</label>
                        </div>
                        <small class="text-secondary">Aus = privat: nur Shops, die schon eine Connection dieses Typs haben, sehen es im Assistenten (z. B. MGM nur für Crystalp). Die Connection für einen neuen Shop legst du dann im Admin unter Connections an.<?php echo array_key_exists('is_public', $type) ? '' : ' Wirkt erst nach Migration 0096.'; ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Art der Anmeldung</label>
                        <select name="auth_type" class="form-select">
                            <option value="none" <?php echo $type['auth_type'] === 'none' ? 'selected' : ''; ?>>Keine</option>
                            <option value="bearer" <?php echo $type['auth_type'] === 'bearer' ? 'selected' : ''; ?>>Bearer-Token</option>
                            <option value="basic" <?php echo $type['auth_type'] === 'basic' ? 'selected' : ''; ?>>Benutzername + Passwort</option>
                            <option value="api_key" <?php echo $type['auth_type'] === 'api_key' ? 'selected' : ''; ?>>API-Key im Header</option>
                            <option value="custom_headers" <?php echo $type['auth_type'] === 'custom_headers' ? 'selected' : ''; ?>>Eigene Header</option>
                        </select>
                        <small class="text-secondary">Wie sich Connect bei diesem System ausweist. "API-Key im Header" nutzt z. B. Shopify (Header-Name + Wert).</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Datenformat</label>
                        <select name="request_format" class="form-select">
                            <option value="json" <?php echo $type['request_format'] === 'json' ? 'selected' : ''; ?>>JSON (z. B. Shopify)</option>
                            <option value="xml" <?php echo $type['request_format'] === 'xml' ? 'selected' : ''; ?>>XML (z. B. MGM)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Feste Basis-Adresse (optional)</label>
                        <input type="text" name="base_url_template" class="form-control" value="<?php echo htmlspecialchars((string) $type['base_url_template']); ?>">
                        <small class="text-secondary">Nur ausfüllen, wenn ALLE Connections dieselbe Adresse nutzen. Leer lassen, wenn sie pro Connection unterschiedlich ist (z. B. Shopify: jeder Shop hat seine eigene Domain; MGM: jeder Kunde seine eigene IP).</small>
                    </div>
                    <?php
                    // Server-IPs als Vorschlag: CONNECT_SERVER_IPS in der .env ("main=1.2.3.4,worker1=…"),
                    // dazu die IPs, die der Anfrage-Zaehler schon gesehen hat (Migration 0070)
                    $serverIps = [];
                    foreach (array_filter(array_map('trim', explode(',', $config->optional('CONNECT_SERVER_IPS', '')))) as $entry) {
                        [$ipName, $ipValue] = array_pad(array_map('trim', explode('=', $entry, 2)), 2, '');
                        if ($ipValue === '' && filter_var($ipName, FILTER_VALIDATE_IP) !== false) {
                            [$ipName, $ipValue] = ['', $ipName];
                        }
                        if (filter_var($ipValue, FILTER_VALIDATE_IP) !== false) {
                            $serverIps[$ipValue] = $ipName;
                        }
                    }
                    try {
                        foreach ($pdo->query("SELECT DISTINCT scope FROM connect_rate_limit_state WHERE scope <> ''")->fetchAll(\PDO::FETCH_COLUMN) as $seenIp) {
                            if (filter_var($seenIp, FILTER_VALIDATE_IP) !== false && !isset($serverIps[$seenIp])) {
                                $serverIps[$seenIp] = '';
                            }
                        }
                    } catch (\PDOException) {
                        // Migration 0070 fehlt -- dann nur die Liste aus der .env
                    }
                    ?>
                    <div class="col-md-6">
                        <label class="form-label" for="outbound-ip">Feste Absender-IP (optional)</label>
                        <input type="text" id="outbound-ip" name="outbound_ip" class="form-control font-monospace" list="server-ips"
                            value="<?php echo htmlspecialchars((string) ($type['outbound_ip'] ?? '')); ?>" placeholder="leer = IP des jeweiligen Workers">
                        <datalist id="server-ips">
                            <?php foreach ($serverIps as $ipValue => $ipName): ?>
                                <option value="<?php echo htmlspecialchars($ipValue); ?>"><?php echo htmlspecialchars($ipName !== '' ? $ipName : 'gesehen'); ?></option>
                            <?php endforeach; ?>
                        </datalist>
                        <small class="text-secondary">
                            Nur für Systeme, die beim Kunden nur eine freigegebene IP durchlassen (z. B. MGM): Alle Anfragen an dieses System gehen dann von dieser IP hinaus, egal auf welchem Worker der Job läuft.
                            Die IP muss eine des Servers sein (main oder ein Worker). Leer lassen für alle anderen Systeme, damit sich die Last auf die Worker-IPs verteilt.
                            <?php if ($serverIps !== []): ?>
                                <br>Server-IPs:
                                <?php
                                $ipLabels = [];
                                foreach ($serverIps as $ipValue => $ipName) {
                                    $ipLabels[] = ($ipName !== '' ? $ipName . ' ' : '') . $ipValue;
                                }
                                echo htmlspecialchars(implode(' · ', $ipLabels));
                                ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Anfrage-Limit: max. Aufrufe</label>
                        <input type="number" min="1" name="rate_limit_max_requests" class="form-control" value="<?php echo htmlspecialchars((string) ($type['rate_limit_max_requests'] ?? '')); ?>" placeholder="kein Limit">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">... je (Sekunden)</label>
                        <input type="number" min="1" name="rate_limit_window_seconds" class="form-control" value="<?php echo htmlspecialchars((string) ($type['rate_limit_window_seconds'] ?? '')); ?>" placeholder="kein Limit">
                        <small class="text-secondary">Gilt für ALLE Shops/Connections dieses Connector Types zusammen (ein gemeinsames Zeitfenster), nicht pro Shop -- z. B. "40 je 60" für HelloCash. Beide Felder leer lassen für kein Limit. Wird das Limit erreicht, wartet Connect selbständig kurz und versucht es erneut; dauert das zu lange, bricht der betroffene Lauf ab und wird beim nächsten Mal fortgesetzt.</small>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (array_key_exists('logo_path', $type) && !in_array($type['type_key'], ['shopify', 'connect_internal'], true)): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Logo</h3></div>
                <div class="card-body">
                    <p class="text-secondary">Erscheint auf der Kachel im ersten Fenster der Merchants („Welche Software möchtest du verbinden?“). PNG, JPG, WebP oder GIF, max. 1 MB, am besten quer und mit transparentem Hintergrund.</p>
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <div class="border rounded p-2 text-center" style="width: 160px;">
                            <?php if (!empty($type['logo_path'])): ?>
                                <img src="../assets/logos/<?php echo htmlspecialchars(rawurlencode((string) $type['logo_path'])); ?>" alt="" style="height: 48px; max-width: 100%; object-fit: contain;">
                            <?php else: ?>
                                <span class="text-secondary small">kein Logo</span>
                            <?php endif; ?>
                        </div>
                        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
                            <input type="hidden" name="action" value="upload_logo">
                            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control" required>
                            <button type="submit" class="btn btn-primary text-nowrap">Hochladen</button>
                        </form>
                        <?php if (!empty($type['logo_path'])): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="remove_logo">
                                <button type="submit" class="btn btn-outline-danger">Entfernen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Zugangsdaten-Formular für neue Connections</h3>
            </div>
            <div class="card-body pb-0 text-secondary">
                <small>
                    Das sind die Eingabefelder, die beim Anlegen einer neuen Connection für diesen Connector Type abgefragt werden
                    (z. B. bei Shopify: Shop-Domain + Zugriffs-Token). <strong>Fester Wert/Platzhalter</strong>: bei Typ "Versteckt"
                    ein Wert, den der Nutzer nie zu sehen bekommt und der immer automatisch mitgeschickt wird (z. B. Shopifys
                    Header-Name); bei Text/Passwort nur ein Hinweistext im leeren Feld.
                </small>
            </div>
            <?php $authFields = json_decode((string) $type['auth_field_schema'], true) ?: []; ?>
            <?php if (count($authFields) > 0): ?>
                <form method="post">
                    <input type="hidden" name="action" value="update_auth_fields">
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Interner Name</th>
                                    <th>Anzeigename</th>
                                    <th>Art</th>
                                    <th>Pflichtfeld</th>
                                    <th>Fester Wert / Platzhalter</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($authFields as $i => $field): ?>
                                    <tr>
                                        <td><input type="text" class="form-control form-control-sm" name="auth_fields[<?php echo $i; ?>][key]" value="<?php echo htmlspecialchars($field['key'] ?? ''); ?>"></td>
                                        <td><input type="text" class="form-control form-control-sm" name="auth_fields[<?php echo $i; ?>][label]" value="<?php echo htmlspecialchars($field['label'] ?? ''); ?>"></td>
                                        <td>
                                            <select class="form-select form-select-sm" name="auth_fields[<?php echo $i; ?>][type]">
                                                <option value="text" <?php echo ($field['type'] ?? 'text') === 'text' ? 'selected' : ''; ?>>Text</option>
                                                <option value="password" <?php echo ($field['type'] ?? '') === 'password' ? 'selected' : ''; ?>>Passwort</option>
                                                <option value="hidden" <?php echo ($field['type'] ?? '') === 'hidden' ? 'selected' : ''; ?>>Versteckt (fester Wert)</option>
                                            </select>
                                        </td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input" name="auth_fields[<?php echo $i; ?>][required]" <?php echo !empty($field['required']) ? 'checked' : ''; ?>></td>
                                        <td><input type="text" class="form-control form-control-sm" name="auth_fields[<?php echo $i; ?>][extra]" value="<?php echo htmlspecialchars($field['value'] ?? $field['placeholder'] ?? ''); ?>"></td>
                                        <td>
                                            <button type="submit" form="delete-auth-field-<?php echo $i; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Feld wirklich entfernen?')">×</button>
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
                <?php foreach ($authFields as $i => $field): ?>
                    <form id="delete-auth-field-<?php echo $i; ?>" method="post" class="d-none">
                        <input type="hidden" name="action" value="delete_auth_field">
                        <input type="hidden" name="index" value="<?php echo $i; ?>">
                    </form>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="card-body text-secondary">Noch keine Zugangsdaten-Felder definiert.</div>
            <?php endif; ?>
            <div class="card-footer">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="add_auth_field">
                    <div class="col-auto">
                        <input type="text" name="key" class="form-control" placeholder="Interner Name, z.B. token" required>
                    </div>
                    <div class="col-auto">
                        <input type="text" name="label" class="form-control" placeholder="Anzeigename, z.B. API-Token">
                    </div>
                    <div class="col-auto">
                        <select name="type" class="form-select">
                            <option value="text">Text</option>
                            <option value="password">Passwort</option>
                            <option value="hidden">Versteckt (fester Wert)</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <input type="text" name="extra" class="form-control" placeholder="Fester Wert / Platzhalter">
                    </div>
                    <div class="col-auto form-check pt-2">
                        <input type="checkbox" class="form-check-input" name="required" value="1" id="new-auth-field-required">
                        <label class="form-check-label" for="new-auth-field-required">Pflichtfeld</label>
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
                <h3 class="card-title">Objects</h3>
            </div>
            <?php if (count($objects) === 0): ?>
                <div class="card-body text-secondary">Noch keine Objects definiert.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th>object_key</th>
                                <th>Felder</th>
                                <th>Operationen</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($objects as $object): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($object['label']); ?></td>
                                    <td><code><?php echo htmlspecialchars($object['object_key']); ?></code></td>
                                    <td><?php echo (int) $object['field_count']; ?></td>
                                    <td><?php echo (int) $object['operation_count']; ?></td>
                                    <td><a href="object_edit.php?id=<?php echo (int) $object['id']; ?>" class="btn btn-sm btn-outline-primary">Bearbeiten</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <div class="card-footer">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="create_object">
                    <div class="col-auto">
                        <input type="text" name="object_key" class="form-control" placeholder="object_key, z.B. invoice" required>
                    </div>
                    <div class="col-auto">
                        <input type="text" name="label" class="form-control" placeholder="Label, z.B. Rechnung" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-outline-primary">Object hinzufügen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
