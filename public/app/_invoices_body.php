<?php

declare(strict_types=1);

/**
 * connect/public/app/_invoices_body.php
 *
 * Merchant-Ansicht "Rechnungen": Status der automatischen Rechnungsanlage
 * (Connect\Sync\InvoiceService, ausgeloest ueber den orders/paid-Webhook) +
 * paginierte Liste jedes Anlage-Versuchs aus connect_activity_log
 * (object_key='invoice', operation='create') -- sowohl erfolgreiche als auch
 * fehlgeschlagene (letztere gehen zusaetzlich per Mail an die in den
 * Einstellungen hinterlegte Fehler-Adresse, siehe InvoiceService::notifyError(),
 * stehen hier aber genauso im Protokoll). Gleiches Grundmuster wie
 * _stock_body.php (Status-Karte + paginierte Tabelle), von admin/invoices.php
 * (Admin-Drilldown zu einem Shop) UND app/invoices.php (Merchant-Einstieg)
 * gemeinsam genutzt.
 *
 * Die Bestellnummer steckt bewusst in der Nachricht selbst ("Bestellung 1234: ..."),
 * nicht in einer eigenen Spalte (siehe Connect\Sync\InvoiceService::createForOrder()) --
 * hier per einfachem Regex wieder herausgeloest, rein fuer die Anzeige.
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

$isAdmin = $isAdmin ?? false;

$pdo = $app->pdo;
$shop = $app->shop;

// -----------------------------------------------------------------------
// Pagination -- gleiches Muster wie _stock_body.php.
// -----------------------------------------------------------------------
$pageSizeOptions = [10, 25, 50, 100];
$pageSize = (int) ($_GET['page_size'] ?? 25);
if (!in_array($pageSize, $pageSizeOptions, true)) {
    $pageSize = 25;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM connect_activity_log WHERE shop = :shop AND object_key = 'invoice' AND operation = 'create'"
);
$countStmt->execute(['shop' => $shop]);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $pageSize;

// $pageSize/$offset sind bereits auf feste Werte geclampt (siehe oben) -- direkt
// interpoliert statt gebunden, wie in _stock_body.php begruendet.
$logStmt = $pdo->prepare(
    "SELECT status, message, external_id, created_at, request_body, response_body, sync_run_id
     FROM connect_activity_log
     WHERE shop = :shop AND object_key = 'invoice' AND operation = 'create'
     ORDER BY created_at DESC, id DESC
     LIMIT {$pageSize} OFFSET {$offset}"
);
$logStmt->execute(['shop' => $shop]);
$logRows = $logStmt->fetchAll();

// -----------------------------------------------------------------------
// Status-Karte: Konfiguration (gleiche Datenquelle wie die Karte
// "Rechnungen/Aufträge" in _sync_mappings_body.php) + Gesamtzahlen.
// -----------------------------------------------------------------------
$shopSettingsStmt = $pdo->prepare(
    'SELECT s.invoice_creation_enabled, s.invoice_mail_enabled, s.target_connection_id,
            c.label AS target_label, t.name AS target_type_name
     FROM connect_shops s
     LEFT JOIN connect_connections c ON c.id = s.target_connection_id
     LEFT JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE s.shop = :shop'
);
$shopSettingsStmt->execute(['shop' => $shop]);
$shopSettings = $shopSettingsStmt->fetch();

// -----------------------------------------------------------------------
// PDF einer Rechnung (?pdf=<ID im Zielsystem>): live beim Zielsystem abgeholt ueber die
// Operation "read_pdf" am Object "invoice" (Connector Builder, z.B. helloCash v2
// GET invoices/{{id}}/pdf) und direkt weitergereicht -- Connect speichert nichts. Nur IDs,
// die dieser Shop selbst angelegt hat (Protokoll), damit keine fremden Belege abrufbar sind.
// -----------------------------------------------------------------------
$pdfAvailable = false;
if ($shopSettings !== false && $shopSettings['target_connection_id'] !== null) {
    $pdfOpStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM connect_object_operations op
         JOIN connect_objects o ON o.id = op.object_id
         JOIN connect_connections c ON c.connector_type_id = o.connector_type_id
         WHERE c.id = :conn AND o.object_key = 'invoice' AND op.operation_type = 'read_pdf'"
    );
    $pdfOpStmt->execute(['conn' => (int) $shopSettings['target_connection_id']]);
    $pdfAvailable = (int) $pdfOpStmt->fetchColumn() > 0;
}
$flashMessage = null;
$flashType = 'info';

// -----------------------------------------------------------------------
// Fehlgeschlagene Rechnung erneut versuchen (Connect\Sync\InvoiceRetryService). CSRF prueft
// bootstrap.php / admin_bootstrap.php; welche Bestellungen erlaubt sind, entscheidet der Service
// anhand des Dedup-Protokolls (nur 'error' dieses Shops), nicht anhand des POST.
// -----------------------------------------------------------------------
$retryService = new \Connect\Sync\InvoiceRetryService($pdo, new \Connect\Connector\ConnectorFactory(
    $pdo,
    $app->crypto,
    new \Connect\Http\HttpClient(),
    \Connect\Shopify\TokenRefresher::fromConfig($app->config)
));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'retry_invoice') {
    @set_time_limit(120);
    $retryResult = $retryService->retry($shop, trim((string) ($_POST['source_id'] ?? '')));
    $flashMessage = $retryResult['ok']
        ? 'Rechnung angelegt: ' . $retryResult['message']
        : 'Rechnung konnte nicht angelegt werden: ' . $retryResult['message'];
    $flashType = $retryResult['ok'] ? 'success' : 'danger';
}
try {
    $failedInvoices = $retryService->failedForShop($shop);
} catch (\Throwable $e) {
    $failedInvoices = [];
}

if (isset($_GET['pdf']) && $pdfAvailable) {
    $pdfId = trim((string) $_GET['pdf']);
    $ownStmt = $pdo->prepare(
        "SELECT response_body FROM connect_activity_log
         WHERE shop = :shop AND object_key = 'invoice' AND operation = 'create' AND status = 'success' AND external_id = :id
         ORDER BY id DESC LIMIT 1"
    );
    $ownStmt->execute(['shop' => $shop, 'id' => $pdfId]);
    $ownResponse = $ownStmt->fetchColumn();
    if ($pdfId === '' || $ownResponse === false) {
        $flashMessage = 'Diese Rechnung gehört nicht zu deinem Shop oder wurde nicht von oView angelegt.';
        $flashType = 'danger';
    } else {
        try {
            $pdfFactory = new \Connect\Connector\ConnectorFactory(
                $pdo,
                $app->crypto,
                new \Connect\Http\HttpClient(),
                new \Connect\Shopify\TokenRefresher(new \Connect\Http\HttpClient(), $app->config->optional('CONNECT_SHOPIFY_API_KEY'), $app->config->optional('CONNECT_SHOPIFY_API_SECRET'))
            );
            $pdfConnector = $pdfFactory->forConnection((int) $shopSettings['target_connection_id']);
            if (!$pdfConnector instanceof \Connect\Connector\Generic\GenericHttpConnector) {
                throw new \RuntimeException('Dieses System unterstützt keinen PDF-Abruf.');
            }
            // external_id ist je nach Belegprofil die Belegnummer (helloCash: data.invoice_number),
            // der PDF-Abruf braucht aber meist die interne ID -- die steht am Anfang der gespeicherten
            // Antwort. {{id}} = interne ID (sonst external_id), {{number}} = external_id.
            $internalId = connect_invoice_internal_id((string) $ownResponse) ?? $pdfId;
            $pdf = $pdfConnector->fetchDocument('invoice', [
                'id' => ctype_digit($internalId) ? (int) $internalId : $internalId,
                'number' => $pdfId,
            ]);
            if (!$pdf['ok']) {
                throw new \RuntimeException((string) $pdf['error']);
            }
            $invoiceNumber = connect_invoice_number((string) $ownResponse) ?? $pdfId;
            $filename = $pdf['filename'] ?? ('Rechnung-' . preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceNumber) . '.pdf');
            header('Content-Type: application/pdf');
            // attachment statt inline: im Shopify-iframe wuerde ein angezeigtes PDF die App ersetzen,
            // und ein neuer Tab bekommt in Safari die (partitionierte) Sitzung nicht immer mit
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
            header('Content-Length: ' . strlen($pdf['body']));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            echo $pdf['body'];
            exit;
        } catch (\Throwable $e) {
            $flashMessage = 'PDF konnte nicht geladen werden: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

$successCountStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM connect_activity_log WHERE shop = :shop AND object_key = 'invoice' AND operation = 'create' AND status = 'success'"
);
$successCountStmt->execute(['shop' => $shop]);
$successCount = (int) $successCountStmt->fetchColumn();
$errorCount = $totalCount - $successCount;

$statusBadge = ['success' => 'bg-green-lt', 'error' => 'bg-red-lt'];

/**
 * Loest "Bestellung 1234: Rest der Nachricht" wieder in [Bestellnummer, Rest]
 * auf (siehe Klassenkommentar oben) -- liefert [null, $message] unveraendert,
 * wenn das Muster (z.B. bei sehr alten, vor dieser Aenderung protokollierten
 * Zeilen) nicht passt, damit nichts verloren geht.
 *
 * @return array{0: ?string, 1: string}
 */
