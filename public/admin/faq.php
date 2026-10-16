<?php

declare(strict_types=1);

/**
 * connect/public/admin/faq.php
 *
 * FAQ fuer Merchants pflegen (Connect\Faq\FaqBoard, Migration 0102): Fragen anlegen, aendern,
 * loeschen, Reihenfolge ueber die Sortierzahl, einzeln oder alle Entwuerfe freigeben.
 * Nur veroeffentlichte Eintraege sehen Merchants (app/faq.php).
 *
 * Mit ?shop=…&preview=1 (Merchant-Menue in der Vorschau): die Merchant-Ansicht dieses Shops.
 */

if (isset($_GET['shop'], $_GET['preview'])) {
    $app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';
    $isAdmin = false;
    require dirname(__DIR__) . '/app/_faq_body.php';

    return;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Faq\FaqBoard;
use Connect\Setup\LegalTexts;

$config = Config::load();
$pdo = Database::connect($config)->pdo();
$auth = new AdminAuth($pdo);
$auth->requireLogin();

$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$board = new FaqBoard($pdo);
$available = $board->available();
$flashMessage = null;
$flashType = 'info';
$editId = (int) ($_GET['edit'] ?? 0);
$failedForm = null;

if ($available && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($action === 'save_faq') {
            $error = $board->save(
                $id,
                (string) ($_POST['category'] ?? ''),
                (string) ($_POST['question'] ?? ''),
                (string) ($_POST['answer'] ?? ''),
                (int) ($_POST['sort_order'] ?? 100),
                isset($_POST['is_published'])
            );
            if ($error === null) {
                $flashMessage = $id > 0 ? 'Eintrag gespeichert.' : 'Eintrag angelegt.';
                $flashType = 'success';
            } else {
                $flashMessage = $error;
                $flashType = 'danger';
                // Eingabe nach einem Validierungsfehler nicht verlieren
                $failedForm = [
                    'id' => $id,
                    'category' => (string) ($_POST['category'] ?? ''),
                    'question' => (string) ($_POST['question'] ?? ''),
                    'answer' => (string) ($_POST['answer'] ?? ''),
                    'sort_order' => (int) ($_POST['sort_order'] ?? 100),
                    'is_published' => isset($_POST['is_published']) ? 1 : 0,
                ];
            }
        } elseif ($action === 'toggle_faq') {
            $publish = ($_POST['publish'] ?? '') === '1';
            $board->setPublished($id, $publish);
            $flashMessage = $publish ? 'Eintrag ist jetzt für Händler sichtbar.' : 'Eintrag ist wieder ein Entwurf.';
            $flashType = 'success';
        } elseif ($action === 'publish_all') {
            $count = $board->publishAllDrafts();
            $flashMessage = $count . ' Entwurf/Entwürfe veröffentlicht.';
            $flashType = 'success';
        } elseif ($action === 'delete_faq') {
            $board->delete($id);
            $flashMessage = 'Eintrag gelöscht.';
            $flashType = 'success';
        }
    } catch (\Throwable $ex) {
        $flashMessage = 'Speichern fehlgeschlagen: ' . $ex->getMessage();
        $flashType = 'danger';
    }
}

$items = $available ? $board->all() : [];
$groups = FaqBoard::grouped($items);
$draftCount = count(array_filter($items, static fn (array $i): bool => $i['is_published'] === 0));
$editing = null;
foreach ($items as $item) {
    if ($item['id'] === $editId) {
        $editing = $item;
    }
}
$form = $failedForm ?? $editing ?? [
    'id' => 0,
    'category' => '',
    'question' => '',
    'answer' => '',
    'sort_order' => $available ? $board->nextSortOrder() : 100,
    'is_published' => 0,
];

