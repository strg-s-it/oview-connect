<?php

declare(strict_types=1);

/**
 * connect/public/admin/messages.php
 *
 * Betrieb -> Nachrichten: Mails an Merchants der alten und neuen Welt (Migration 0082,
 * Connect\Notify\MessageService, Connect\Notify\MerchantDirectory).
 *   ohne id        Liste aller Nachrichten
 *   ?new=1         neuer Entwurf (optional &template=scopes|maintenance)
 *   ?id=N          Entwurf bearbeiten: Text, Empfaenger-Filter, Vorschau, Testmail, Senden
 *                  bzw. nach dem Senden: Status je Empfaenger
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Notify\MerchantDirectory;
use Connect\Notify\MessageService;

$config = Config::load();
$pdo = Database::connect($config)->pdo();
$auth = new AdminAuth($pdo);
$auth->requireLogin();
$adminEmail = (string) $auth->currentEmail();

$service = new MessageService($pdo, $config);
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);

$templates = [
    'scopes' => [
        'label' => 'Shopify-Berechtigungen erneuern',
        'kind' => 'service',
        'subject' => 'oView: Bitte neue Berechtigungen in Shopify bestätigen',
        'body' => "Hallo {firma},\n\ndamit oView für deinen Shop {shop} weiterhin alle Funktionen ausführen kann, braucht die App zusätzliche Berechtigungen in Shopify: {fehlende_berechtigungen}\n\nBitte melde dich in deinem Shopify-Admin an und öffne diesen Link. Shopify zeigt dir die Berechtigungen, die du dort bestätigst:\n{berechtigungen_link}\n\nDas dauert weniger als eine Minute. An deinen Einstellungen in oView ändert sich nichts.\n\nBei Fragen antworte einfach auf diese Mail.\n\nLiebe Grüße\ndein oView-Team",
        'filter' => ['scopes' => 'missing'],
    ],
    'prepare' => [
        'label' => 'Umstieg vorbereiten (Shopify + helloCash-Token)',
        'kind' => 'service',
        'subject' => 'oView: Bitte Umstieg auf die neue Technik vorbereiten (5 Minuten)',
        'body' => "Hallo {firma},\n\nwir stellen oView für deinen Shop {shop} in den nächsten Wochen auf eine neue, schnellere Technik um. Damit das reibungslos klappt, brauchen wir zwei kleine Dinge von dir:\n\n1. Neue Berechtigungen in Shopify bestätigen\n2. Den API-Token in helloCash neu generieren und eintragen (helloCash vergibt Berechtigungen inzwischen je Token, dein bisheriger hat nicht alle)\n\nAuf dieser Seite führen wir dich Schritt für Schritt durch – das dauert etwa fünf Minuten:\n{vorbereitung_link}\n\nWichtig: Sobald du in helloCash auf „Neu generieren“ klickst, funktioniert der bisherige Token nicht mehr. Trag den neuen deshalb gleich danach auf dieser Seite ein, dann läuft alles ohne Unterbrechung weiter.\n\nDer Link ist persönlich und 60 Tage gültig, bitte nicht weitergeben.\n\nBei Fragen antworte einfach auf diese Mail.\n\nLiebe Grüße\ndein oView-Team",
        'filter' => ['world' => ['legacy', 'pending'], 'token' => 'open'],
    ],
    'maintenance' => [
        'label' => 'Wartungshinweis',
        'kind' => 'service',
        'subject' => 'oView: Kurze Wartung am [Datum]',
        'body' => "Hallo {firma},\n\nam [Datum] zwischen [Uhrzeit] und [Uhrzeit] führen wir Wartungsarbeiten durch. In dieser Zeit werden Lagerbestände und Rechnungen verzögert abgeglichen. Danach holt oView alles automatisch nach, du musst nichts tun.\n\nLiebe Grüße\ndein oView-Team",
        'filter' => [],
    ],
];

$flashMessage = null;
$flashType = 'info';
$id = (int) ($_GET['id'] ?? 0);

/** Filter aus dem Formular (Arrays/Strings) */
$readFilter = static function (array $source): array {
    return [
        'world' => array_values(array_intersect((array) ($source['world'] ?? []), array_keys(MerchantDirectory::WORLD_LABELS))),
        'plan' => array_values(array_intersect((array) ($source['plan'] ?? []), ['standard', 'plus', 'plusplus', 'none'])),
        'system' => trim((string) ($source['system'] ?? '')),
        'scopes' => in_array($source['scopes'] ?? '', ['missing', 'ok', 'unknown'], true) ? (string) $source['scopes'] : '',
        'token' => in_array($source['token'] ?? '', ['ok', 'open'], true) ? (string) $source['token'] : '',
        'setup' => in_array($source['setup'] ?? '', ['done', 'open'], true) ? (string) $source['setup'] : '',
        'installed_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($source['installed_from'] ?? '')) ? (string) $source['installed_from'] : '',
        'installed_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($source['installed_to'] ?? '')) ? (string) $source['installed_to'] : '',
        'stock_paused' => !empty($source['stock_paused']) ? 1 : 0,
        'q' => trim((string) ($source['q'] ?? '')),
        'exclude' => array_values(array_map('strval', (array) ($source['exclude'] ?? []))),
    ];
};

