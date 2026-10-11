<?php

declare(strict_types=1);

/**
 * connect/public/admin/legal_texts.php
 *
 * Rechtstexte fuer das erste Fenster der Merchants (app/welcome.php, Migration 0076):
 * Datenschutzerklaerung, AVV, Haftungshinweis -- mit Versionen.
 *   Entwurf speichern        -- fuer Merchants noch unsichtbar
 *   Neue Version veroeffentlichen -- alle Shops muessen beim naechsten Oeffnen neu zustimmen
 *   Korrektur                -- aktuelle Version aendern, ohne neue Zustimmung (Tippfehler)
 * Erst ein veroeffentlichter Text wird abgefragt.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Setup\LegalTexts;

$config = Config::load();
$pdo = Database::connect($config)->pdo();
$auth = new AdminAuth($pdo);
$auth->requireLogin();

$flashMessage = null;
$flashType = 'info';
$tableMissing = false;
$legal = new LegalTexts($pdo);

try {
    $pdo->query('SELECT 1 FROM connect_legal_texts LIMIT 1');
} catch (\Throwable) {
    $tableMissing = true;
}

if (!$tableMissing && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_legal_text') {
    $docKey = (string) ($_POST['doc_key'] ?? '');
    $mode = in_array($_POST['mode'] ?? '', ['draft', 'publish', 'correction'], true) ? (string) $_POST['mode'] : 'draft';
    $title = trim((string) ($_POST['title'] ?? ''));
    $summary = trim((string) ($_POST['summary'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));

    if (!isset(LegalTexts::DOCS[$docKey]) || $title === '' || $body === '') {
        $flashMessage = 'Bitte Titel und Text ausfüllen.';
        $flashType = 'danger';
    } else {
        try {
            $version = $legal->save($docKey, $mode, $title, $summary !== '' ? $summary : null, $body, $auth->currentEmail());
            $flashMessage = match ($mode) {
                'publish' => "Version {$version} veröffentlicht – alle Shops stimmen beim nächsten Öffnen neu zu.",
                'correction' => "Version {$version} korrigiert (keine neue Zustimmung nötig).",
                default => "Entwurf (Version {$version}) gespeichert – für Merchants noch unsichtbar.",
            };
            $flashType = 'success';
        } catch (\Throwable $e) {
            $flashMessage = $e->getMessage();
            $flashType = 'danger';
        }
    }
}

$docs = $tableMissing ? [] : $legal->latestForEditing();
$published = $tableMissing ? [] : $legal->published();
$acceptedCounts = $tableMissing ? [] : $legal->acceptedCounts();
$shopCount = (int) $pdo->query('SELECT COUNT(*) FROM connect_shops')->fetchColumn();

$pageTitle = 'Rechtstexte';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<?php if ($tableMissing): ?>
    <div class="alert alert-warning">Migration 0076 ist noch nicht eingespielt.</div>
<?php else: ?>
    <p class="text-secondary">
        Diese Texte sehen Merchants im ersten Fenster von Connect und müssen zustimmen, bevor sie das Dashboard sehen.
        Formatierung: Leerzeile = neuer Absatz, Zeile mit <code># </code> am Anfang = Überschrift, <code>## </code> = Unterüberschrift, Adressen (https://…) werden zu Links.
        Den Inhalt bitte rechtlich prüfen lassen.
    </p>
    <div class="row row-cards">
        <?php foreach (LegalTexts::DOCS as $docKey => [$docLabel, $acceptSentence]): ?>
            <?php
            $doc = $docs[$docKey] ?? null;
            $current = $published[$docKey] ?? null;
            $isDraft = $doc !== null && $doc['published_at'] === null;
            ?>
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><?php echo htmlspecialchars($docLabel); ?></h3>
                        <div class="card-actions">
                            <?php if ($current !== null): ?>
                                <span class="badge bg-green-lt">veröffentlicht: Version <?php echo (int) $current['version']; ?> seit <?php echo htmlspecialchars((string) $current['published_at']); ?></span>
                                <span class="badge bg-secondary-lt"><?php echo (int) ($acceptedCounts[$docKey] ?? 0); ?> von <?php echo $shopCount; ?> Shops zugestimmt</span>
                            <?php else: ?>
                                <span class="badge bg-yellow-lt">nicht veröffentlicht – wird nicht abgefragt</span>
                            <?php endif; ?>
                            <?php if ($isDraft): ?>
                                <span class="badge bg-blue-lt">Entwurf Version <?php echo (int) $doc['version']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-2">
                            <input type="hidden" name="action" value="save_legal_text">
                            <input type="hidden" name="doc_key" value="<?php echo htmlspecialchars($docKey); ?>">
                            <div class="col-md-6">
                                <label class="form-label">Titel</label>
                                <input type="text" name="title" class="form-control" required value="<?php echo htmlspecialchars((string) ($doc['title'] ?? $docLabel)); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Kurztext auf der Kachel (optional)</label>
                                <input type="text" name="summary" class="form-control" maxlength="500" value="<?php echo htmlspecialchars((string) ($doc['summary'] ?? '')); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Text</label>
                                <textarea name="body" rows="10" class="form-control" required><?php echo htmlspecialchars((string) ($doc['body'] ?? '')); ?></textarea>
                                <small class="text-secondary">Zustimmung auf der Kachel: „<?php echo htmlspecialchars($acceptSentence); ?>“</small>
                            </div>
                            <div class="col-12 d-flex flex-wrap gap-2 mt-2">
                                <button type="submit" name="mode" value="draft" class="btn btn-outline-primary">Als Entwurf speichern</button>
                                <button type="submit" name="mode" value="publish" class="btn btn-primary"
                                    onclick="return confirm('Als neue Version veröffentlichen? Alle Shops müssen beim nächsten Öffnen von Connect neu zustimmen.');">
                                    <?php echo $isDraft ? 'Entwurf veröffentlichen' : 'Als neue Version veröffentlichen'; ?>
                                </button>
                                <?php if ($current !== null && !$isDraft): ?>
                                    <button type="submit" name="mode" value="correction" class="btn btn-outline-secondary"
                                        onclick="return confirm('Nur korrigieren (z. B. Tippfehler)? Bisherige Zustimmungen bleiben gültig – bei inhaltlichen Änderungen stattdessen eine neue Version veröffentlichen.');">
                                        Korrektur ohne neue Zustimmung
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                        <?php if ($doc !== null && trim((string) $doc['body']) !== ''): ?>
                            <details class="mt-3">
                                <summary class="text-secondary" style="cursor:pointer;">Vorschau wie beim Merchant</summary>
                                <div class="border rounded p-3 mt-2"><?php echo LegalTexts::render((string) $doc['body']); ?></div>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
