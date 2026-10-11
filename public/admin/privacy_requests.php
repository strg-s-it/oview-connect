<?php

declare(strict_types=1);

/**
 * connect/public/admin/privacy_requests.php
 *
 * Datenschutz-Anfragen ueber alle Shops: die Shopify-Pflicht-Webhooks
 * (customers/data_request, customers/redact, shop/redact) und app/uninstalled,
 * wie sie public/webhooks/privacy.php verarbeitet hat. Bei einer Datenauskunft
 * (customers/data_request) steht hier das Ergebnis, das an den Haendler
 * weitergegeben werden muss -- Shopify verlangt das innerhalb von 30 Tagen.
 * Ergebnisse und Anfragen werden nach der Aufbewahrungsfrist automatisch geloescht
 * (bin/purge_privacy_data.php). Die Seite braucht die Tabelle aus Migration 0051 und
 * zeigt ohne sie einen Hinweis statt eines Fehlers.
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

$topicLabels = [
    'customers/data_request' => 'Datenauskunft (Kunde)',
    'customers/redact' => 'Kundendaten löschen',
    'shop/redact' => 'Shop-Daten löschen',
    'app/uninstalled' => 'App deinstalliert',
];

$statusBadge = [
    'processed' => 'bg-green-lt',
    'received' => 'bg-secondary-lt',
    'error' => 'bg-red-lt',
];

// Nach der Migration 0051 vorhanden -- vorher eine verstaendliche Meldung statt SQL-Fehler.
$tableExists = $pdo->query("SHOW TABLES LIKE 'connect_privacy_requests'")->fetch() !== false;

$requests = [];
$detail = null;

if ($tableExists) {
    $detailId = (int) ($_GET['id'] ?? 0);
    if ($detailId > 0) {
        $detailStmt = $pdo->prepare('SELECT * FROM connect_privacy_requests WHERE id = :id');
        $detailStmt->execute(['id' => $detailId]);
        $detail = $detailStmt->fetch() ?: null;

        if ($detail === null) {
            \Connect\Http\ErrorPage::show(
                404,
                'Anfrage nicht gefunden',
                'Dieser Eintrag existiert nicht (mehr). Anfragen werden nach der Aufbewahrungsfrist automatisch gelöscht.',
                ['label' => 'Zur Übersicht', 'url' => 'privacy_requests.php']
            );
        }
    }

    $requests = $pdo->query(
        'SELECT id, shop, topic, shopify_customer_id, status, summary, received_at, processed_at,
                (result IS NOT NULL) AS has_result
         FROM connect_privacy_requests
         ORDER BY id DESC
         LIMIT 100'
    )->fetchAll();
}

$pageTitle = 'Datenschutz';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = []; // kein Shop-Bezug auf dieser Seite
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <?php if (!$tableExists): ?>
        <div class="col-12">
            <div class="alert alert-warning mb-0">
                Die Tabelle für Datenschutz-Anfragen fehlt noch. Bitte <code>php connect/bin/install.php</code> ausführen (Migration 0051).
            </div>
        </div>
    <?php else: ?>
        <?php if ($detail !== null): ?>
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            Anfrage #<?php echo (int) $detail['id']; ?> —
                            <?php echo htmlspecialchars($topicLabels[$detail['topic']] ?? $detail['topic']); ?>
                            (<?php echo htmlspecialchars($detail['shop']); ?>)
                        </h3>
                    </div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-3">Status</dt>
                            <dd class="col-9"><span class="badge <?php echo $statusBadge[$detail['status']] ?? 'bg-secondary-lt'; ?>"><?php echo htmlspecialchars($detail['status']); ?></span></dd>
                            <dt class="col-3">Empfangen</dt>
                            <dd class="col-9"><?php echo htmlspecialchars((string) $detail['received_at']); ?></dd>
                            <dt class="col-3">Verarbeitet</dt>
                            <dd class="col-9"><?php echo htmlspecialchars((string) $detail['processed_at']); ?></dd>
                            <dt class="col-3">Shopify-Kunden-ID</dt>
                            <dd class="col-9"><?php echo htmlspecialchars((string) $detail['shopify_customer_id']); ?></dd>
                            <dt class="col-3">Zähler</dt>
                            <dd class="col-9"><code><?php echo htmlspecialchars((string) $detail['summary']); ?></code></dd>
                            <?php if ($detail['error_message'] !== null): ?>
                                <dt class="col-3">Fehler</dt>
                                <dd class="col-9 text-danger"><?php echo htmlspecialchars((string) $detail['error_message']); ?></dd>
                            <?php endif; ?>
                        </dl>
                        <?php if ($detail['result'] !== null): ?>
                            <h4>Gefundene Daten (an den Händler weitergeben)</h4>
                            <p class="text-secondary">
                                Enthält Personendaten. Wird nach der Aufbewahrungsfrist automatisch gelöscht.
                                Die alte Welt schickt ihren Teil der Auskunft separat per E-Mail.
                            </p>
                            <pre class="p-3 bg-light border rounded" style="max-height:480px;overflow:auto"><?php echo htmlspecialchars((string) $detail['result']); ?></pre>
                        <?php endif; ?>
                        <a href="privacy_requests.php" class="btn btn-outline-secondary">Zur Übersicht</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Datenschutz-Anfragen (letzte 100)</h3>
                </div>
                <?php if (count($requests) === 0): ?>
                    <div class="card-body text-secondary">Noch keine Anfrage empfangen.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Shop</th>
                                    <th>Art</th>
                                    <th>Status</th>
                                    <th>Zähler</th>
                                    <th>Empfangen</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $request): ?>
                                    <tr>
                                        <td><?php echo (int) $request['id']; ?></td>
                                        <td><?php echo htmlspecialchars($request['shop']); ?></td>
                                        <td><?php echo htmlspecialchars($topicLabels[$request['topic']] ?? $request['topic']); ?></td>
                                        <td><span class="badge <?php echo $statusBadge[$request['status']] ?? 'bg-secondary-lt'; ?>"><?php echo htmlspecialchars($request['status']); ?></span></td>
                                        <td class="text-secondary"><code><?php echo htmlspecialchars((string) $request['summary']); ?></code></td>
                                        <td class="text-secondary"><?php echo htmlspecialchars((string) $request['received_at']); ?></td>
                                        <td class="text-end">
                                            <a href="privacy_requests.php?id=<?php echo (int) $request['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <?php echo (int) $request['has_result'] === 1 ? 'Daten ansehen' : 'Details'; ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>