$pageTitle = 'FAQ';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$topRightHtml = '<span class="text-secondary me-3">' . $e($auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<?php if (!$available): ?>
    <div class="alert alert-warning">Migration 0102 ist noch nicht eingespielt (<code>php bin/install.php</code> am Server).</div>
<?php else: ?>
    <p class="text-secondary">
        Diese Fragen sehen Händler im Menü unter <strong>FAQ</strong> – aber nur, wenn sie <strong>veröffentlicht</strong> sind.
        Entwürfe sieht nur ihr hier. Formatierung der Antwort: Leerzeile = neuer Absatz, Zeile mit <code># </code> am Anfang = Überschrift,
        Adressen (https://…) werden zu Links, kein freies HTML. Die Reihenfolge ergibt sich aus der Sortierzahl (kleiner = weiter oben).
    </p>

    <?php if ($draftCount > 0): ?>
        <div class="alert alert-warning d-flex align-items-center gap-3">
            <div class="flex-fill"><?php echo (int) $draftCount; ?> Eintrag/Einträge sind noch Entwurf und für Händler unsichtbar. Erst lesen und anpassen, dann freigeben.</div>
            <form method="post" class="m-0" onsubmit="return confirm('Alle Entwürfe jetzt für Händler sichtbar machen?');">
                <input type="hidden" name="action" value="publish_all">
                <button type="submit" class="btn btn-warning">Alle Entwürfe veröffentlichen</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="row row-cards">
        <div class="col-lg-5">
            <div class="card" id="faq-form">
                <div class="card-header"><h3 class="card-title"><?php echo $form['id'] > 0 ? 'Eintrag bearbeiten' : 'Neuer Eintrag'; ?></h3></div>
                <div class="card-body">
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="action" value="save_faq">
                        <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                        <div class="mb-2">
                            <label class="form-label" for="faq-category">Kategorie</label>
                            <input id="faq-category" type="text" name="category" class="form-control" maxlength="80" required list="faq-categories" value="<?php echo $e($form['category']); ?>">
                            <datalist id="faq-categories">
                                <?php foreach ($board->categories() as $category): ?>
                                    <option value="<?php echo $e($category); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="faq-question">Frage</label>
                            <input id="faq-question" type="text" name="question" class="form-control" maxlength="300" required value="<?php echo $e($form['question']); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="faq-answer">Antwort</label>
                            <textarea id="faq-answer" name="answer" rows="9" class="form-control" required><?php echo $e($form['answer']); ?></textarea>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label" for="faq-sort">Sortierzahl</label>
                                <input id="faq-sort" type="number" name="sort_order" class="form-control" value="<?php echo (int) $form['sort_order']; ?>">
                            </div>
                            <div class="col-6 d-flex align-items-end">
                                <label class="form-check">
                                    <input type="checkbox" name="is_published" value="1" class="form-check-input"<?php echo (int) $form['is_published'] === 1 ? ' checked' : ''; ?>>
                                    <span class="form-check-label">Für Händler sichtbar</span>
                                </label>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Speichern</button>
                        <?php if ($form['id'] > 0): ?>
                            <a href="faq.php" class="btn btn-link">Abbrechen</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <?php if ($groups === []): ?>
                <div class="card"><div class="card-body text-secondary">Noch keine Einträge.</div></div>
            <?php endif; ?>
            <?php foreach ($groups as $category => $groupItems): ?>
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title"><?php echo $e((string) $category); ?></h3></div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($groupItems as $item): ?>
                            <div class="list-group-item">
                                <div class="d-flex align-items-start gap-2">
                                    <div class="flex-fill">
                                        <div class="fw-bold"><?php echo $e($item['question']); ?></div>
                                        <details class="mt-1">
                                            <summary class="text-secondary small" style="cursor:pointer;">Antwort ansehen</summary>
                                            <div class="border rounded p-2 mt-1"><?php echo LegalTexts::render($item['answer']); ?></div>
                                        </details>
                                    </div>
                                    <div class="text-end text-nowrap">
                                        <span class="badge <?php echo $item['is_published'] === 1 ? 'bg-green-lt' : 'bg-yellow-lt'; ?>"><?php echo $item['is_published'] === 1 ? 'sichtbar' : 'Entwurf'; ?></span>
                                        <div class="small text-secondary mt-1">Sortierzahl <?php echo (int) $item['sort_order']; ?></div>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 mt-2">
                                    <a href="faq.php?edit=<?php echo (int) $item['id']; ?>#faq-form" class="btn btn-sm">Bearbeiten</a>
                                    <form method="post" class="m-0">
                                        <input type="hidden" name="action" value="toggle_faq">
                                        <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                        <input type="hidden" name="publish" value="<?php echo $item['is_published'] === 1 ? '0' : '1'; ?>">
                                        <button type="submit" class="btn btn-sm"><?php echo $item['is_published'] === 1 ? 'Zurück zum Entwurf' : 'Veröffentlichen'; ?></button>
                                    </form>
                                    <form method="post" class="m-0 ms-auto" onsubmit="return confirm('Diesen Eintrag endgültig löschen?');">
                                        <input type="hidden" name="action" value="delete_faq">
                                        <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
