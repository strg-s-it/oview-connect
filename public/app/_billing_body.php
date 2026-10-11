<?php

declare(strict_types=1);

/**
 * connect/public/app/_billing_body.php
 *
 * Seite "Abo": die drei Abos wie in der Preisliste (Connect\Billing\Plans), aktuelles Abo
 * aus Shopify (Connect\Billing\BillingService::refresh), Wechsel per Shopify-Bestaetigung.
 * Wechseln kann nur der Merchant selbst in Shopify (der Admin sieht die Seite nur lesend).
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

use Connect\Billing\BillingService;
use Connect\Billing\Plans;
use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Shopify\TokenRefresher;

$isAdmin = $isAdmin ?? false;
$pdo = $app->pdo;
$shop = $app->shop;
$canChange = !$isAdmin && !isset($_GET['preview']) && $app->host !== '';

$billing = new BillingService($pdo, new ConnectorFactory($pdo, $app->crypto, new HttpClient(), new TokenRefresher(
    new HttpClient(),
    $app->config->optional('CONNECT_SHOPIFY_API_KEY'),
    $app->config->optional('CONNECT_SHOPIFY_API_SECRET')
)), $app->config);

$flashMessage = null;
$flashType = 'info';
$confirmationUrl = null;

if ($canChange && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_plan') {
    try {
        $confirmationUrl = $billing->subscribe($shop, (string) ($_POST['plan'] ?? ''));
    } catch (\Throwable $e) {
        $flashMessage = 'Das Abo konnte nicht geändert werden: ' . $e->getMessage();
        $flashType = 'danger';
    }
}

$current = null;
$refreshError = null;
try {
    $current = $billing->refresh($shop);
} catch (\Throwable $e) {
    $refreshError = $e->getMessage();
}

$pageTitle = 'Abo' . ($isAdmin ? ' — ' . $shop : '');
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

<?php if ($confirmationUrl !== null): ?>
    <div class="alert alert-info">Du wirst zu Shopify weitergeleitet, um das Abo zu bestätigen …
        <a href="<?php echo htmlspecialchars($confirmationUrl); ?>" target="_top">Falls nichts passiert, hier klicken.</a></div>
    <script>
        (function () {
            var url = <?php echo json_encode($confirmationUrl); ?>;
            try {
                var bridge = window['app-bridge'];
                var host = <?php echo json_encode($app->host); ?>;
                if (window !== window.top && bridge && host) {
                    var shopifyApp = bridge.createApp({ apiKey: <?php echo json_encode($app->config->optional('CONNECT_SHOPIFY_API_KEY', '')); ?>, host: host });
                    bridge.actions.Redirect.create(shopifyApp).dispatch(bridge.actions.Redirect.Action.REMOTE, { url: url, newContext: false });
                    return;
                }
            } catch (e) { /* unten normal weiterleiten */ }
            window.top.location.href = url;
        })();
    </script>
<?php endif; ?>

<?php if ($refreshError !== null): ?>
    <div class="alert alert-warning">Dein aktuelles Abo konnte gerade nicht bei Shopify abgefragt werden. Bitte lade die Seite später neu.<?php echo $isAdmin ? ' (' . htmlspecialchars($refreshError) . ')' : ''; ?></div>
<?php elseif ($current !== null): ?>
    <p class="text-secondary">
        <?php if ($current['plan'] !== null): ?>
            Dein Abo: <strong><?php echo htmlspecialchars(Plans::PLANS[$current['plan']]['name']); ?></strong>
            <?php if ($current['trial_ends_at'] !== null): ?>
                · kostenloser Test bis <?php echo htmlspecialchars((new \DateTimeImmutable($current['trial_ends_at']))->format('d.m.Y')); ?>
            <?php endif; ?>
            <?php echo $current['test'] ? ' · Testabrechnung' : ''; ?>
        <?php elseif ($current['name'] !== null): ?>
            Dein Abo: <strong><?php echo htmlspecialchars((string) $current['name']); ?></strong> (Sondervereinbarung)
        <?php else: ?>
            Du hast noch kein Abo. Das erste Abo kannst du <?php echo Plans::TRIAL_DAYS; ?> Tage kostenlos testen.
        <?php endif; ?>
        Abgerechnet wird über deine Shopify-Rechnung.
    </p>
<?php endif; ?>

<div class="row row-cards">
    <?php foreach (Plans::PLANS as $key => $plan): ?>
        <?php $isCurrent = $current !== null && $current['plan'] === $key; ?>
        <div class="col-md-4">
            <div class="card h-100 <?php echo $isCurrent ? 'border-primary' : ''; ?>" <?php echo $isCurrent ? 'style="box-shadow: 0 0 0 2px var(--tblr-primary);"' : ''; ?>>
                <div class="card-body d-flex flex-column gap-3">
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <span class="text-uppercase text-secondary"><?php echo htmlspecialchars($plan['name']); ?></span>
                        <?php if ($isCurrent): ?><span class="badge bg-primary-lt">dein Abo</span><?php endif; ?>
                    </div>
                    <div><span class="h1 mb-0">$<?php echo htmlspecialchars($plan['price']); ?></span> <span class="text-secondary">/ Monat</span></div>
                    <ul class="list-unstyled mb-0 d-grid gap-1">
                        <?php foreach ($plan['features'] as $feature): ?>
                            <li>&#10003; <?php echo htmlspecialchars($feature); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="mt-auto">
                        <?php if ($isCurrent): ?>
                            <button class="btn w-100" disabled>Aktuelles Abo</button>
                        <?php elseif ($canChange): ?>
                            <form method="post" class="m-0">
                                <input type="hidden" name="action" value="change_plan">
                                <input type="hidden" name="plan" value="<?php echo htmlspecialchars($key); ?>">
                                <button type="submit" class="btn btn-primary w-100"><?php echo $current !== null && $current['plan'] === null && $current['name'] === null ? 'Auswählen' : 'Wechseln'; ?></button>
                            </form>
                        <?php else: ?>
                            <button class="btn w-100" disabled title="Nur der Merchant kann in Shopify wechseln">Wechseln (nur Merchant)</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<p class="text-secondary small mt-3">
    Das erste Abo kannst du <?php echo Plans::TRIAL_DAYS; ?> Tage kostenlos testen. Ein Wechsel gilt sofort und ohne neuen Testzeitraum.
    Die Abrechnung erfolgt monatlich über Shopify.
</p>

<?php require __DIR__ . '/_footer.php'; ?>