function connect_invoice_split_order_number(string $message): array
{
    if (preg_match('/^Bestellung ([^:]+): (.*)$/s', $message, $matches) === 1) {
        return [$matches[1], $matches[2]];
    }

    return [null, $message];
}

/**
 * Rechnungsnummer aus der Antwort des Zielsystems (z.B. helloCash data.invoice_number) --
 * die gespeicherte ID (external_id) ist die interne Kennung, nicht die Nummer auf dem Beleg.
 */
function connect_invoice_number(string $responseBody): ?string
{
    if (!preg_match('/"invoice_number"\s*:\s*"?([A-Za-z0-9\/_-]+)"?/', $responseBody, $m)) {
        return null;
    }

    return $m[1];
}

/**
 * Interne ID des Belegs aus der gespeicherten Antwort ({"data":{"id":…} oder {"id":…}) --
 * per Regex, weil die Antwort im Protokoll gekuerzt sein kann (mitgeliefertes PDF als Base64)
 * und dann kein gueltiges JSON mehr ist. Die ID steht in beiden Formen am Anfang.
 */
function connect_invoice_internal_id(string $responseBody): ?string
{
    if (preg_match('/^\s*\{\s*(?:"data"\s*:\s*\{\s*)?"id"\s*:\s*"?([A-Za-z0-9_-]+)/', $responseBody, $m) === 1) {
        return $m[1];
    }

    return null;
}

