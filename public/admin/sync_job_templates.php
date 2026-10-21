<?php

declare(strict_types=1);

/**
 * connect/public/admin/sync_job_templates.php
 *
 * Uebersicht aller Cache-Sync-Job-Vorlagen (z.B. "Shopify: Produkt, alle 60
 * Minuten") + neue anlegen. Reines, shop-uebergreifendes Admin-Feature --
 * ersetzt die zuvor hart in shops.php kodierte Liste ($autoSyncObjectKeys).
 * Eine Vorlage wird EINMAL gepflegt und von beliebig vielen Shops
 * "aktiviert" (siehe sync_mappings.php, dort pro Shop) -- gleiches Prinzip
 * wie die Mapping-Vorlagen (mapping_templates.php).
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
    $objectId = (int) ($_POST['object_id'] ?? 0);
    $intervalMinutes = (int) ($_POST['default_interval_minutes'] ?? 0);
    $autoActivate = isset($_POST['auto_activate']) ? 1 : 0;

    if ($name === '' || $objectId <= 0) {
        $flashMessage = 'Bitte Name und Object angeben.';
        $flashType = 'danger';
    } elseif ($intervalMinutes <= 0) {
        $flashMessage = 'Bitte ein Intervall in Minuten (größer 0) angeben.';
        $flashType = 'danger';
    } else {
        $objectStmt = $pdo->prepare('SELECT connector_type_id FROM connect_objects WHERE id = :id');
        $objectStmt->execute(['id' => $objectId]);
        $connectorTypeId = $objectStmt->fetchColumn();

        if ($connectorTypeId === false) {
            $flashMessage = 'Object nicht gefunden.';
            $flashType = 'danger';
        } else {
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO connect_sync_job_templates (name, connector_type_id, object_id, default_interval_minutes, auto_activate)
                     VALUES (:name, :connector_type_id, :object_id, :interval, :auto_activate)'
                );
                $insert->execute([
                    'name' => $name,
                    'connector_type_id' => $connectorTypeId,
                    'object_id' => $objectId,
                    'interval' => $intervalMinutes,
                    'auto_activate' => $autoActivate,
                ]);
                $flashMessage = "Job-Vorlage \"{$name}\" angelegt.";
                $flashType = 'success';
            } catch (\Throwable $e) {
                // z.B. UNIQUE-Verstoss (schon eine Vorlage fuer dieses Object).
                $flashMessage = 'Fehler: ' . $e->getMessage();
                $flashType = 'danger';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_auto_activate') {
    $templateId = (int) ($_POST['template_id'] ?? 0);
    $pdo->prepare('UPDATE connect_sync_job_templates SET auto_activate = 1 - auto_activate WHERE id = :id')->execute(['id' => $templateId]);
    $flashMessage = 'Aktualisiert.';
    $flashType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate_all') {
    $templateId = (int) ($_POST['template_id'] ?? 0);
    $activated = \Connect\Sync\JobTemplateActivator::activateForAllShops($pdo, $templateId);
    $flashMessage = $activated > 0
        ? "Bei {$activated} Shop(s) aktiviert. Die ersten Läufe plant der Scheduler in den nächsten Minuten ein."
        : 'Alle Shops haben diesen Job schon.';
    $flashType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_template') {
    $templateId = (int) ($_POST['template_id'] ?? 0);
    $pdo->prepare('DELETE FROM connect_sync_job_templates WHERE id = :id')->execute(['id' => $templateId]);
    $flashMessage = 'Job-Vorlage gelöscht.';
    $flashType = 'success';
}

$templates = $pdo->query(
    'SELECT t.*, ct.name AS type_name, o.label AS object_label,
            (SELECT COUNT(*) FROM connect_sync_mappings m WHERE m.sync_job_template_id = t.id) AS activation_count
     FROM connect_sync_job_templates t
     JOIN connect_connector_types ct ON ct.id = t.connector_type_id
     JOIN connect_objects o ON o.id = t.object_id
     ORDER BY ct.name, o.label'
)->fetchAll();

// Nur Objects, fuer die es noch KEINE Vorlage gibt (UNIQUE KEY auf object_id)
// -- vermeidet den sonst erst beim Speichern sichtbaren Fehler.
$objectOptions = $pdo->query(
    'SELECT o.id, o.label AS object_label, t.name AS type_name
     FROM connect_objects o
     JOIN connect_connector_types t ON t.id = o.connector_type_id
     WHERE o.id NOT IN (SELECT object_id FROM connect_sync_job_templates)
       AND t.type_key != \'connect_internal\'
     ORDER BY t.name, o.label'
)->fetchAll();

$pageTitle = 'Job-Vorlagen';
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
                <h3 class="card-title">Job-Vorlagen (Cache-Synchronisierung)</h3>
            </div>
            <p class="text-secondary px-3 pt-2">
                Legt fest, WELCHE Objects regelmäßig im Hintergrund aktuell gehalten werden können und mit welchem
                Standard-Intervall — einmal pflegen, von beliebig vielen Shops unter "Jobs" aktivieren. "Automatisch
                aktivieren" heißt: jede neu per Migrationsbrücke übernommene Shopify-Connection bekommt diesen Job
                sofort ohne manuellen Schritt.
            </p>
            <?php if (count($templates) === 0): ?>
                <div class="card-body text-secondary">Noch keine Job-Vorlage angelegt.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>System</th>
                                <th>Object</th>
                                <th>Standard-Intervall</th>
                                <th>Automatisch aktivieren</th>
                                <th>Aktivierungen</th>
                                <th>Fehlt bei</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $template): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($template['name']); ?></td>
                                    <td><?php echo htmlspecialchars($template['type_name']); ?></td>
                                    <td><?php echo htmlspecialchars($template['object_label']); ?></td>
                                    <td><?php echo (int) $template['default_interval_minutes']; ?> Min.</td>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="toggle_auto_activate">
                                            <input type="hidden" name="template_id" value="<?php echo (int) $template['id']; ?>">
                                            <button type="submit" class="btn btn-sm <?php echo ((int) $template['auto_activate']) === 1 ? 'btn-outline-success' : 'btn-outline-secondary'; ?>">
                                                <?php echo ((int) $template['auto_activate']) === 1 ? 'Ja' : 'Nein'; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td><?php echo (int) $template['activation_count']; ?></td>
                                    <td>
                                        <?php $missingShops = \Connect\Sync\JobTemplateActivator::missingCount($pdo, (int) $template['id']); ?>
                                        <?php if ($missingShops === 0): ?>
                                            <span class="text-secondary">–</span>
                                        <?php else: ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Job \'<?php echo htmlspecialchars(addslashes($template['name'])); ?>\' jetzt bei <?php echo $missingShops; ?> Shop(s) aktivieren?');">
                                                <input type="hidden" name="action" value="activate_all">
                                                <input type="hidden" name="template_id" value="<?php echo (int) $template['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary"><?php echo $missingShops; ?> Shop(s) – jetzt aktivieren</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="post" onsubmit="return confirm('Job-Vorlage \'<?php echo htmlspecialchars(addslashes($template['name'])); ?>\' wirklich löschen? Bestehende Aktivierungen bei Shops bleiben als eigenständige Jobs erhalten, verlieren aber den Bezug zur Vorlage.');">
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
                <h3 class="card-title">Neue Job-Vorlage anlegen</h3>
            </div>
            <div class="card-body">
                <?php if (count($objectOptions) === 0): ?>
                    <div class="text-secondary">Für alle vorhandenen Objects gibt es bereits eine Job-Vorlage.</div>
                <?php else: ?>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="create_template">
                        <div class="col-md-4">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" placeholder="z. B. Shopify: Produkt" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Object</label>
                            <select name="object_id" class="form-select" required>
                                <option value="">-- auswählen --</option>
                                <?php foreach ($objectOptions as $option): ?>
                                    <option value="<?php echo (int) $option['id']; ?>"><?php echo htmlspecialchars($option['type_name'] . ' — ' . $option['object_label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Standard-Intervall (Min.)</label>
                            <input type="number" name="default_interval_minutes" class="form-control" value="60" min="1" required>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <label class="form-check">
                                <input type="checkbox" name="auto_activate" class="form-check-input">
                                <span class="form-check-label">Automatisch bei neuen Connections aktivieren</span>
                            </label>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Anlegen</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
