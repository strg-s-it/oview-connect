<?php

declare(strict_types=1);

/**
 * connect/public/admin/functions.php
 *
 * "Funktionen": explizite Zuordnung "welche Mapping-Vorlage gehoert zu
 * welcher Aktion" (siehe connect_functions, Migration 0045). Ersetzt die
 * bisher implizite Auswahl "die zuletzt angelegte Vorlage gewinnt" in
 * Connect\Sync\ProductImportService::resolveMappingTemplateId() durch eine
 * admin-sichtbare, admin-gepflegte Zuordnung.
 *
 * Die Aktions-LOGIK bleibt fest im Code (function_kind ist ein kleiner,
 * fester Satz -- aktuell nur 'import_missing', der "Importieren"-Button auf
 * der Produkte-Seite) -- konfigurierbar ist nur, WELCHE Mapping-Vorlage
 * diese Logik fuer ein Objekt-Paar verwendet. Reines, shop-uebergreifendes
 * Admin-Feature, genau wie mapping_templates.php: eine Funktion wird EINMAL
 * angelegt und gilt automatisch fuer jeden Shop mit passender Connector-
 * Kombination, ohne Aktivierungsschritt.
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

// Jeder Wert braucht einen eigenen Code-Pfad, der ihn tatsaechlich abfragt
// (siehe Connect\Sync\ProductImportService::resolveMappingTemplateId() fuer
// 'import_missing', Connect\Sync\InvoiceService::resolveInvoiceMappingTemplateId()
// fuer 'invoice_create') -- neue Funktionsarten kommen als neuer Eintrag hier
// UND als neuer Code-Pfad dazu, z.B. 'update_existing' fuer ein kuenftiges
// "Aktualisieren".
$functionKindLabels = [
    'import_missing' => 'Fehlendes Produkt anlegen (Importieren)',
    'invoice_create' => 'Rechnung erstellen (Kopftext)',
];

$flashMessage = null;
$flashType = 'info';

// -----------------------------------------------------------------------
// POST: neue Funktion anlegen
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_function') {
    $functionKind = (string) ($_POST['function_kind'] ?? '');
    $mappingTemplateId = (int) ($_POST['mapping_template_id'] ?? 0);
    $label = trim((string) ($_POST['label'] ?? ''));

    if (!isset($functionKindLabels[$functionKind]) || $mappingTemplateId <= 0 || $label === '') {
        $flashMessage = 'Bitte Funktionsart, Mapping-Vorlage und Anzeigename angeben.';
        $flashType = 'danger';
    } else {
        $templateStmt = $pdo->prepare('SELECT source_object_id, target_object_id, name FROM connect_mapping_templates WHERE id = :id');
        $templateStmt->execute(['id' => $mappingTemplateId]);
        $template = $templateStmt->fetch();

        if ($template === false) {
            $flashMessage = 'Mapping-Vorlage nicht gefunden.';
            $flashType = 'danger';
        } else {
            // Verhindert, dass fuer dieselbe Funktionsart + dasselbe Objekt-
            // Paar zwei verschiedene Vorlagen hinterlegt werden -- genau die
            // Ambiguitaet, die diese Tabelle eigentlich aufloesen soll.
            $conflictStmt = $pdo->prepare(
                "SELECT f.id, t.name FROM connect_functions f
                 JOIN connect_mapping_templates t ON t.id = f.mapping_template_id
                 WHERE f.function_kind = :function_kind
                   AND t.source_object_id = :source_object_id AND t.target_object_id = :target_object_id"
            );
            $conflictStmt->execute([
                'function_kind' => $functionKind,
                'source_object_id' => $template['source_object_id'],
                'target_object_id' => $template['target_object_id'],
            ]);
            $conflict = $conflictStmt->fetch();

            if ($conflict !== false) {
                $flashMessage = "Für diese Objekt-Kombination ist bereits eine Funktion dieser Art hinterlegt: \"{$conflict['name']}\". Bitte zuerst die bestehende Funktion entfernen.";
                $flashType = 'danger';
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO connect_functions (function_kind, mapping_template_id, label)
                     VALUES (:function_kind, :mapping_template_id, :label)'
                );
                $insert->execute([
                    'function_kind' => $functionKind,
                    'mapping_template_id' => $mappingTemplateId,
                    'label' => $label,
                ]);
                $flashMessage = 'Funktion angelegt.';
                $flashType = 'success';
            }
        }
    }
}

// -----------------------------------------------------------------------
// POST: Funktion loeschen
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_function') {
    $pdo->prepare('DELETE FROM connect_functions WHERE id = :id')->execute(['id' => (int) ($_POST['function_id'] ?? 0)]);
    $flashMessage = 'Funktion gelöscht.';
    $flashType = 'success';
}

$functions = $pdo->query(
    "SELECT f.id, f.function_kind, f.label,
            t.id AS template_id, t.name AS template_name,
            sct.name AS source_type_name, so.label AS source_object_label,
            tct.name AS target_type_name, tobj.label AS target_object_label
     FROM connect_functions f
     JOIN connect_mapping_templates t ON t.id = f.mapping_template_id
     JOIN connect_connector_types sct ON sct.id = t.source_connector_type_id
     JOIN connect_objects so ON so.id = t.source_object_id
     JOIN connect_connector_types tct ON tct.id = t.target_connector_type_id
     JOIN connect_objects tobj ON tobj.id = t.target_object_id
     ORDER BY f.function_kind, t.name"
)->fetchAll();

$templateOptions = $pdo->query(
    'SELECT t.id, t.name, sct.name AS source_type_name, so.label AS source_object_label,
            tct.name AS target_type_name, tobj.label AS target_object_label
     FROM connect_mapping_templates t
     JOIN connect_connector_types sct ON sct.id = t.source_connector_type_id
     JOIN connect_objects so ON so.id = t.source_object_id
     JOIN connect_connector_types tct ON tct.id = t.target_connector_type_id
     JOIN connect_objects tobj ON tobj.id = t.target_object_id
     ORDER BY t.name'
)->fetchAll();

$preselectedTemplateId = (int) ($_GET['mapping_template_id'] ?? 0);

$pageTitle = 'Funktionen';
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
                <h3 class="card-title">Funktionen</h3>
            </div>
            <p class="text-secondary px-3 pt-2">
                Legt fest, welche Mapping-Vorlage eine Aktion (z. B. der "Importieren"-Button auf der Produkte-Seite)
                für ein Objekt-Paar verwendet. Ohne hinterlegte Funktion wird automatisch die zuletzt angelegte
                passende Mapping-Vorlage verwendet — Import funktioniert also auch ohne diese Einrichtung, eine
                Funktion sorgt nur für Eindeutigkeit und Sichtbarkeit, falls mehrere Vorlagen zum selben Objekt-Paar
                passen. Gilt automatisch für jeden Shop mit passender Connector-Kombination, ohne Aktivierungsschritt.
            </p>
            <p class="text-secondary px-3">
                Eine Funktionsart ist NICHT auf eine einzige Mapping-Vorlage beschränkt — pro Zielsystem (z. B.
                HelloCash, ein weiteres ERP) legst du eine eigene Funktion mit derselben Funktionsart an, jeweils mit
                der passenden Vorlage für dieses System. Die Liste unten zeigt dann mehrere Zeilen mit gleicher
                Funktionsart, eine je System.
            </p>
            <?php if (count($functions) === 0): ?>
                <div class="card-body text-secondary">Noch keine Funktion angelegt.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Funktionsart</th>
                                <th>Mapping-Vorlage</th>
                                <th>Anzeigename</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($functions as $function): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($functionKindLabels[$function['function_kind']] ?? $function['function_kind']); ?></td>
                                    <td>
                                        <a href="mapping_template_edit.php?id=<?php echo (int) $function['template_id']; ?>">
                                            <?php echo htmlspecialchars($function['template_name']); ?>
                                        </a>
                                        <div class="text-secondary">
                                            <?php echo htmlspecialchars($function['source_type_name'] . ' — ' . $function['source_object_label'] . ' → ' . $function['target_type_name'] . ' — ' . $function['target_object_label']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($function['label']); ?></td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Funktion wirklich löschen?');">
                                            <input type="hidden" name="action" value="delete_function">
                                            <input type="hidden" name="function_id" value="<?php echo (int) $function['id']; ?>">
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
                <h3 class="card-title">Neue Funktion anlegen</h3>
            </div>
            <div class="card-body">
                <?php if (count($templateOptions) === 0): ?>
                    <div class="text-secondary">Noch keine <a href="mapping_templates.php">Mapping-Vorlage</a> vorhanden.</div>
                <?php else: ?>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="create_function">
                        <div class="col-md-4">
                            <label class="form-label">Funktionsart</label>
                            <select name="function_kind" class="form-select" required>
                                <option value="">-- auswählen --</option>
                                <?php foreach ($functionKindLabels as $kind => $kindLabel): ?>
                                    <option value="<?php echo htmlspecialchars($kind); ?>"><?php echo htmlspecialchars($kindLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Mapping-Vorlage</label>
                            <select name="mapping_template_id" class="form-select" required>
                                <option value="">-- auswählen --</option>
                                <?php foreach ($templateOptions as $option): ?>
                                    <option value="<?php echo (int) $option['id']; ?>" <?php echo $preselectedTemplateId === (int) $option['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($option['name'] . ' (' . $option['source_type_name'] . ' — ' . $option['source_object_label'] . ' → ' . $option['target_type_name'] . ' — ' . $option['target_object_label'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Anzeigename</label>
                            <input type="text" name="label" class="form-control" placeholder="z. B. Importieren" value="Importieren" required>
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
