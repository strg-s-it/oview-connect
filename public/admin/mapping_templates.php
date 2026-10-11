<?php

declare(strict_types=1);

/**
 * connect/public/admin/mapping_templates.php
 *
 * Uebersicht aller Mapping-Vorlagen (Quell-Connector-Type+Object -> Ziel-
 * Connector-Type+Object) + neue anlegen. Reines, shop-uebergreifendes
 * Admin-Feature -- eine Vorlage (z.B. "Shopify-Bestellung -> MGM-Auftrag")
 * wird EINMAL gepflegt und von beliebig vielen Shops "aktiviert" (siehe
 * mappings.php, dort pro Shop). Genau wie Connector Types/Objects/Fields
 * bereits Connector-Type-Ebene sind, nicht Connection-Ebene.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_template') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $sourceObjectId = (int) ($_POST['source_object_id'] ?? 0);
    $targetObjectId = (int) ($_POST['target_object_id'] ?? 0);

    if ($name === '' || $sourceObjectId <= 0 || $targetObjectId <= 0) {
        $flashMessage = 'Bitte Name, Quell- und Ziel-Object angeben.';
        $flashType = 'danger';
    } else {
        $sourceStmt = $pdo->prepare('SELECT connector_type_id FROM connect_objects WHERE id = :id');
        $sourceStmt->execute(['id' => $sourceObjectId]);
        $sourceConnectorTypeId = $sourceStmt->fetchColumn();

        $targetStmt = $pdo->prepare('SELECT connector_type_id FROM connect_objects WHERE id = :id');
        $targetStmt->execute(['id' => $targetObjectId]);
        $targetConnectorTypeId = $targetStmt->fetchColumn();

        if ($sourceConnectorTypeId === false || $targetConnectorTypeId === false) {
            $flashMessage = 'Quell- oder Ziel-Object nicht gefunden.';
            $flashType = 'danger';
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO connect_mapping_templates
                    (name, source_connector_type_id, source_object_id, target_connector_type_id, target_object_id)
                 VALUES (:name, :source_connector_type_id, :source_object_id, :target_connector_type_id, :target_object_id)'
            );
            $insert->execute([
                'name' => $name,
                'source_connector_type_id' => $sourceConnectorTypeId,
                'source_object_id' => $sourceObjectId,
                'target_connector_type_id' => $targetConnectorTypeId,
                'target_object_id' => $targetObjectId,
            ]);

            header('Location: mapping_template_edit.php?id=' . $pdo->lastInsertId());
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_template') {
    $templateId = (int) ($_POST['template_id'] ?? 0);
    $pdo->prepare('DELETE FROM connect_mapping_templates WHERE id = :id')->execute(['id' => $templateId]);
    $flashMessage = 'Mapping-Vorlage gelöscht.';
    $flashType = 'success';
}

$templates = $pdo->query(
    'SELECT t.*, sct.name AS source_type_name, so.label AS source_object_label,
            tct.name AS target_type_name, tobj.label AS target_object_label,
            (SELECT COUNT(*) FROM connect_field_mappings fm WHERE fm.mapping_template_id = t.id) AS field_count,
            (SELECT COUNT(*) FROM connect_sync_mappings m WHERE m.mapping_template_id = t.id) AS activation_count
     FROM connect_mapping_templates t
     JOIN connect_connector_types sct ON sct.id = t.source_connector_type_id
     JOIN connect_objects so ON so.id = t.source_object_id
     JOIN connect_connector_types tct ON tct.id = t.target_connector_type_id
     JOIN connect_objects tobj ON tobj.id = t.target_object_id
     ORDER BY t.name'
)->fetchAll();

// Flache "Connector Type — Object"-Liste ueber ALLE Connector Types fuer die
// Quelle/Ziel-Auswahl -- der Connector Type selbst ergibt sich aus dem
// gewaehlten Object (siehe create_template oben), muss also nicht separat
// vorgewaehlt werden.
$objectOptions = $pdo->query(
    'SELECT o.id, o.label AS object_label, t.name AS type_name
     FROM connect_objects o
     JOIN connect_connector_types t ON t.id = o.connector_type_id
     ORDER BY t.name, o.label'
)->fetchAll();

$pageTitle = 'Mapping-Vorlagen';
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
                <h3 class="card-title">Mapping-Vorlagen</h3>
            </div>
            <p class="text-secondary px-3 pt-2">
                Einmal pflegen, von beliebig vielen Shops nutzen — z. B. "Shopify-Bestellung → MGM-Auftrag" oder
                "HelloCash-Rechnung → Shopify-Bestellung". Welche Connection eines Shops die Vorlage tatsächlich
                ausführt, wird pro Shop unter "Mappings" festgelegt.
            </p>
            <?php if (count($templates) === 0): ?>
                <div class="card-body text-secondary">Noch keine Mapping-Vorlage angelegt.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Quelle</th>
                                <th>Ziel</th>
                                <th>Felder</th>
                                <th>Aktivierungen</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $template): ?>
                                <tr>
                                    <td><a href="mapping_template_edit.php?id=<?php echo (int) $template['id']; ?>"><?php echo htmlspecialchars($template['name']); ?></a></td>
                                    <td><?php echo htmlspecialchars($template['source_type_name'] . ' — ' . $template['source_object_label']); ?></td>
                                    <td><?php echo htmlspecialchars($template['target_type_name'] . ' — ' . $template['target_object_label']); ?></td>
                                    <td>
                                        <?php if ((int) $template['field_count'] === 0): ?>
                                            <span class="badge bg-red-lt">keine</span>
                                        <?php else: ?>
                                            <?php echo (int) $template['field_count']; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo (int) $template['activation_count']; ?></td>
                                    <td class="text-end text-nowrap">
                                        <a href="mapping_template_edit.php?id=<?php echo (int) $template['id']; ?>" class="btn btn-sm btn-outline-primary">Felder bearbeiten</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Vorlage \'<?php echo htmlspecialchars(addslashes($template['name'])); ?>\' wirklich löschen? Das entfernt auch alle Aktivierungen bei Shops, die sie nutzen.');">
                                            <input type="hidden" name="action" value="delete_template">
                                            <input type="hidden" name="template_id" value="<?php echo (int) $template['id']; ?>">
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

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Neue Mapping-Vorlage anlegen</h3>
            </div>
            <div class="card-body">
                <?php if (count($objectOptions) === 0): ?>
                    <div class="text-secondary">Noch keine Objects vorhanden — zuerst unter "Connector Types" welche anlegen.</div>
                <?php else: ?>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="create_template">
                        <div class="col-md-4">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" placeholder="z. B. Shopify-Bestellung → MGM-Auftrag" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quelle</label>
                            <select name="source_object_id" class="form-select" required>
                                <option value="">-- auswählen --</option>
                                <?php foreach ($objectOptions as $option): ?>
                                    <option value="<?php echo (int) $option['id']; ?>"><?php echo htmlspecialchars($option['type_name'] . ' — ' . $option['object_label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ziel</label>
                            <select name="target_object_id" class="form-select" required>
                                <option value="">-- auswählen --</option>
                                <?php foreach ($objectOptions as $option): ?>
                                    <option value="<?php echo (int) $option['id']; ?>"><?php echo htmlspecialchars($option['type_name'] . ' — ' . $option['object_label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Anlegen und Felder zuordnen</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
