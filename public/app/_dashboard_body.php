<?php

declare(strict_types=1);

/**
 * connect/public/app/_dashboard_body.php
 *
 * Startseite "Übersicht" (seit 2026-10-05, Connect\Setup\MerchantOverview): Ampel,
 * Probleme in Klartext mit Aktion, Kacheln Lagerbestand/Rechnungen/Produkte, Verlauf,
 * ungenutzte Funktionen. Technisches (Connections, Webhooks, Jobs) steht fuer den
 * Merchant auf der Status-Seite (_status_body.php). Der Admin sieht darunter
 * zusaetzlich die technischen Karten und den Einrichtungs-Assistenten.
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

$isAdmin = $isAdmin ?? false;

$pdo = $app->pdo;
$shop = $app->shop;

$connectionsStmt = $pdo->prepare(
    'SELECT c.status, COUNT(*) AS count
     FROM connect_connections c
     WHERE c.shop = :shop
     GROUP BY c.status'
);
$connectionsStmt->execute(['shop' => $shop]);
$statusCounts = ['active' => 0, 'error' => 0, 'pending' => 0];
foreach ($connectionsStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['count'];
}
$totalConnections = array_sum($statusCounts);

$syncMappingCountStmt = $pdo->prepare('SELECT COUNT(*) FROM connect_sync_mappings WHERE shop = :shop AND enabled = 1');
$syncMappingCountStmt->execute(['shop' => $shop]);
$activeSyncMappingCount = (int) $syncMappingCountStmt->fetchColumn();

$webhookCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM connect_webhook_subscriptions ws
     JOIN connect_connections c ON c.id = ws.connection_id
     WHERE c.shop = :shop'
);
$webhookCountStmt->execute(['shop' => $shop]);
$webhookCount = (int) $webhookCountStmt->fetchColumn();

// Einrichtungs-Assistent (Connect\Setup\SetupAssistant): "Passt so" hakt einen Schritt ab,
// ohne die Einstellung zu oeffnen (fuer Standardwerte, die schon stimmen).
$setupAssistant = new \Connect\Setup\SetupAssistant($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_setup_step') {
    $setupAssistant->confirm($shop, [(string) ($_POST['step'] ?? '')]);
    $flashMessage = 'Schritt abgehakt.';
    $flashType = 'success';
}
$setup = $setupAssistant->forShop($shop);

// Meldung aus der Einrichtung (app/welcome.php, "oView starten")
if (!$isAdmin && isset($_SESSION['connect_flash']) && is_array($_SESSION['connect_flash'])) {
    [$flashType, $flashMessage] = $_SESSION['connect_flash'];
    unset($_SESSION['connect_flash']);
}
$previewSuffix = isset($_GET['preview']) ? '&preview=1' : '';
$setupLink = static function (array $step) use ($shop, $previewSuffix): string {
    if ($step['key'] === 'target') {
        // ohne Zielsystem oeffnet die Einstellungsseite automatisch den Verbindungs-Assistenten
        $hasTarget = !str_starts_with($step['value'], 'nicht');
        return ($hasTarget ? 'connections.php' : 'sync_mappings.php') . '?shop=' . urlencode($shop) . $previewSuffix;
    }
    if ($step['key'] === 'plan') {
        return 'billing.php?shop=' . urlencode($shop) . $previewSuffix;
    }

    return 'sync_mappings.php?shop=' . urlencode($shop) . $previewSuffix . '#' . $step['anchor'];
};
$nextSetupStep = null;
foreach ($setup['steps'] as $setupStep) {
    if (!$setupStep['done'] && !$setupStep['optional']) {
        $nextSetupStep = $setupStep;
        break;
    }
}

$pageTitle = $isAdmin ? 'Dashboard — ' . $shop : 'Übersicht';

// Abo hoechstens alle 6 h bei Shopify abfragen (steuert u.a. die Haeufigkeit des Lagerabgleichs)
(new \Connect\Billing\BillingService($pdo, new \Connect\Connector\ConnectorFactory($pdo, $app->crypto, new \Connect\Http\HttpClient(), new \Connect\Shopify\TokenRefresher(
    new \Connect\Http\HttpClient(),
    $app->config->optional('CONNECT_SHOPIFY_API_KEY'),
    $app->config->optional('CONNECT_SHOPIFY_API_SECRET')
)), $app->config))->refreshIfStale($shop);

// Kein Abo bestaetigt? (Shopify meldet sicher kein aktives Abo -- unbekannt zaehlt nicht.) Nur ein
// Hinweis, keine Sperre: ohne Abo funktioniert alles weiter.
$noPlan = false;
try {
    $planStmt = $pdo->prepare('SELECT plan_status FROM connect_shops WHERE shop = :shop');
    $planStmt->execute(['shop' => $shop]);
    $noPlan = $planStmt->fetchColumn() === 'none';
} catch (\PDOException) {
    // Migration 0079 fehlt
}

// Merchant-Uebersicht (Connect\Setup\MerchantOverview): Ampel, Probleme, Kacheln, Verlauf
$overview = (new \Connect\Setup\MerchantOverview($pdo))->build($shop);
// Erstinitialisierung: jeder Daten-Job (Produkte, Artikel, Mitarbeiter ...) mind. einmal erfolgreich?
$initialLoad = \Connect\Sync\JobScheduler::initialLoad($pdo, $shop);

// Nachrichten vom oView-Team ("auch in der App", admin/messages.php): wegklicken = gelesen.
// Auch der Admin (Admin-Ansicht und Vorschau) kann sie erledigen -- dann sind sie auch beim
// Merchant weg (Eintrag im Aktivitaetsprotokoll).
$messageService = new \Connect\Notify\MessageService($pdo, $app->config);
$messageByAdmin = $isAdmin || $previewSuffix !== '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss_message') {
    $dismissId = (int) ($_POST['message_id'] ?? 0);
    $messageService->markRead($shop, $dismissId);
    if ($messageByAdmin) {
        \Connect\Sync\ActivityLog::log($pdo, $shop, null, null, 'message_dismissed', true, "In-App-Nachricht #{$dismissId} vom Admin als erledigt markiert.");
    }
}
$inAppMessages = $messageService->unreadInApp($shop);
$link = static function (string $target) use ($shop, $previewSuffix): string {
    [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);

    return $path . '?shop=' . urlencode($shop) . $previewSuffix . ($anchor !== null ? '#' . $anchor : '');
};

$navItems = require __DIR__ . '/_shop_nav.php';

if ($isAdmin) {
    $homeUrl = 'shops.php';
    $adminNavItems = require dirname(__DIR__) . '/admin/_nav.php';
    $adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
    $topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
        . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
}

require __DIR__ . '/_header.php';

$ov = $overview;
$problemCount = count($ov['problems']);
$agoText = static function (?int $minutes): string {
    return match (true) {
        $minutes === null => '',
        $minutes < 1 => 'gerade eben',
        $minutes < 60 => 'vor ' . $minutes . ' Minute' . ($minutes === 1 ? '' : 'n'),
        $minutes < 1440 => 'vor ' . intdiv($minutes, 60) . ' Stunde' . (intdiv($minutes, 60) === 1 ? '' : 'n'),
        default => 'vor ' . intdiv($minutes, 1440) . ' Tag' . (intdiv($minutes, 1440) === 1 ? '' : 'en'),
    };
};
?>

<style>
    .ov-banner { display: flex; gap: .9rem; align-items: center; padding: 1rem 1.15rem; border-radius: 12px; flex-wrap: wrap; }
    .ov-banner.ok { background: var(--tblr-green-lt, #e6f4ea); }
    .ov-banner.warn { background: var(--tblr-yellow-lt, #fdf3d8); }
    .ov-banner.wait { background: var(--tblr-primary-lt, #e9f0f9); }
    .ov-dot { width: 12px; height: 12px; border-radius: 50%; flex: none; }
    .ov-banner.ok .ov-dot { background: var(--tblr-green, #2fb344); }
    .ov-banner.warn .ov-dot { background: var(--tblr-yellow, #f59f00); }
    .ov-banner.wait .ov-dot { background: var(--tblr-primary, #206bc4); }
    .ov-big { font-size: 1.1rem; font-weight: 700; }
    /* Icons (Connect\Ui\Icon): Kachel-Symbol und Status-Symbol in den Hinweisen */
    .ov-ic { flex: none; width: 34px; height: 34px; border-radius: 9px; display: inline-grid; place-items: center; background: var(--tblr-primary-lt, #e9f0f9); color: var(--tblr-primary, #206bc4); }
    .ov-state { flex: none; display: inline-grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; background: rgba(255, 255, 255, .6); }
    .ov-banner.ok .ov-state { color: var(--tblr-green, #2fb344); }
    .ov-banner.warn .ov-state { color: var(--tblr-yellow, #f59f00); }
    .ov-banner.wait .ov-state { color: var(--tblr-primary, #206bc4); }
    .ov-kv { display: grid; gap: .45rem; }
    .ov-kv > div { display: flex; justify-content: space-between; gap: .6rem; }
    .ov-kv span:first-child { color: var(--tblr-secondary, #667382); }
    .ov-kv span:last-child { text-align: right; font-weight: 500; }
    .ov-mark { width: 8px; height: 8px; border-radius: 50%; margin-top: .45rem; flex: none; }
    .ov-mark.ok { background: var(--tblr-green, #2fb344); }
    .ov-mark.warn { background: var(--tblr-yellow, #f59f00); }
</style>

<div class="d-grid gap-3 mb-3">
    <?php foreach ($inAppMessages as $inAppMessage): ?>
        <div class="card border-primary">
            <div class="card-header">
                <h3 class="card-title">Nachricht vom oView-Team · <?php echo htmlspecialchars($inAppMessage['subject']); ?></h3>
                <div class="card-actions text-secondary small"><?php echo htmlspecialchars($inAppMessage['at']); ?></div>
            </div>
            <div class="card-body"><?php echo $inAppMessage['body_html']; ?></div>
            <div class="card-footer text-end">
                <form method="post" class="m-0"<?php echo $messageByAdmin ? ' onsubmit="return confirm(\'Nachricht als erledigt markieren? Sie wird dann auch beim Händler ausgeblendet.\')"' : ''; ?>>
                    <input type="hidden" name="action" value="dismiss_message">
                    <input type="hidden" name="message_id" value="<?php echo (int) $inAppMessage['id']; ?>">
                    <button type="submit" class="btn btn-sm"><?php echo $messageByAdmin ? 'Erledigt (auch beim Händler ausblenden)' : 'Gelesen, ausblenden'; ?></button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($noPlan): ?>
        <div class="ov-banner warn"><span class="ov-state"><?php echo \Connect\Ui\Icon::svg('alert-triangle', 22); ?></span><div class="flex-fill">
            <div class="ov-big">Dein Abo ist noch nicht bestätigt</div>
            <div class="text-secondary">Bitte wähle ein Abo und bestätige es in Shopify – das erste Abo kannst du <?php echo \Connect\Billing\Plans::TRIAL_DAYS; ?> Tage kostenlos testen. Falls die Bestätigungsseite nicht erscheint, erlaube Weiterleitungen bzw. Pop-ups für Shopify oder antworte auf unsere Mail.</div>
        </div><a href="<?php echo htmlspecialchars($link('billing.php')); ?>" class="btn btn-primary">Abo auswählen</a></div>
    <?php endif; ?>
    <?php if ($initialLoad['open'] !== []): ?>
        <?php
        // "HelloCash: Mitarbeiter" -> "Mitarbeiter (HelloCash)"
        $initialLabel = static function (string $name): string {
            $parts = explode(': ', $name, 2);

            return count($parts) === 2 ? $parts[1] . ' (' . $parts[0] . ')' : $name;
        };
        $initialFailed = array_filter($initialLoad['open'], static fn (array $o): bool => $o['failed']);
        ?>
        <div class="ov-banner wait"><span class="ov-state"><?php echo \Connect\Ui\Icon::svg('refresh', 22); ?></span><div class="flex-fill">
            <div class="ov-big">Die Erstinitialisierung ist noch nicht abgeschlossen</div>
            <div class="text-secondary">
                oView lädt gerade zum ersten Mal deine Daten aus Shopify und deinem Kassensystem
                (<?php echo (int) $initialLoad['done']; ?> von <?php echo (int) $initialLoad['total']; ?> Bereichen fertig).
                Bis dahin können Einstellungen wie Kassierer oder Zahlungsarten noch leer sein. Das dauert meist nur wenige Minuten – du musst nichts tun.
            </div>
            <div class="small text-secondary mt-1">
                Noch offen: <?php echo htmlspecialchars(implode(', ', array_map(static fn (array $o): string => $initialLabel($o['name']), $initialLoad['open']))); ?>.
                <?php if ($initialFailed !== []): ?>
                    <br><span class="text-warning">Bei <?php echo htmlspecialchars(implode(', ', array_map(static fn (array $o): string => $initialLabel($o['name']), $initialFailed))); ?> hat es noch nicht geklappt – oView versucht es automatisch erneut.</span>
                <?php endif; ?>
            </div>
        </div></div>
        <?php if (!$isAdmin): ?>
            <script>setTimeout(function () { window.location.reload(); }, 60000); // Fortschritt aktualisieren</script>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($problemCount > 0): ?>
        <div class="ov-banner warn"><span class="ov-state"><?php echo \Connect\Ui\Icon::svg('alert-triangle', 22); ?></span><div>
            <div class="ov-big"><?php echo $problemCount === 1 ? '1 Sache braucht' : $problemCount . ' Sachen brauchen'; ?> deine Aufmerksamkeit</div>
            <div class="text-secondary"><?php echo $ov['last_activity_ago'] !== null ? 'Alles andere läuft normal. Letzter Abgleich ' . htmlspecialchars($agoText($ov['last_activity_ago'])) . '.' : 'Alles andere läuft normal.'; ?></div>
        </div></div>
    <?php elseif ($initialLoad['open'] !== []): ?>
        <?php // Hinweis zur Erstinitialisierung steht schon oben -- kein "Alles läuft" daneben ?>
    <?php elseif ($ov['last_activity_ago'] === null): ?>
        <div class="ov-banner wait"><span class="ov-state"><?php echo \Connect\Ui\Icon::svg('refresh', 22); ?></span><div>
            <div class="ov-big">Der erste Abgleich läuft gerade</div>
            <div class="text-secondary">oView lädt deine Produkte und gleicht sie ab. Das dauert ein paar Minuten.</div>
        </div></div>
    <?php else: ?>
        <div class="ov-banner ok"><span class="ov-state"><?php echo \Connect\Ui\Icon::svg('circle-check', 22); ?></span><div>
            <div class="ov-big">Alles läuft</div>
            <div class="text-secondary">Letzter Abgleich <?php echo htmlspecialchars($agoText($ov['last_activity_ago'])); ?> · keine offenen Probleme</div>
        </div></div>
    <?php endif; ?>

    <?php if ($problemCount > 0): ?>
        <div class="card">
            <div class="card-header"><h3 class="card-title mb-0 d-flex align-items-center gap-2"><span class="ov-ic"><?php echo \Connect\Ui\Icon::svg('alert-triangle', 22); ?></span>Braucht deine Aufmerksamkeit</h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ($ov['problems'] as $problem): ?>
                    <div class="list-group-item d-flex gap-3 align-items-center flex-wrap">
                        <span class="ov-mark warn align-self-start"></span>
                        <div class="flex-fill" style="min-width: 14rem;">
                            <div class="fw-bold"><?php echo htmlspecialchars($problem['title']); ?></div>
                            <div class="text-secondary"><?php echo htmlspecialchars($problem['text']); ?></div>
                            <?php if (($problem['detail'] ?? '') !== ''): ?>
                                <details class="small mt-1"><summary class="text-secondary" style="cursor:pointer;">Genaue Meldung</summary><div class="text-secondary"><?php echo htmlspecialchars((string) $problem['detail']); ?></div></details>
                            <?php endif; ?>
                        </div>
                        <a href="<?php echo htmlspecialchars($link($problem['action'][1])); ?>" class="btn btn-sm btn-primary"><?php echo htmlspecialchars($problem['action'][0]); ?></a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row row-cards">
        <div class="col-md-4">
            <div class="card h-100"><div class="card-body d-flex flex-column gap-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0 d-flex align-items-center gap-2"><span class="ov-ic"><?php echo \Connect\Ui\Icon::svg('packages', 22); ?></span>Lagerbestand</h3>
                    <span class="badge <?php echo $ov['stock']['enabled'] ? 'bg-green-lt' : 'bg-secondary-lt'; ?>"><?php echo $ov['stock']['enabled'] ? 'aktiv' : 'aus'; ?></span>
                </div>
                <?php if ($ov['stock']['enabled']): ?>
                    <div class="ov-kv">
                        <div><span>Richtung</span><span><?php echo htmlspecialchars($ov['stock']['direction']); ?></span></div>
                        <div><span>Letzter Abgleich</span><span><?php echo $ov['stock']['last'] !== null ? htmlspecialchars($ov['stock']['last']['time']) . ($ov['stock']['last']['status'] === 'error' ? ' · Fehler' : ' · ' . $ov['stock']['last']['changed'] . ' angepasst') : 'noch keiner'; ?></span></div>
                        <div><span>Nächster Abgleich</span><span><?php echo htmlspecialchars((string) $ov['stock']['next']); ?></span></div>
                        <div><span>Heute geändert</span><span><?php echo (int) $ov['stock']['changed_today']; ?> Bestände</span></div>
                    </div>
                    <div class="mt-auto"><a href="<?php echo htmlspecialchars($link('stock.php')); ?>" class="btn btn-sm">Änderungen ansehen</a></div>
                <?php else: ?>
                    <div class="text-secondary">Bestände werden nicht abgeglichen.</div>
                    <div class="mt-auto"><a href="<?php echo htmlspecialchars($link('sync_mappings.php#setup-stock')); ?>" class="btn btn-sm btn-primary">Einschalten</a></div>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="col-md-4">
            <?php $inv = $ov['invoice']; ?>
            <div class="card h-100"><div class="card-body d-flex flex-column gap-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0 d-flex align-items-center gap-2"><span class="ov-ic"><?php echo \Connect\Ui\Icon::svg('file-invoice', 22); ?></span>Rechnungen</h3>
                    <?php if ($inv['mode'] === 'off'): ?>
                        <span class="badge bg-secondary-lt">aus</span>
                    <?php elseif ($inv['open_errors'] !== []): ?>
                        <span class="badge bg-yellow-lt"><?php echo count($inv['open_errors']); ?> offen</span>
                    <?php else: ?>
                        <span class="badge bg-green-lt"><?php echo $inv['mode'] === 'stockonly' ? 'nur Bestand' : 'aktiv'; ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($inv['mode'] === 'off'): ?>
                    <div class="text-secondary">Für bezahlte Bestellungen entsteht keine Rechnung.</div>
                    <div class="mt-auto"><a href="<?php echo htmlspecialchars($link('sync_mappings.php#setup-invoice')); ?>" class="btn btn-sm btn-primary">Einschalten</a></div>
                <?php else: ?>
                    <div class="ov-kv">
                        <div><span>Heute</span><span><?php echo (int) $inv['today']; ?> <?php echo $inv['mode'] === 'stockonly' ? 'Abbuchungen' : 'Rechnungen'; ?></span></div>
                        <div><span>Diesen Monat</span><span><?php echo (int) $inv['month']; ?></span></div>
                        <div><span>Letzte</span><span>
                            <?php if ($inv['last'] === null): ?>noch keine<?php else: ?>
                                <?php echo $inv['last']['order'] !== null ? '#' . htmlspecialchars(ltrim((string) $inv['last']['order'], '#')) : ''; ?>
                                <?php echo $inv['last']['number'] !== null ? ' → Rechnung ' . htmlspecialchars((string) $inv['last']['number']) : ''; ?>
                                · <?php echo htmlspecialchars((string) $inv['last']['time']); ?>
                            <?php endif; ?>
                        </span></div>
                    </div>
                    <div class="mt-auto"><a href="<?php echo htmlspecialchars($link('invoices.php')); ?>" class="btn btn-sm">Alle Rechnungen</a></div>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="col-md-4">
            <?php $prod = $ov['products']; ?>
            <div class="card h-100"><div class="card-body d-flex flex-column gap-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0 d-flex align-items-center gap-2"><span class="ov-ic"><?php echo \Connect\Ui\Icon::svg('box', 22); ?></span>Produkte</h3>
                    <?php if ($prod !== null): ?><span class="badge bg-green-lt"><?php echo (int) $prod['linked']; ?> verknüpft</span><?php endif; ?>
                </div>
                <?php if ($prod === null): ?>
                    <div class="text-secondary">Die Produkte werden gerade geladen.</div>
                <?php else: ?>
                    <div class="ov-kv">
                        <div><span>Nur in Shopify</span><span><?php echo (int) $prod['only_shopify']; ?></span></div>
                        <div><span>Nur in <?php echo htmlspecialchars($ov['target_name']); ?></span><span><?php echo (int) $prod['only_target']; ?></span></div>
                        <div><span>Erkannt an</span><span><?php echo htmlspecialchars($prod['role']); ?></span></div>
                        <?php
                        // Produkt-Check (Connect\Sync\ProductCheck, Migration 0090)
                        try {
                            $checkStmt = $pdo->prepare("SELECT score FROM connect_product_checks WHERE shop = :shop AND status = 'done'");
                            $checkStmt->execute(['shop' => $shop]);
                            $checkScore = $checkStmt->fetchColumn();
                        } catch (\PDOException) {
                            $checkScore = false;
                        }
                        ?>
                        <div><span>Produkt-Qualität</span><span><a href="<?php echo htmlspecialchars($link('product_check.php')); ?>"><?php echo $checkScore !== false && $checkScore !== null ? (int) $checkScore . ' %' : 'jetzt prüfen'; ?></a></span></div>
                    </div>
                    <div class="mt-auto"><a href="<?php echo htmlspecialchars($link('products.php')); ?>" class="btn btn-sm"><?php echo (int) $prod['only_shopify'] + (int) $prod['only_target'] > 0 ? 'Fehlende ansehen' : 'Produkte ansehen'; ?></a></div>
                <?php endif; ?>
            </div></div>
        </div>
    </div>

    <div class="row row-cards">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title mb-0 d-flex align-items-center gap-2"><span class="ov-ic"><?php echo \Connect\Ui\Icon::svg('history', 22); ?></span>Was zuletzt passiert ist</h3></div>
                <?php if ($ov['feed'] === []): ?>
                    <div class="card-body text-secondary">Noch nichts passiert. Sobald oView abgleicht oder eine Rechnung erstellt, siehst du es hier.</div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($ov['feed'] as $item): ?>
                            <div class="list-group-item d-flex gap-3">
                                <span class="text-secondary small" style="width: 3.2rem; flex: none;"><?php echo htmlspecialchars($item['time']); ?></span>
                                <span class="ov-mark <?php echo $item['tone']; ?>"></span>
                                <div style="min-width: 0;">
                                    <div><?php echo htmlspecialchars($item['title']); ?></div>
                                    <?php if ($item['text'] !== ''): ?><div class="text-secondary small"><?php echo htmlspecialchars($item['text']); ?></div><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title mb-0 d-flex align-items-center gap-2"><span class="ov-ic"><?php echo \Connect\Ui\Icon::svg('plug-connected', 22); ?></span>Noch nicht genutzt</h3></div>
                <div class="list-group list-group-flush">
                    <?php foreach ($ov['unused'] as $unusedEntry): ?>
                        <?php [$label, $target] = $unusedEntry; $upgradePlan = $unusedEntry[2] ?? null; ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <span>
                                <?php echo htmlspecialchars($label); ?>
                                <?php if ($upgradePlan !== null): ?>
                                    <span class="badge bg-purple-lt ms-1" title="In deinem aktuellen Abo nicht enthalten">ab <?php echo htmlspecialchars($upgradePlan); ?></span>
                                <?php endif; ?>
                            </span>
                            <?php if ($upgradePlan !== null): ?>
                                <a href="<?php echo htmlspecialchars($link($target)); ?>" class="btn btn-sm btn-outline-primary text-nowrap">Abo ansehen</a>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($link($target)); ?>" class="btn btn-sm text-nowrap">Einschalten</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($ov['unused'] === []): ?>
                        <div class="list-group-item text-secondary">Du nutzt schon alles, was oView für deinen Shop kann.</div>
                    <?php endif; ?>
                </div>
                <?php if (!$isAdmin && $previewSuffix === ''): ?>
                    <div class="card-footer d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <span class="text-secondary small">Etwas grundsätzlich ändern?</span>
                        <a href="welcome.php?shop=<?php echo urlencode($shop); ?>&amp;rerun=1" class="btn btn-sm btn-outline-primary"><?php echo \Connect\Ui\Icon::svg('player-play', 16, 'me-1'); ?>Einrichtungs-Assistent starten</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<h3 class="mt-4 mb-2 text-secondary">Technik (nur Admin)</h3>
<div class="row row-cards">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Connections</div>
                <div class="h1 mb-0"><?php echo $totalConnections; ?></div>
                <div class="text-secondary mt-2">
                    <span class="badge bg-green-lt"><?php echo $statusCounts['active']; ?> aktiv</span>
                    <span class="badge bg-red-lt"><?php echo $statusCounts['error']; ?> fehlerhaft</span>
                    <span class="badge bg-yellow-lt"><?php echo $statusCounts['pending']; ?> ungetestet</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Webhooks abonniert</div>
                <div class="h1 mb-0"><?php echo $webhookCount; ?></div>
                <div class="text-secondary mt-2">bei Shopify aktiv registriert</div>
            </div>
        </div>
    </div>
</div>

<?php if ($setup['steps'] !== []): ?>
    <?php $setupPercent = $setup['required'] > 0 ? (int) round($setup['required_done'] / $setup['required'] * 100) : 100; ?>
    <div class="row row-cards mt-1">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><?php echo $isAdmin ? 'Einrichtung des Merchants' : 'Einrichtung'; ?></h3>
                    <div class="card-actions text-secondary">
                        <?php echo $setup['required_done']; ?> von <?php echo $setup['required']; ?> Schritten erledigt
                    </div>
                </div>
                <div class="card-body pb-2">
                    <div class="progress progress-sm mb-3">
                        <div class="progress-bar <?php echo $setupPercent === 100 ? 'bg-green' : ''; ?>" style="width: <?php echo $setupPercent; ?>%" role="progressbar" aria-valuenow="<?php echo $setupPercent; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php echo $setupPercent; ?> % erledigt"></div>
                    </div>
                    <?php if ($nextSetupStep !== null): ?>
                        <div class="d-flex align-items-center gap-3 mb-1">
                            <div>Nächster Schritt: <strong><?php echo htmlspecialchars($nextSetupStep['title']); ?></strong></div>
                            <a href="<?php echo htmlspecialchars($setupLink($nextSetupStep)); ?>" class="btn btn-primary btn-sm">Weiter</a>
                        </div>
                    <?php else: ?>
                        <div class="text-success">Alles eingerichtet. Optionale Schritte kannst du jederzeit nachholen.</div>
                    <?php endif; ?>
                    <?php if (!$setup['available']): ?>
                        <div class="alert alert-warning mt-2 mb-0">Migration 0075 fehlt noch – Abhaken wird erst danach gespeichert.</div>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <tbody>
                            <?php foreach ($setup['steps'] as $step): ?>
                                <tr>
                                    <td class="w-1 text-center">
                                        <?php if ($step['done']): ?>
                                            <span class="badge bg-green-lt" title="erledigt">&#10003;</span>
                                        <?php elseif ($step['optional']): ?>
                                            <span class="text-secondary small fst-italic">opt.</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-lt"><?php echo (int) $step['number']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="<?php echo $step['done'] ? 'text-secondary' : ''; ?>"><?php echo htmlspecialchars($step['title']); ?></div>
                                        <small class="text-secondary"><?php echo htmlspecialchars($step['hint']); ?></small>
                                    </td>
                                    <td class="text-nowrap">
                                        <span class="badge <?php echo $step['done'] ? 'bg-green-lt' : 'bg-yellow-lt'; ?>"><?php echo htmlspecialchars($step['value']); ?></span>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?php if (!$step['done'] && $step['confirmable']): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="confirm_setup_step">
                                                <input type="hidden" name="step" value="<?php echo htmlspecialchars($step['key']); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success" title="Aktuellen Wert so übernehmen">Passt so</button>
                                            </form>
                                        <?php endif; ?>
                                        <a href="<?php echo htmlspecialchars($setupLink($step)); ?>" class="btn btn-sm btn-outline-primary">einstellen</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-secondary small">
                    <?php echo htmlspecialchars(implode(' · ', $setup['data'])); ?>.
                    Änderungen in Shopify oder im Zielsystem werden nicht sofort sichtbar – oView gleicht stündlich ab.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php endif; // Technik (nur Admin) ?>

<?php require __DIR__ . '/_footer.php'; ?>