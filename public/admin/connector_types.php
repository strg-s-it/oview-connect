<?php

declare(strict_types=1);

/**
 * connect/public/admin/connector_types.php
 *
 * Uebersicht aller Connector Types (Shopify, HelloCash, MGM, ...) + neue
 * anlegen. Reines Admin-Feature -- Connector Types sind shop-uebergreifend
 * geteilte Templates (siehe is_builtin), Aenderungen betreffen ALLE Shops,
 * die den jeweiligen Type nutzen.
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

$flashMessage = null;
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_connector_type') {
    $typeKey = trim((string) ($_POST['type_key'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    $authType = (string) ($_POST['auth_type'] ?? 'none');
    $requestFormat = (string) ($_POST['request_format'] ?? 'json');

    if ($typeKey === '' || $name === '') {
        $flashMessage = 'Bitte type_key und Name angeben.';
        $flashType = 'danger';
    } else {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO connect_connector_types (type_key, name, description, is_builtin, auth_type, auth_field_schema, request_format, base_url_template, created_at)
                 VALUES (:type_key, :name, :description, 0, :auth_type, :auth_field_schema, :request_format, NULL, CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                'type_key' => $typeKey,
                'name' => $name,
                'description' => (string) ($_POST['description'] ?? ''),
                'auth_type' => $authType,
                'auth_field_schema' => '[]',
                'request_format' => $requestFormat,
            ]);
            $flashMessage = "Connector Type \"{$name}\" angelegt. Bitte jetzt Objekte/Felder/Operationen ergänzen.";
            $flashType = 'success';
        } catch (\Throwable $e) {
            $flashMessage = 'Fehler: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

$connectorTypes = $pdo->query(
    'SELECT t.*, (SELECT COUNT(*) FROM connect_objects o WHERE o.connector_type_id = t.id) AS object_count
     FROM connect_connector_types t
     ORDER BY t.is_builtin DESC, t.name'
)->fetchAll();

$pageTitle = 'Connector Types';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = []; // kein Shop-Bezug auf dieser Seite
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Connector Types</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>type_key</th>
                            <th>Auth</th>
                            <th>Format</th>
                            <th>Objekte</th>
                            <th></th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($connectorTypes as $type): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($type['name']); ?></td>
                                <td><code><?php echo htmlspecialchars($type['type_key']); ?></code></td>
                                <td><?php echo htmlspecialchars($type['auth_type']); ?></td>
                                <td><?php echo htmlspecialchars($type['request_format']); ?></td>
                                <td><?php echo (int) $type['object_count']; ?></td>
                                <td><?php echo $type['is_builtin'] ? '<span class="badge bg-blue-lt">builtin</span>' : ''; ?></td>
                                <td>
                                    <a href="connector_type_edit.php?id=<?php echo (int) $type['id']; ?>" class="btn btn-sm btn-outline-primary">Bearbeiten</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Neuen Connector Type anlegen</h3>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="create_connector_type">
                    <div class="col-md-3">
                        <label class="form-label">type_key</label>
                        <input type="text" name="type_key" class="form-control" placeholder="z.B. mein_erp" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" placeholder="z.B. Mein ERP" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Art der Anmeldung</label>
                        <select name="auth_type" class="form-select">
                            <option value="none">Keine</option>
                            <option value="bearer">Bearer-Token</option>
                            <option value="basic">Benutzername + Passwort</option>
                            <option value="api_key">API-Key im Header</option>
                            <option value="custom_headers">Eigene Header</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Datenformat</label>
                        <select name="request_format" class="form-select">
                            <option value="json">JSON</option>
                            <option value="xml">XML</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Anlegen</button>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Beschreibung</label>
                        <input type="text" name="description" class="form-control">
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
