<?php

declare(strict_types=1);

/**
 * connect/public/admin/lookup_tables.php
 *
 * Uebersicht aller Lookup-Tabellen (z.B. "Land-ISO -> MGM-NamenID") --
 * Wertetabellen fuer den lookup-Transform in Mapping-Vorlagen (siehe
 * mapping_template_edit.php). Global wie die Vorlagen selbst -- einmal
 * gepflegt, von jeder Vorlage/jedem Shop nutzbar.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_lookup_table') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($name === '') {
        $flashMessage = 'Bitte einen Namen angeben.';
        $flashType = 'danger';
    } else {
        $insert = $pdo->prepare('INSERT INTO connect_lookup_tables (name, description) VALUES (:name, :description)');
        $insert->execute(['name' => $name, 'description' => $description !== '' ? $description : null]);

        header('Location: lookup_table_edit.php?id=' . $pdo->lastInsertId());
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_lookup_table') {
    $tableId = (int) ($_POST['table_id'] ?? 0);
    $pdo->prepare('DELETE FROM connect_lookup_tables WHERE id = :id')->execute(['id' => $tableId]);
    $flashMessage = 'Lookup-Tabelle gelöscht.';
    $flashType = 'success';
}

$tables = $pdo->query(
    'SELECT t.*, (SELECT COUNT(*) FROM connect_lookup_table_entries e WHERE e.lookup_table_id = t.id) AS entry_count
     FROM connect_lookup_tables t ORDER BY t.name'
)->fetchAll();

$pageTitle = 'Lookup-Tabellen';
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
                <h3 class="card-title">Lookup-Tabellen</h3>
            </div>
            <p class="text-secondary px-3 pt-2">
                Wertetabellen für die Umrechnung "Lookup" in Mapping-Vorlagen, z. B. Land-ISO-Code → interne Länder-ID.
            </p>
            <?php if (count($tables) === 0): ?>
                <div class="card-body text-secondary">Noch keine Lookup-Tabelle angelegt.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Beschreibung</th>
                                <th>Einträge</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tables as $table): ?>
                                <tr>
                                    <td><a href="lookup_table_edit.php?id=<?php echo (int) $table['id']; ?>"><?php echo htmlspecialchars($table['name']); ?></a></td>
                                    <td class="text-secondary"><?php echo htmlspecialchars((string) $table['description']); ?></td>
                                    <td><?php echo (int) $table['entry_count']; ?></td>
                                    <td class="text-end">
                                        <form method="post" onsubmit="return confirm('Lookup-Tabelle \'<?php echo htmlspecialchars(addslashes($table['name'])); ?>\' wirklich löschen?');">
                                            <input type="hidden" name="action" value="delete_lookup_table">
                                            <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
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
                <h3 class="card-title">Neue Lookup-Tabelle anlegen</h3>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="create_lookup_table">
                    <div class="col-md-4">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" placeholder="z. B. Land → NamenID" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Beschreibung</label>
                        <input type="text" name="description" class="form-control" placeholder="optional">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">Anlegen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