// ---- Neue Nachricht: erst beim ersten Speichern/Testen/Senden in der DB angelegt -----------
// Beim blossen Aufruf (?new=1) entsteht nur ein nicht gespeicherter Entwurf (id 0).
$isNew = isset($_GET['new']) && $id === 0;
$formAction = 'messages.php';
if ($isNew) {
    $templateKey = (string) ($_GET['template'] ?? '');
    $template = $templates[$templateKey] ?? null;
    $message = [
        'id' => 0,
        'kind' => $template['kind'] ?? 'service',
        'subject' => $template['subject'] ?? 'oView: ',
        'body' => $template['body'] ?? "Hallo {firma},\n\n\n\nLiebe Grüße\ndein oView-Team",
        'in_app' => 1,
        'filter' => json_encode(($template['filter'] ?? []) + ['world' => ['live', 'pending', 'legacy']], JSON_UNESCAPED_UNICODE),
        'status' => 'draft',
    ];
    $formAction = 'messages.php?new=1' . ($templateKey !== '' ? '&template=' . rawurlencode($templateKey) : '');
} else {
    $message = $id > 0 ? $service->message($id) : null;
    $formAction = 'messages.php?id=' . $id;
}
$directory = new MerchantDirectory($pdo, $config);

// ---- Aktionen am Entwurf -------------------------------------------------------------
if ($message !== null && $message['status'] === 'draft' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $filter = $readFilter($_POST);
    $kind = ($_POST['kind'] ?? 'service') === 'info' ? 'info' : 'service';
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));

    if ($action === 'delete') {
        if ($id > 0) {
            $pdo->prepare("DELETE FROM connect_messages WHERE id = :id AND status = 'draft'")->execute(['id' => $id]);
        }
        header('Location: messages.php');
        exit;
    }
    if ($subject === '' || $body === '') {
        $flashMessage = 'Betreff und Text dürfen nicht leer sein.';
        $flashType = 'danger';
    } else {
        if ($id === 0) {
            $pdo->prepare('INSERT INTO connect_messages (kind, subject, body, in_app, filter, created_by) VALUES (:kind, :subject, :body, :in_app, :filter, :by)')
                ->execute(['kind' => $kind, 'subject' => $subject, 'body' => $body, 'in_app' => 1, 'filter' => json_encode($filter), 'by' => $adminEmail]);
            $id = (int) $pdo->lastInsertId();
            $formAction = 'messages.php?id=' . $id;
        }
        $pdo->prepare('UPDATE connect_messages SET kind = :kind, subject = :subject, body = :body, in_app = :in_app, filter = :filter WHERE id = :id')
            ->execute(['kind' => $kind, 'subject' => $subject, 'body' => $body, 'in_app' => isset($_POST['in_app']) ? 1 : 0, 'filter' => json_encode($filter), 'id' => $id]);
        $message = $service->message($id);
        $flashMessage = 'Entwurf gespeichert.';
        $flashType = 'success';

        $recipients = array_values(array_filter(MerchantDirectory::filter($directory->all(), $filter), static fn (array $m): bool => !in_array($m['shop'], $filter['exclude'], true)));

        if ($action === 'test') {
            $previewShop = (string) ($_POST['preview_shop'] ?? '');
            $sample = null;
            foreach ($recipients as $m) {
                if ($sample === null || $m['shop'] === $previewShop) {
                    $sample = $m;
                }
            }
            if ($sample === null) {
                $flashMessage = 'Kein Empfänger in der Auswahl – die Testmail braucht einen Shop für die Platzhalter.';
                $flashType = 'danger';
            } else {
                $error = $service->sendTest($message, $sample, $adminEmail);
                $flashMessage = $error === null ? "Testmail (wie für {$sample['shop']}) an {$adminEmail} verschickt." : 'Testmail fehlgeschlagen: ' . $error;
                $flashType = $error === null ? 'success' : 'danger';
            }
        } elseif ($action === 'send') {
            if (empty($_POST['confirm'])) {
                $flashMessage = 'Bitte den Versand bestätigen (Häkchen).';
                $flashType = 'warning';
            } elseif ($recipients === []) {
                $flashMessage = 'Keine Empfänger ausgewählt.';
                $flashType = 'danger';
            } else {
                $result = $service->queue($id, $recipients);
                $flashMessage = "Zum Versand freigegeben: {$result['queued']} Mail(s), {$result['skipped']} übersprungen (keine Adresse bzw. abgemeldet). Der Versand läuft im Hintergrund (ca. 20 je Minute).";
                $flashType = 'success';
                $message = $service->message($id);
            }
        }
    }
}

