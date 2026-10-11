<?php

declare(strict_types=1);

/**
 * connect/public/admin/cleanup.php
 *
 * Umstieg -> Aufraeumen: Shops der alten Welt, die es bei Shopify nicht mehr gibt (geschlossen,
 * geloescht) oder bei denen die App weg ist, deren app/uninstalled-Webhook aber nie ankam.
 * Grundlage ist die Live-Pruefung aus admin/transition.php ("Berechtigungen jetzt pruefen",
 * connect_legacy_scope_checks).
 *
 * Shopify-Antworten auf /admin/oauth/access_scopes.json:
 *   404  Shop existiert nicht mehr                 -> entfernen
 *   401  Token ungueltig, App deinstalliert         -> entfernen
 *   403  Zugriff verweigert ("Unavailable Shop")    -> meist deaktiviert/gekuendigt, auswaehlbar,
 *                                                     aber nicht vorausgewaehlt
 *   402  Shop eingefroren (Shopify-Rechnung offen)  -> nur beobachten, kann zurueckkommen
 *   423  Shop gesperrt                              -> nur beobachten
 *   kein Token in oview_stores                     -> entfernen (nichts mehr nutzbar)
 *
 * Entfernen = derselbe Weg wie bei einer echten Deinstallation: Connect schickt einen mit dem
 * App-Secret signierten Webhook "shop/redact" an webhooks/shop_data_erasure.php der alten Welt.
 * Die loescht alle Daten des Shops dort und reicht an Connect (privacy.php) weiter. Vorher wird
 * jeder Shop noch einmal live geprueft -- nur wenn er dann immer noch 404/401/ohne Token ist.
 *
 * Zweite Sicherheit: letzte Spuren in der alten Welt (LegacyBridge::lastActivity -- Bestellungen,
 * Rechnungen, Lagerabgleiche, erfolgreiche Shopify-Aufrufe, App geoeffnet). Gab es innerhalb von
 * ACTIVE_DAYS Tagen noch etwas, ist der Shop nicht auswaehlbar und wird nicht entfernt.
 */

const ACTIVE_DAYS = 30;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Http\HttpClient;
use Connect\Legacy\LegacyBridge;
use Connect\Notify\MerchantDirectory;
use Connect\Sync\ActivityLog;

$config = Config::load();
$pdo = Database::connect($config)->pdo();
$auth = new AdminAuth($pdo);
$auth->requireLogin();
$adminEmail = (string) $auth->currentEmail();
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);

/** Einordnung je Shop aus dem Ergebnis der Live-Pruefung. @return array{0: string, 1: string, 2: string} [Schluessel, Badge-Klasse, Text] */
$classify = static function (?string $error): array {
    if ($error === null) {
        return ['ok', 'bg-green-lt', 'erreichbar'];
    }
    if (str_contains($error, 'kein Shopify-Token')) {
        return ['remove', 'bg-red-lt', 'kein Token mehr in der alten Welt'];
    }
    if (preg_match('/HTTP (\d{3})/', $error, $m) === 1) {
        return match ($m[1]) {
            '404' => ['remove', 'bg-red-lt', 'Shop existiert nicht mehr (geschlossen/gelöscht)'],
            '401' => ['remove', 'bg-red-lt', 'App deinstalliert (Token ungültig)'],
            '403' => ['review', 'bg-orange-lt', 'Zugriff verweigert – meist Shop deaktiviert/gekündigt, bitte prüfen'],
            '402' => ['watch', 'bg-yellow-lt', 'Shop eingefroren (Shopify-Rechnung offen)'],
            '423' => ['watch', 'bg-yellow-lt', 'Shop gesperrt'],
            default => ['watch', 'bg-yellow-lt', 'Shopify HTTP ' . $m[1]],
        };
    }

    return ['watch', 'bg-yellow-lt', $error];
};

$secret = $config->optional('CONNECT_SHOPIFY_API_SECRET', '');
$erasureUrl = $config->optional('LEGACY_SHOP_ERASURE_URL', 'https://oview.at/webhooks/shop_data_erasure.php');

/** Juengste Spur. @param array<string, string> $sources @return array{0: string, 1: string}|null [Quelle, Zeitpunkt] */
$latestActivity = static function (array $sources): ?array {
    if ($sources === []) {
        return null;
    }
    arsort($sources);

    return [(string) array_key_first($sources), (string) reset($sources)];
};