/**
 * Siehe admin/activity_log.php::connect_pretty_body() -- gleiche Idee (JSON
 * huebsch einruecken, sonst Rohtext), bewusst eigener Funktionsname, damit
 * beide Dateien unabhaengig voneinander includebar bleiben.
 */
function connect_invoice_pretty_body(string $body): string
{
    $decoded = json_decode($body, true);
    $pretty = json_last_error() === JSON_ERROR_NONE
        ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : $body;

    return mb_strlen($pretty) > 20000 ? mb_substr($pretty, 0, 20000) . "\n… (gekürzt)" : $pretty;
}

$pageTitle = 'Rechnungen' . ($isAdmin ? ' — ' . $shop : '');
$navItems = require __DIR__ . '/_shop_nav.php';

if ($isAdmin) {
    $homeUrl = 'shops.php';
    $adminNavItems = require dirname(__DIR__) . '/admin/_nav.php';
    $adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
    $topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
        . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
}

require __DIR__ . '/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Status</h3>
            </div>
            <div class="card-body">
                <?php if ($shopSettings === false || $shopSettings['target_connection_id'] === null): ?>
                    <div class="alert alert-info mb-0">
                        Noch keine Software verbunden<?php echo $isAdmin ? '.' : ' — bitte unter "Einstellungen" einrichten.'; ?>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-secondary">Verbunden mit</div>
                            <div><?php echo htmlspecialchars((string) $shopSettings['target_label'] . ' (' . $shopSettings['target_type_name'] . ')'); ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-secondary">Automatische Rechnungsanlage</div>
                            <div>
                                <span class="badge <?php echo ((int) $shopSettings['invoice_creation_enabled']) === 1 ? 'bg-green-lt' : 'bg-secondary-lt'; ?>">
                                    <?php echo ((int) $shopSettings['invoice_creation_enabled']) === 1 ? 'aktiv' : 'inaktiv'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-secondary">Rechnungsmail an Kunden</div>
                            <div>
                                <span class="badge <?php echo ((int) $shopSettings['invoice_mail_enabled']) === 1 ? 'bg-green-lt' : 'bg-secondary-lt'; ?>">
                                    <?php echo ((int) $shopSettings['invoice_mail_enabled']) === 1 ? 'aktiv' : 'inaktiv'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-secondary">Bisher angelegt</div>
                            <div>
                                <strong><?php echo $successCount; ?></strong> erfolgreich
                                <?php if ($errorCount > 0): ?>
                                    <span class="text-secondary">/ <?php echo $errorCount; ?> fehlgeschlagen</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (count($failedInvoices) > 0): ?>
    <div class="col-12" id="failed">
        <div class="card border-warning">
            <div class="card-header">
                <h3 class="card-title mb-0">Nicht angelegte Rechnungen (<?php echo count($failedInvoices); ?>)</h3>
            </div>
            <div class="card-body text-secondary pb-0">
                Für diese Bestellungen konnte keine Rechnung erstellt werden. Behebe zuerst die Ursache (siehe Meldung), dann kannst du die Rechnung hier nachträglich anlegen lassen. Bestellungen, für die schon eine Rechnung existiert, werden nie doppelt angelegt.
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead><tr><th>Bestellung</th><th>Fehlgeschlagen am</th><th>Meldung</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($failedInvoices as $failed): ?>
                            <tr>
                                <td class="text-nowrap"><strong><?php echo htmlspecialchars($failed['order']); ?></strong></td>
                                <td class="text-secondary text-nowrap"><?php echo htmlspecialchars($failed['at']); ?></td>
                                <td><?php echo htmlspecialchars(mb_substr($failed['message'], 0, 300)); ?></td>
                                <td class="text-end">
                                    <form method="post" onsubmit="return confirm('Rechnung für <?php echo htmlspecialchars($failed['order'], ENT_QUOTES); ?> jetzt in <?php echo htmlspecialchars((string) ($shopSettings['target_type_name'] ?? 'dem Kassensystem'), ENT_QUOTES); ?> anlegen?');">
                                        <input type="hidden" name="action" value="retry_invoice">
                                        <input type="hidden" name="source_id" value="<?php echo htmlspecialchars($failed['source_id']); ?>">
                                        <button type="submit" class="btn btn-sm btn-primary">Erneut versuchen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between w-100">
                <h3 class="card-title mb-0">Verlauf</h3>
                <form method="get" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="shop" value="<?php echo htmlspecialchars($shop); ?>">
                    <label class="text-secondary mb-0">pro Seite</label>
                    <select name="page_size" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                        <?php foreach ($pageSizeOptions as $option): ?>
                            <option value="<?php echo $option; ?>" <?php echo $pageSize === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php if (count($logRows) === 0): ?>
                <div class="card-body text-secondary">
                    <?php echo $totalCount === 0 ? 'Noch keine Rechnung angelegt.' : 'Keine Einträge auf dieser Seite.'; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table table-striped">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Bestellung</th>
                                <th>Rechnungs-Nr.</th>
                                <th>Status</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logRows as $row): ?>
                                <?php [$orderNumber, $restMessage] = connect_invoice_split_order_number((string) $row['message']); ?>
                                <tr>
                                    <td class="text-secondary text-nowrap"><?php echo htmlspecialchars((string) $row['created_at']); ?></td>
                                    <td><?php echo $orderNumber !== null ? htmlspecialchars($orderNumber) : '<span class="text-secondary">?</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <?php if ($row['external_id'] !== null && $row['external_id'] !== ''): ?>
                                            <?php $shownNumber = connect_invoice_number((string) $row['response_body']); ?>
                                            <span title="ID im Kassensystem: <?php echo htmlspecialchars((string) $row['external_id']); ?>"><?php echo htmlspecialchars($shownNumber ?? (string) $row['external_id']); ?></span>
                                            <?php if ($pdfAvailable && $row['status'] === 'success'): ?>
                                                <a href="?shop=<?php echo urlencode($shop); ?><?php echo isset($_GET['preview']) ? '&amp;preview=1' : ''; ?>&amp;pdf=<?php echo urlencode((string) $row['external_id']); ?>" class="btn btn-sm btn-outline-secondary ms-2 py-0" title="Rechnung als PDF herunterladen">
                                                    <?php echo \Connect\Ui\Icon::svg('file-invoice', 14, 'me-1'); ?>PDF
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-secondary">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge <?php echo htmlspecialchars($statusBadge[$row['status']] ?? 'bg-secondary-lt'); ?>"><?php echo htmlspecialchars((string) $row['status']); ?></span></td>
                                    <td>
                                        <?php echo htmlspecialchars(mb_substr($restMessage, 0, 300)); ?>
                                        <?php if ($isAdmin && $row['sync_run_id'] !== null): ?>
                                            <a href="job_log.php?run_id=<?php echo (int) $row['sync_run_id']; ?>" class="text-secondary">→ Lauf #<?php echo (int) $row['sync_run_id']; ?></a>
                                        <?php endif; ?>
                                        <?php if ($row['request_body'] !== null || $row['response_body'] !== null): ?>
                                            <details class="mt-1">
                                                <summary class="text-secondary" style="cursor:pointer">Gesendet/Empfangen anzeigen</summary>
                                                <?php if ($row['request_body'] !== null): ?>
                                                    <div class="text-secondary mt-1">Gesendet:</div>
                                                    <pre class="bg-dark-lt p-2 mb-1" style="white-space:pre-wrap; max-height:300px; overflow:auto;"><?php echo htmlspecialchars(connect_invoice_pretty_body((string) $row['request_body'])); ?></pre>
                                                <?php endif; ?>
                                                <?php if ($row['response_body'] !== null): ?>
                                                    <div class="text-secondary mt-1">Empfangen:</div>
                                                    <pre class="bg-dark-lt p-2 mb-0" style="white-space:pre-wrap; max-height:300px; overflow:auto;"><?php echo htmlspecialchars(connect_invoice_pretty_body((string) $row['response_body'])); ?></pre>
                                                <?php endif; ?>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer d-flex align-items-center justify-content-between">
                    <span class="text-secondary"><?php echo $totalCount; ?> Einträge — Seite <?php echo $page; ?> von <?php echo $totalPages; ?></span>
                    <ul class="pagination mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?shop=<?php echo urlencode($shop); ?>&amp;page_size=<?php echo $pageSize; ?>&amp;page=<?php echo max(1, $page - 1); ?>">Zurück</a>
                        </li>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?shop=<?php echo urlencode($shop); ?>&amp;page_size=<?php echo $pageSize; ?>&amp;page=<?php echo min($totalPages, $page + 1); ?>">Weiter</a>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