// ---- Anzeige -------------------------------------------------------------------------
$pageTitle = 'Nachrichten';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$topRightHtml = '<span class="text-secondary me-3">' . $e($adminEmail) . '</span><a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
if ($message !== null) {
    $breadcrumbs = [['messages.php', 'Nachrichten'], [null, (string) $message['subject']]];
}
require dirname(__DIR__) . '/app/_header.php';
?>

<?php if (!$service->smtpConfigured()): ?>
    <div class="alert alert-warning">
        Für den Versand von <strong><?php echo $e($service->fromAddress()); ?></strong> fehlen die SMTP-Zugangsdaten in <code>connect/.env</code>
        (<code>CONNECT_MESSAGE_SMTP_HOST</code>, <code>_PORT</code>, <code>_USER</code>, <code>_PASS</code>, <code>_ENCRYPTION</code>).
        Ohne sie gehen die Mails über die Mailfunktion des Servers und landen eher im Spam.
    </div>
<?php endif; ?>

<?php if ($message === null): ?>
    <?php
    $list = [];
    try {
        $list = $pdo->query(
            "SELECT m.*, (SELECT COUNT(*) FROM connect_message_recipients r WHERE r.message_id = m.id AND r.status = 'sent') AS sent,
                    (SELECT COUNT(*) FROM connect_message_recipients r WHERE r.message_id = m.id AND r.status = 'failed') AS failed,
                    (SELECT COUNT(*) FROM connect_message_recipients r WHERE r.message_id = m.id AND r.status = 'pending') AS pending
             FROM connect_messages m ORDER BY m.id DESC LIMIT 100"
        )->fetchAll();
    } catch (\PDOException) {
        echo '<div class="alert alert-danger">Migration 0082 fehlt noch.</div>';
    }
    ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Nachrichten an Merchants</h3>
            <div class="card-actions d-flex gap-2">
                <a href="messages.php?new=1" class="btn btn-primary btn-sm">Neue Nachricht</a>
                <?php foreach ($templates as $key => $template): ?>
                    <a href="messages.php?new=1&amp;template=<?php echo $e($key); ?>" class="btn btn-outline-primary btn-sm">Vorlage: <?php echo $e($template['label']); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Betreff</th><th>Art</th><th>Status</th><th>Empfänger</th><th>Erstellt</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($list as $row): ?>
                        <tr>
                            <td><?php echo $e($row['subject']); ?></td>
                            <td><?php echo $row['kind'] === 'info' ? 'Information' : 'Service'; ?><?php echo (int) $row['in_app'] === 1 ? ' · auch in der App' : ''; ?></td>
                            <td>
                                <?php echo ['draft' => '<span class="badge bg-secondary-lt">Entwurf</span>', 'queued' => '<span class="badge bg-blue-lt">wird versendet</span>', 'done' => '<span class="badge bg-green-lt">versendet</span>'][$row['status']] ?? $e($row['status']); ?>
                            </td>
                            <td class="small"><?php echo $row['status'] === 'draft' ? '–' : (int) $row['sent'] . ' gesendet' . ((int) $row['failed'] > 0 ? ' · ' . (int) $row['failed'] . ' Fehler' : '') . ((int) $row['pending'] > 0 ? ' · ' . (int) $row['pending'] . ' offen' : ''); ?></td>
                            <td class="small text-secondary"><?php echo $e(substr((string) $row['created_at'], 0, 16)); ?> · <?php echo $e($row['created_by']); ?></td>
                            <td class="text-end"><a href="messages.php?id=<?php echo (int) $row['id']; ?>" class="btn btn-sm"><?php echo $row['status'] === 'draft' ? 'Bearbeiten' : 'Ansehen'; ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($list === []): ?>
                        <tr><td colspan="6" class="text-secondary">Noch keine Nachrichten.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($message['status'] !== 'draft'): ?>
    <?php
    $rows = $pdo->prepare('SELECT * FROM connect_message_recipients WHERE message_id = :id ORDER BY FIELD(status, \'failed\', \'pending\', \'skipped\', \'sent\'), shop');
    $rows->execute(['id' => $id]);
    $recipientRows = $rows->fetchAll();
    $counts = array_count_values(array_column($recipientRows, 'status'));
    ?>
    <div class="row row-cards">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><?php echo $e($message['subject']); ?></h3></div>
                <div class="card-body">
                    <div class="small text-secondary mb-2">
                        <?php echo $message['kind'] === 'info' ? 'Information (mit Abmelde-Link)' : 'Service'; ?><?php echo (int) $message['in_app'] === 1 ? ' · auch in der App' : ''; ?>
                        · freigegeben <?php echo $e((string) $message['queued_at']); ?><?php echo $message['finished_at'] ? ' · fertig ' . $e((string) $message['finished_at']) : ''; ?>
                    </div>
                    <div class="d-flex flex-wrap gap-1 mb-3">
                        <?php foreach (['sent' => ['bg-green-lt', 'gesendet'], 'pending' => ['bg-blue-lt', 'offen'], 'failed' => ['bg-red-lt', 'Fehler'], 'skipped' => ['bg-secondary-lt', 'übersprungen']] as $status => [$badge, $label]): ?>
                            <?php if (($counts[$status] ?? 0) > 0): ?><span class="badge <?php echo $badge; ?>"><?php echo (int) $counts[$status]; ?> <?php echo $label; ?></span><?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="border rounded p-2 small" style="white-space: pre-wrap;"><?php echo $e($message['body']); ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Empfänger</h3></div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table table-sm">
                        <thead><tr><th>Shop</th><th>Adresse</th><th>Status</th><th>Gesendet</th><th>In der App</th></tr></thead>
                        <tbody>
                            <?php foreach ($recipientRows as $r): ?>
                                <tr>
                                    <td><?php echo $e($r['shop']); ?> <span class="text-secondary small"><?php echo $r['world'] === 'legacy' ? '(alte Welt)' : ''; ?></span></td>
                                    <td class="small"><?php echo $e($r['email'] ?? '–'); ?></td>
                                    <td>
                                        <span class="badge <?php echo ['sent' => 'bg-green-lt', 'pending' => 'bg-blue-lt', 'failed' => 'bg-red-lt', 'skipped' => 'bg-secondary-lt'][$r['status']] ?? ''; ?>"><?php echo $e(['sent' => 'gesendet', 'pending' => 'offen', 'failed' => 'Fehler', 'skipped' => 'übersprungen'][$r['status']] ?? $r['status']); ?></span>
                                        <?php if ($r['error']): ?><div class="small text-secondary"><?php echo $e($r['error']); ?></div><?php endif; ?>
                                    </td>
                                    <td class="small text-secondary"><?php echo $e((string) ($r['sent_at'] ?? '')); ?></td>
                                    <td class="small text-secondary"><?php echo (int) $message['in_app'] === 1 && $r['world'] === 'connect' ? ($r['read_at'] ? 'gelesen ' . $e(substr((string) $r['read_at'], 0, 10)) : 'noch nicht gelesen') : '–'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <?php
    $filter = json_decode((string) ($message['filter'] ?? ''), true) ?: [];
    $filter = $readFilter($filter);
    $all = $matches = $recipients = $systems = [];
    $preview = $rendered = null;
    $withEmail = 0;
    // Letzte gesendete Nachricht je Shop (wie auf der Umstieg-Seite)
    $lastMessages = [];
    try {
        foreach ($pdo->query(
            "SELECT r.shop, MAX(r.sent_at) AS last_sent, COUNT(*) AS sent FROM connect_message_recipients r WHERE r.status = 'sent' GROUP BY r.shop"
        )->fetchAll() as $lastRow) {
            $lastMessages[(string) $lastRow['shop']] = $lastRow;
        }
    } catch (\PDOException) {
        // Migration 0082 fehlt
    }
    try {
        $all = $directory->all();
        $matches = MerchantDirectory::filter($all, $filter);
        $recipients = array_values(array_filter($matches, static fn (array $m): bool => !in_array($m['shop'], $filter['exclude'], true)));
        $systems = array_values(array_unique(array_filter(array_map(static fn (array $m): string => (string) ($m['system'] ?? ''), $all))));
        $previewShop = (string) ($_POST['preview_shop'] ?? ($recipients[0]['shop'] ?? ''));
        foreach ($recipients as $m) {
            if ($m['shop'] === $previewShop) {
                $preview = $m;
            }
        }
        $preview ??= $recipients[0] ?? null;
        $rendered = $preview !== null ? $service->render($message, $preview) : null;
        $withEmail = count(array_filter($recipients, static fn (array $m): bool => $m['email'] !== null && !($message['kind'] === 'info' && $m['optout'])));
    } catch (\Throwable $ex) {
        error_log('[messages] ' . $ex);
        echo '<div class="alert alert-danger">Empfängerliste konnte nicht geladen werden: ' . $e($ex->getMessage()) . ' <span class="text-secondary">(' . $e(basename($ex->getFile()) . ':' . $ex->getLine()) . ')</span></div>';
    }
    ?>
    <?php if ($directory->legacyError() !== null): ?>
        <div class="alert alert-warning"><?php echo $e($directory->legacyError()); ?></div>
    <?php endif; ?>

    <form method="post" action="<?php echo $e($formAction); ?>">
    <?php if ($id === 0): ?>
        <div class="alert alert-info">Noch nicht gespeichert – der Entwurf wird erst beim ersten Speichern, Testen oder Senden angelegt.</div>
    <?php endif; ?>
    <div class="row row-cards">
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">1 · Text</h3></div>
                <div class="card-body d-grid gap-3">
                    <div class="d-flex gap-3 flex-wrap">
                        <label class="form-check mb-0"><input type="radio" class="form-check-input" name="kind" value="service" <?php echo $message['kind'] !== 'info' ? 'checked' : ''; ?>> <span class="form-check-label">Service <span class="text-secondary small">(zum Vertrag: Wartung, Berechtigungen, Störungen)</span></span></label>
                        <label class="form-check mb-0"><input type="radio" class="form-check-input" name="kind" value="info" <?php echo $message['kind'] === 'info' ? 'checked' : ''; ?>> <span class="form-check-label">Information <span class="text-secondary small">(mit Abmelde-Link)</span></span></label>
                    </div>
                    <div>
                        <label class="form-label" for="msg-subject">Betreff</label>
                        <input id="msg-subject" type="text" name="subject" class="form-control" maxlength="255" value="<?php echo $e($message['subject']); ?>" required>
                    </div>
                    <div>
                        <label class="form-label" for="msg-body">Text</label>
                        <textarea id="msg-body" name="body" rows="14" class="form-control" required><?php echo $e($message['body']); ?></textarea>
                        <small class="text-secondary">Leerzeile = neuer Absatz, <code># </code> am Zeilenanfang = Überschrift, Links werden automatisch klickbar.</small>
                    </div>
                    <div class="small">
                        <strong>Platzhalter</strong> (je Shop ersetzt):
                        <?php foreach (MessageService::PLACEHOLDERS as $placeholder => $help): ?>
                            <div><code><?php echo $e($placeholder); ?></code> <span class="text-secondary"><?php echo $e($help); ?></span></div>
                        <?php endforeach; ?>
                    </div>
                    <label class="form-check mb-0">
                        <input type="checkbox" class="form-check-input" name="in_app" <?php echo (int) $message['in_app'] === 1 ? 'checked' : ''; ?>>
                        <span class="form-check-label">Auch in der App anzeigen <span class="text-secondary small">(Hinweis oben in der Übersicht, bis der Merchant ihn wegklickt – nur Shops in Connect)</span></span>
                    </label>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">3 · Vorschau</h3></div>
                <div class="card-body">
                    <?php if ($rendered === null): ?>
                        <div class="text-secondary">Keine Empfänger ausgewählt.</div>
                    <?php else: ?>
                        <div class="d-flex gap-2 align-items-end mb-2 flex-wrap">
                            <div>
                                <label class="form-label small mb-1" for="preview-shop">So sieht es aus für</label>
                                <select id="preview-shop" name="preview_shop" class="form-select form-select-sm">
                                    <?php foreach ($recipients as $m): ?>
                                        <option value="<?php echo $e($m['shop']); ?>" <?php echo $m['shop'] === ($preview['shop'] ?? '') ? 'selected' : ''; ?>><?php echo $e($m['shop']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="action" value="save" class="btn btn-sm">Aktualisieren</button>
                            <button type="submit" name="action" value="test" class="btn btn-sm btn-outline-primary">Testmail an <?php echo $e($adminEmail); ?></button>
                        </div>
                        <div class="small text-secondary mb-1">Betreff: <strong><?php echo $e($rendered[0]); ?></strong></div>
                        <iframe title="Vorschau" sandbox="" style="width:100%;height:460px;border:1px solid var(--tblr-border-color);border-radius:8px" srcdoc="<?php echo $e($rendered[2]); ?>"></iframe>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">2 · Empfänger</h3></div>
                <div class="card-body d-grid gap-2">
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach (MerchantDirectory::WORLD_LABELS as $key => $label): ?>
                            <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="world[]" value="<?php echo $e($key); ?>" <?php echo in_array($key, $filter['world'], true) ? 'checked' : ''; ?>> <span class="form-check-label"><?php echo $e($label); ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex flex-wrap gap-3">
                        <span class="text-secondary small">Abo:</span>
                        <?php foreach (['standard' => 'HELLOCASH', 'plus' => '+', 'plusplus' => '++', 'none' => 'keines/unbekannt'] as $key => $label): ?>
                            <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="plan[]" value="<?php echo $e($key); ?>" <?php echo in_array($key, $filter['plan'], true) ? 'checked' : ''; ?>> <span class="form-check-label"><?php echo $e($label); ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <select name="system" class="form-select form-select-sm" aria-label="Software">
                                <option value="">Software: alle</option>
                                <?php foreach ($systems as $system): ?>
                                    <option value="<?php echo $e($system); ?>" <?php echo strcasecmp($filter['system'], $system) === 0 ? 'selected' : ''; ?>><?php echo $e($system); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="scopes" class="form-select form-select-sm" aria-label="Shopify-Berechtigungen">
                                <?php foreach (['' => 'Berechtigungen: alle', 'missing' => 'Berechtigungen fehlen', 'ok' => 'Berechtigungen vollständig', 'unknown' => 'Berechtigungen unbekannt'] as $key => $label): ?>
                                    <option value="<?php echo $e($key); ?>" <?php echo $filter['scopes'] === $key ? 'selected' : ''; ?>><?php echo $e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="setup" class="form-select form-select-sm" aria-label="Einrichtung">
                                <?php foreach (['' => 'Einrichtung: alle', 'done' => 'Einrichtung abgeschlossen', 'open' => 'Einrichtung offen'] as $key => $label): ?>
                                    <option value="<?php echo $e($key); ?>" <?php echo $filter['setup'] === $key ? 'selected' : ''; ?>><?php echo $e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="token" class="form-select form-select-sm" aria-label="helloCash-Token">
                                <?php foreach (['' => 'helloCash-Token: alle', 'open' => 'neuer Token fehlt noch', 'ok' => 'neuer Token hinterlegt'] as $key => $label): ?>
                                    <option value="<?php echo $e($key); ?>" <?php echo $filter['token'] === $key ? 'selected' : ''; ?>><?php echo $e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><input type="date" name="installed_from" class="form-control form-control-sm" value="<?php echo $e($filter['installed_from']); ?>" title="installiert ab" aria-label="installiert ab"></div>
                        <div class="col-md-4"><input type="date" name="installed_to" class="form-control form-control-sm" value="<?php echo $e($filter['installed_to']); ?>" title="installiert bis" aria-label="installiert bis"></div>
                        <div class="col-md-4"><input type="search" name="q" class="form-control form-control-sm" value="<?php echo $e($filter['q']); ?>" placeholder="Suche Shop, Name, E-Mail"></div>
                    </div>
                    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="stock_paused" value="1" <?php echo $filter['stock_paused'] ? 'checked' : ''; ?>> <span class="form-check-label">nur Shops mit angehaltenem Lagerabgleich</span></label>
                    <div><button type="submit" name="action" value="save" class="btn btn-sm">Filter anwenden</button></div>
                </div>
                <div class="card-body border-top pt-2 pb-2 small d-flex flex-wrap gap-2 align-items-center">
                    <span class="me-auto"><strong id="msg-count-selected"><?php echo count($recipients); ?></strong> Shops ausgewählt, davon <strong class="msg-count-email"><?php echo $withEmail; ?></strong> mit Mail-Adresse<?php echo $message['kind'] === 'info' ? ' (ohne Abgemeldete)' : ''; ?>. Häkchen entfernen, um einen Shop auszunehmen.</span>
                    <button type="button" class="btn btn-sm" onclick="msgSelectAll(true)">Alle auswählen</button>
                    <button type="button" class="btn btn-sm" onclick="msgSelectAll(false)">Alle abwählen</button>
                </div>
                <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                    <table class="table table-vcenter card-table table-sm">
                        <thead><tr><th class="w-1"></th><th>Shop</th><th>Welt</th><th>Adresse</th><th>Abo</th><th>Berechtigungen</th><th>helloCash-Token</th><th>Letzte Nachricht</th></tr></thead>
                        <tbody>
                            <?php foreach ($matches as $m): ?>
                                <?php $excluded = in_array($m['shop'], $filter['exclude'], true); ?>
                                <tr class="<?php echo $excluded ? 'text-secondary' : ''; ?>">
                                    <td>
                                        <input type="checkbox" class="form-check-input msg-include" data-exclude="ex-<?php echo $e(md5($m['shop'])); ?>" data-mail="<?php echo $m['email'] !== null && !($message['kind'] === 'info' && $m['optout']) ? '1' : '0'; ?>" <?php echo $excluded ? '' : 'checked'; ?> onchange="document.getElementById(this.dataset.exclude).disabled=this.checked; msgRecount();" aria-label="<?php echo $e($m['shop']); ?> einbeziehen">
                                        <input type="hidden" id="ex-<?php echo $e(md5($m['shop'])); ?>" name="exclude[]" value="<?php echo $e($m['shop']); ?>" <?php echo $excluded ? '' : 'disabled'; ?>>
                                    </td>
                                    <td><?php echo $e($m['shop']); ?><?php echo $m['company'] ? '<div class="small text-secondary">' . $e($m['company']) . '</div>' : ''; ?></td>
                                    <td class="small"><?php echo $e(['live' => 'Connect', 'pending' => 'Connect (nicht umgestellt)', 'legacy' => 'alte Welt'][$m['world']]); ?></td>
                                    <td class="small">
                                        <?php if ($m['email'] === null): ?><span class="text-danger">keine</span>
                                        <?php else: ?><?php echo $e($m['email']); ?> <span class="text-secondary">(<?php echo $e($m['email_source']); ?>)</span><?php endif; ?>
                                        <?php echo $m['optout'] ? '<span class="badge bg-secondary-lt">abgemeldet</span>' : ''; ?>
                                    </td>
                                    <td class="small"><?php echo $e($m['plan_label']); ?></td>
                                    <td class="small"><?php echo $m['scopes_missing'] === null ? '<span class="text-secondary">unbekannt</span>' : ($m['scopes_missing'] === [] ? '<span class="text-success">vollständig</span>' : '<span class="text-danger">fehlen: ' . $e(implode(', ', $m['scopes_missing'])) . '</span>'); ?></td>
                                    <td class="small"><?php echo ['ok' => '<span class="text-success">neu, geprüft</span>', 'missing' => '<span class="text-danger">unvollständig</span>', 'unknown' => '<span class="text-secondary">offen</span>'][$m['token'] ?? 'unknown'] ?? ''; ?></td>
                                    <?php $lastSent = $lastMessages[$m['shop']] ?? null; ?>
                                    <td class="small text-secondary"><?php echo $lastSent !== null ? $e(substr((string) $lastSent['last_sent'], 0, 10)) . ((int) $lastSent['sent'] > 1 ? ' (' . (int) $lastSent['sent'] . ' gesamt)' : '') : '–'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($matches === []): ?>
                                <tr><td colspan="8" class="text-secondary">Kein Shop passt zu den Filtern.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">4 · Senden</h3></div>
                <div class="card-body d-grid gap-2">
                    <div class="small text-secondary">Absender <strong><?php echo $e($service->fromAddress()); ?></strong>. Antworten kommen dort an. Versand im Hintergrund, ca. 20 Mails je Minute.</div>
                    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="confirm" value="1"> <span class="form-check-label">Text und Empfänger geprüft – an <span class="msg-count-email"><?php echo $withEmail; ?></span> Merchant(s) senden</span></label>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" name="action" value="send" class="btn btn-primary" <?php echo $withEmail === 0 && (int) $message['in_app'] !== 1 ? 'disabled' : ''; ?>>Senden</button>
                        <button type="submit" name="action" value="save" class="btn">Entwurf speichern</button>
                        <button type="submit" name="action" value="delete" class="btn btn-outline-danger ms-auto" formnovalidate onclick="return confirm('Entwurf löschen?')">Entwurf löschen</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </form>
    <script>
        // Zahlen sofort nachziehen, wenn ein Shop per Haekchen aus- oder eingeschlossen wird
        function msgRecount() {
            var boxes = document.querySelectorAll('.msg-include');
            var selected = 0, withMail = 0;
            boxes.forEach(function (box) {
                if (box.checked) {
                    selected++;
                    if (box.dataset.mail === '1') { withMail++; }
                }
            });
            document.getElementById('msg-count-selected').textContent = selected;
            document.querySelectorAll('.msg-count-email').forEach(function (el) { el.textContent = withMail; });
        }
        // Alle gefilterten Shops ein- bzw. ausschliessen (wirkt beim naechsten Speichern/Senden)
        function msgSelectAll(state) {
            document.querySelectorAll('.msg-include').forEach(function (box) {
                box.checked = state;
                document.getElementById(box.dataset.exclude).disabled = state;
            });
            msgRecount();
        }
    </script>
<?php endif; ?>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
