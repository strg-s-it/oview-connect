<?php

declare(strict_types=1);

/**
 * connect/public/admin/lookup_table_edit.php
 *
 * Eintraege EINER (globalen) Lookup-Tabelle verwalten (Quellwert ->
 * Zielwert), z.B. "AT" -> "1", "DE" -> "2" fuer ein Land->NamenID-Mapping.
 * Gleiches Bulk-Editier-Muster wie die Felder-Tabelle in object_edit.php.
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

$tableId = (int) ($_GET['id'] ?? 0);

$tableStmt = $pdo->prepare('SELECT * FROM connect_lookup_tables WHERE id = :id');
$tableStmt->execute(['id' => $tableId]);
$table = $tableStmt->fetch();

if ($table === false) {
    \Connect\Http\ErrorPage::show(
        404,
        'Lookup-Tabelle nicht gefunden',
        'Dieser Eintrag existiert nicht (mehr) oder wurde gelöscht.',
        ['label' => 'Zu den Lookup-Tabellen', 'url' => 'lookup_tables.php']
    );
}

$flashMessage = null;
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_table') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($name === '') {
        $flashMessage = 'Der Name darf nicht leer sein.';
        $flashType = 'danger';
    } else {
        $pdo->prepare('UPDATE connect_lookup_tables SET name = :name, description = :description WHERE id = :id')
            ->execute(['name' => $name, 'description' => $description !== '' ? $description : null, 'id' => $tableId]);
        $table['name'] = $name;
        $table['description'] = $description;
        $flashMessage = 'Gespeichert.';
        $flashType = 'success';
    }
}

// Wird VOR "update_entries" geprueft: der "Entfernen"-Knopf sitzt im selben
// Formular (HTML erlaubt kein <form> im <form>, siehe unten) und traegt
// zusaetzlich zur normalen Sammel-Speicherung "delete_entry_id" -- dessen
// Vorhandensein entscheidet, welche Aktion tatsaechlich gemeint ist.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_entry_id'])) {
    $entryId = (int) $_POST['delete_entry_id'];
    $pdo->prepare('DELETE FROM connect_lookup_table_entries WHERE id = :id AND lookup_table_id = :table_id')
        ->execute(['id' => $entryId, 'table_id' => $tableId]);
    $flashMessage = 'Eintrag entfernt.';
    $flashType = 'success';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_entries') {
    $update = $pdo->prepare('UPDATE connect_lookup_table_entries SET source_value = :source_value, target_value = :target_value WHERE id = :id AND lookup_table_id = :table_id');

    foreach ((array) ($_POST['source_value'] ?? []) as $entryId => $sourceValue) {
        $entryId = (int) $entryId;
        $targetValue = (string) ($_POST['target_value'][$entryId] ?? '');
        $update->execute([
            'source_value' => trim((string) $sourceValue),
            'target_value' => trim($targetValue),
            'id' => $entryId,
            'table_id' => $tableId,
        ]);
    }

    $flashMessage = 'Einträge gespeichert.';
    $flashType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_entry') {
    $sourceValue = trim((string) ($_POST['source_value'] ?? ''));
    $targetValue = trim((string) ($_POST['target_value'] ?? ''));

    if ($sourceValue === '' || $targetValue === '') {
        $flashMessage = 'Bitte Quell- und Zielwert angeben.';
        $flashType = 'danger';
    } else {
        $pdo->prepare('INSERT INTO connect_lookup_table_entries (lookup_table_id, source_value, target_value) VALUES (:table_id, :source_value, :target_value)')
            ->execute(['table_id' => $tableId, 'source_value' => $sourceValue, 'target_value' => $targetValue]);
        $flashMessage = 'Eintrag hinzugefügt.';
        $flashType = 'success';
    }
}

$entriesStmt = $pdo->prepare('SELECT * FROM connect_lookup_table_entries WHERE lookup_table_id = :table_id ORDER BY source_value');
$entriesStmt->execute(['table_id' => $tableId]);
$entries = $entriesStmt->fetchAll();

$pageTitle = 'Lookup-Tabelle: ' . $table['name'];
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$breadcrumbs = [
    ['lookup_tables.php', 'Lookup-Tabellen'],
    [null, $table['name']],
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
                <h3 class="card-title">Stammdaten</h3>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="update_table">
                    <div class="col-md-4">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($table['name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Beschreibung</label>
                        <input type="text" name="description" class="form-control" value="<?php echo htmlspecialchars((string) $table['description']); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Einträge</h3>
            </div>
            <?php if (count($entries) === 0): ?>
                <div class="card-body text-secondary">Noch kein Eintrag angelegt.</div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="update_entries">
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Quellwert</th>
                                    <th>Zielwert</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entries as $entry): ?>
                                    <tr>
                                        <td><input type="text" name="source_value[<?php echo (int) $entry['id']; ?>]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($entry['source_value']); ?>"></td>
                                        <td><input type="text" name="target_value[<?php echo (int) $entry['id']; ?>]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($entry['target_value']); ?>"></td>
                                        <td>
                                            <button type="submit" name="delete_entry_id" value="<?php echo (int) $entry['id']; ?>" formnovalidate class="btn btn-sm btn-outline-danger">Entfernen</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-body">
                        <button type="submit" class="btn btn-primary">Alle Einträge speichern</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Eintrag hinzufügen</h3>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="add_entry">
                    <div class="col-md-4">
                        <label class="form-label">Quellwert</label>
                        <input type="text" name="source_value" class="form-control" placeholder="z. B. AT" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Zielwert</label>
                        <input type="text" name="target_value" class="form-control" placeholder="z. B. 1" required>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">Hinzufügen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