$flashMessage = null;
$flashType = 'info';
$directory = new MerchantDirectory($pdo, $config);
$merchants = $directory->all();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove') {
    $selected = array_values(array_unique(array_map('strval', (array) ($_POST['shops'] ?? []))));
    if (empty($_POST['confirm'])) {
        $flashMessage = 'Bitte bestätigen (Häkchen), dass die Shops endgültig entfernt werden sollen.';
        $flashType = 'warning';
    } elseif ($selected === []) {
        $flashMessage = 'Keine Shops ausgewählt.';
        $flashType = 'warning';
    } elseif ($secret === '') {
        $flashMessage = 'CONNECT_SHOPIFY_API_SECRET fehlt in connect/.env – ohne Signatur nimmt die alte Welt den Auftrag nicht an.';
        $flashType = 'danger';
    } else {
        @set_time_limit(90);
        $legacyOnly = [];
        foreach ($merchants as $m) {
            if ($m['world'] === 'legacy') {
                $legacyOnly[$m['shop']] = true;
            }
        }
        $http = new HttpClient();
        $removed = [];
        $skipped = [];
        $started = time();
        foreach ($selected as $shop) {
            if (time() - $started > 45) {
                $skipped[] = $shop . ': Zeitlimit, bitte noch einmal';
                continue;
            }
            if (!isset($legacyOnly[$shop]) || preg_match('/^[a-z0-9][a-z0-9\-]*$/i', $shop) !== 1) {
                $skipped[] = $shop . ': nicht (nur) in der alten Welt';
                continue;
            }
            // Unmittelbar vorher noch einmal pruefen: nur wenn Shopify es weiterhin bestaetigt
            [$kind] = $classify($directory->checkLegacyScopes($shop, $http));
            if ($kind !== 'remove' && $kind !== 'review') {
                $skipped[] = $shop . ': jetzt wieder erreichbar bzw. nicht eindeutig – nicht entfernt';
                continue;
            }
            // Zweite Sicherheit: noch Spuren in den letzten ACTIVE_DAYS Tagen?
            try {
                $recentActivity = $latestActivity(LegacyBridge::lastActivity($config, [$shop])[$shop] ?? []);
            } catch (\Throwable $ex) {
                $skipped[] = $shop . ': letzte Aktivität nicht prüfbar (' . $ex->getMessage() . ') – nicht entfernt';
                continue;
            }
            if ($recentActivity !== null && strtotime($recentActivity[1]) > time() - ACTIVE_DAYS * 86400) {
                $skipped[] = $shop . ': noch aktiv (' . $recentActivity[0] . ' am ' . substr($recentActivity[1], 0, 10) . ') – nicht entfernt';
                continue;
            }
            $domain = $shop . '.myshopify.com';
            $body = (string) json_encode(['shop_id' => 0, 'shop_domain' => $domain]);
            try {
                $response = $http->request('POST', $erasureUrl, [
                    'Content-Type' => 'application/json',
                    'X-Shopify-Topic' => 'shop/redact',
                    'X-Shopify-Shop-Domain' => $domain,
                    'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
                ], $body);
            } catch (\Throwable $ex) {
                $skipped[] = $shop . ': ' . $ex->getMessage();
                continue;
            }
            if ($response->isSuccess()) {
                $removed[] = $shop;
                $pdo->prepare('DELETE FROM connect_legacy_scope_checks WHERE shop = :shop')->execute(['shop' => $shop]);
                ActivityLog::log($pdo, $shop, 'shopify', null, 'cleanup_removed', true, "Shop aus der alten Welt entfernt (Shopify nicht mehr erreichbar), ausgelöst von {$adminEmail}.");
            } else {
                $skipped[] = $shop . ': alte Welt antwortet HTTP ' . $response->statusCode . ($response->statusCode === 401 ? ' (Signatur abgelehnt – stimmt CONNECT_SHOPIFY_API_SECRET mit der App überein?)' : '');
            }
        }
        $flashMessage = count($removed) . ' Shop(s) entfernt' . ($removed !== [] ? ': ' . implode(', ', $removed) : '') . '.'
            . ($skipped !== [] ? ' Nicht entfernt: ' . implode(' · ', $skipped) : '');
        $flashType = $skipped === [] ? 'success' : 'warning';
        $merchants = $directory->all();
    }
}

$candidates = [];
$counts = ['remove' => 0, 'review' => 0, 'watch' => 0];
foreach ($merchants as $m) {
    if ($m['world'] !== 'legacy' || $m['scopes_error'] === null) {
        continue;
    }
    [$kind, $badge, $text] = $classify($m['scopes_error']);
    if ($kind === 'ok') {
        continue;
    }
    $counts[$kind]++;
    $candidates[] = $m + ['kind' => $kind, 'badge' => $badge, 'reason' => $text];
}

$activity = [];
$activityError = null;
try {
    $activity = LegacyBridge::lastActivity($config, array_column($candidates, 'shop'));
} catch (\Throwable $ex) {
    $activityError = $ex->getMessage();
}
foreach ($candidates as $i => $c) {
    $latest = $latestActivity($activity[$c['shop']] ?? []);
    $candidates[$i]['activity'] = $activity[$c['shop']] ?? [];
    $candidates[$i]['latest'] = $latest;
    $candidates[$i]['active'] = $activityError !== null || ($latest !== null && strtotime($latest[1]) > time() - ACTIVE_DAYS * 86400);
}
$order = ['remove' => 0, 'review' => 1, 'watch' => 2];
usort($candidates, static fn (array $a, array $b): int => [$order[$a['kind']], $a['shop']] <=> [$order[$b['kind']], $b['shop']]);

$pageTitle = 'Aufräumen';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$breadcrumbs = [['transition.php', 'Umstieg'], [null, 'Aufräumen']];
$topRightHtml = '<span class="text-secondary me-3">' . $e($adminEmail) . '</span><a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
require dirname(__DIR__) . '/app/_header.php';
?>

<div class="alert alert-info">
    Shops der alten Welt, die bei der letzten Live-Prüfung nicht erreichbar waren. Grundlage ist
    <a href="transition.php">Umstieg → „Berechtigungen jetzt prüfen“</a> – vorher dort prüfen, sonst fehlen Shops hier.
    <strong>Rot</strong> = entfernen empfohlen (Shop geschlossen, App deinstalliert oder kein Token mehr – der Deinstallations-Webhook kam nie an).
    <strong>Orange</strong> = Shopify verweigert den Zugriff (403) – meist deaktiviert oder gekündigt; auswählbar, aber bitte vorher kurz prüfen (z. B. Shop-URL im Browser öffnen).
    <strong>Gelb</strong> = nur beobachten: eingefrorene Shops (Shopify-Rechnung offen, 402) oder gesperrte können zurückkommen.
</div>

<?php if ($activityError !== null): ?>
    <div class="alert alert-warning">Letzte Aktivität in der alten Welt nicht lesbar (<?php echo $e($activityError); ?>) – zur Sicherheit ist nichts auswählbar.</div>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="action" value="remove">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?php echo (int) $counts['remove']; ?> zum Entfernen · <?php echo (int) $counts['review']; ?> prüfen · <?php echo (int) $counts['watch']; ?> beobachten</h3>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th class="w-1"><input type="checkbox" class="form-check-input" aria-label="alle roten auswählen" onchange="document.querySelectorAll('.cl-remove').forEach(function (c) { c.checked = this.checked; }, this)"></th><th>Shop</th><th>Befund</th><th>Letzte Aktivität (alte Welt)</th><th>Abo (alte Welt)</th><th>Installiert</th><th>Geprüft</th></tr></thead>
                <tbody>
                    <?php foreach ($candidates as $m): ?>
                        <tr>
                            <td><?php if (($m['kind'] === 'remove' || $m['kind'] === 'review') && !$m['active']): ?><input type="checkbox" class="form-check-input<?php echo $m['kind'] === 'remove' ? ' cl-remove' : ''; ?>" name="shops[]" value="<?php echo $e($m['shop']); ?>" aria-label="<?php echo $e($m['shop']); ?> entfernen"><?php endif; ?></td>
                            <td><?php echo $e($m['shop']); ?><div class="small text-secondary"><?php echo $e($m['email'] ?? 'keine Mail-Adresse'); ?></div></td>
                            <td><span class="badge <?php echo $e($m['badge']); ?>"><?php echo $e($m['reason']); ?></span></td>
                            <td class="small">
                                <?php if ($m['latest'] === null): ?>
                                    <span class="text-secondary">keine Spuren</span>
                                <?php else: ?>
                                    <span class="<?php echo $m['active'] ? 'text-danger fw-bold' : ''; ?>" title="<?php echo $e(implode("\n", array_map(static fn (string $k, string $v): string => $k . ': ' . $v, array_keys($m['activity']), $m['activity']))); ?>">
                                        <?php echo $e(substr($m['latest'][1], 0, 10)); ?>
                                    </span>
                                    <div class="text-secondary"><?php echo $e($m['latest'][0]); ?><?php echo $m['active'] ? ' – noch aktiv, nicht entfernen' : ''; ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?php echo $e($m['plan_label']); ?></td>
                            <td class="small text-secondary"><?php echo $e($m['installed'] ?? '–'); ?></td>
                            <td class="small text-secondary"><?php echo $e(substr((string) $m['scopes_checked_at'], 0, 16)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($candidates === []): ?>
                        <tr><td colspan="7" class="text-secondary">Keine auffälligen Shops – oder noch nicht geprüft (Umstieg → „Berechtigungen jetzt prüfen“).</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-body border-top d-grid gap-2">
            <div class="small text-secondary">
                Entfernen löscht in der alten Welt alle Daten des Shops (Einstellungen, Bindung, Produkte, Rechnungsprotokolle, Jobs) – genau wie eine echte Deinstallation mit anschließender Datenlöschung (<code>shop/redact</code>).
                Jeder Shop wird unmittelbar vorher noch einmal bei Shopify geprüft und nur entfernt, wenn er dann immer noch nicht erreichbar ist
                und es in den letzten <?php echo ACTIVE_DAYS; ?> Tagen keine Spuren in der alten Welt gab (Bestellungen, Rechnungen, Lagerabgleiche, erfolgreiche Shopify-Aufrufe, App geöffnet – Details beim Darüberfahren über das Datum). Nicht rückgängig zu machen; installiert der Händler die App neu, beginnt er von vorne.
            </div>
            <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="confirm" value="1"> <span class="form-check-label">Die ausgewählten Shops endgültig aus der alten Welt entfernen</span></label>
            <div><button type="submit" class="btn btn-danger" <?php echo $counts['remove'] + $counts['review'] === 0 ? 'disabled' : ''; ?>>Ausgewählte entfernen</button></div>
        </div>
    </div>
</form>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
