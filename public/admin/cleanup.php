<?php

declare(strict_types=1);

/**
 * connect/public/admin/cleanup.php
 *
 * Umstieg -> Aufraeumen: Shops der alten Welt ohne aktives Konto -- es gibt sie bei Shopify nicht
 * mehr (geschlossen, geloescht), die App ist weg (app/uninstalled kam nie an), es gibt kein
 * aktives Abo, oder es steht nur noch eine Zeile in hellocash_binding (oview_stores schon weg).
 * Grundlage ist die Live-Pruefung aus admin/transition.php ("Berechtigungen jetzt pruefen",
 * connect_legacy_scope_checks, mit aktivem Abo ab Migration 0098).
 *
 * Shopify-Antworten auf /admin/oauth/access_scopes.json:
 *   404  Shop existiert nicht mehr                 -> entfernen
 *   401  Token ungueltig, App deinstalliert         -> entfernen
 *   403  Zugriff verweigert ("Unavailable Shop")    -> meist deaktiviert/gekuendigt, auswaehlbar,
 *                                                     aber nicht vorausgewaehlt
 *   402  Shop eingefroren (Shopify-Rechnung offen)  -> nur beobachten, kann zurueckkommen
 *   423  Shop gesperrt                              -> nur beobachten
 *   kein Token in oview_stores                     -> entfernen (nichts mehr nutzbar)
 *   erreichbar, aber kein aktives Abo              -> auswaehlbar, nicht vorausgewaehlt
 *   nur in hellocash_binding                       -> entfernen (kein Konto, kein Token)
 *
 * Entfernen = derselbe Weg wie bei einer echten Deinstallation: Connect schickt einen mit dem
 * App-Secret signierten Webhook "shop/redact" an webhooks/shop_data_erasure.php der alten Welt.
 * Die loescht alle Daten des Shops dort (oview_stores, hellocash_binding, Produkte, Protokolle, ...)
 * und reicht an Connect (privacy.php) weiter. Vorher wird jeder Shop noch einmal live geprueft --
 * nur wenn er dann immer noch nicht erreichbar bzw. ohne Abo ist.
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

/**
 * Einordnung je Shop aus dem Ergebnis der Live-Pruefung.
 *
 * @param string|null $subscription aktives Abo laut Shopify, '' = keines, null = unbekannt
 * @param bool|null $chargeActive Abo laut oview_stores (null = keine Zeile)
 * @return array{0: string, 1: string, 2: string} [Schluessel, Badge-Klasse, Text]
 */
$classify = static function (bool $checked, ?string $error, ?string $subscription, ?bool $chargeActive, bool $bindingOnly): array {
    if ($bindingOnly) {
        return ['remove', 'bg-red-lt', 'kein Konto mehr – nur noch helloCash-Bindung übrig'];
    }
    if (!$checked) {
        return $chargeActive === false
            ? ['review', 'bg-orange-lt', 'kein aktives Abo laut alter Welt – noch nicht live geprüft']
            : ['unchecked', 'bg-secondary-lt', 'noch nicht live geprüft'];
    }
    if ($error === null) {
        if ($subscription === '') {
            return ['review', 'bg-orange-lt', 'App installiert, aber kein aktives Abo bei Shopify'];
        }
        if ($subscription === null && $chargeActive === false) {
            return ['review', 'bg-orange-lt', 'kein aktives Abo laut alter Welt (Shopify-Abo nicht prüfbar)'];
        }

        return ['ok', 'bg-green-lt', 'erreichbar, Abo aktiv'];
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
                $legacyOnly[$m['shop']] = $m;
            }
        }
        $bindingOnly = array_fill_keys($directory->bindingOnlyShops(), true);
        $http = new HttpClient();
        $removed = [];
        $skipped = [];
        $started = time();
        foreach ($selected as $shop) {
            if (time() - $started > 45) {
                $skipped[] = $shop . ': Zeitlimit, bitte noch einmal';
                continue;
            }
            if ((!isset($legacyOnly[$shop]) && !isset($bindingOnly[$shop])) || preg_match('/^[a-z0-9][a-z0-9\-]*$/i', $shop) !== 1) {
                $skipped[] = $shop . ': nicht (nur) in der alten Welt';
                continue;
            }
            // Unmittelbar vorher noch einmal pruefen: nur wenn Shopify es weiterhin bestaetigt
            // (Shops nur in hellocash_binding haben keinen Token -- dort reicht der frische Stand)
            if (isset($bindingOnly[$shop])) {
                $kind = 'remove';
            } else {
                $live = $directory->checkLegacyShop($shop, $http);
                [$kind] = $classify(true, $live['error'], $live['subscription'], $legacyOnly[$shop]['charge_active'], false);
            }
            if ($kind !== 'remove' && $kind !== 'review') {
                $skipped[] = $shop . ': jetzt wieder erreichbar mit aktivem Abo bzw. nicht eindeutig – nicht entfernt';
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
                try {
                    $pdo->prepare('DELETE FROM connect_legacy_hellocash_checks WHERE shop = :shop')->execute(['shop' => $shop]);
                } catch (\PDOException) {
                    // Migration 0094 fehlt
                }
                ActivityLog::log($pdo, $shop, 'shopify', null, 'cleanup_removed', true, 'Shop aus der alten Welt entfernt (' . (isset($bindingOnly[$shop]) ? 'nur noch helloCash-Bindung' : 'kein aktives Konto') . "), ausgelöst von {$adminEmail}.");
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

// Shops in Connect ohne Abo (plan_status = none: Shopify meldet sicher kein aktives Abo).
// Betrifft nur Connect-Daten, die alte Welt bleibt unberuehrt. Laeuft ein Shop live in Connect,
// ist er nicht auswaehlbar (dort entstehen echte Rechnungen) -- erst zurueck zur alten Welt stellen.
$planColumn = true;
$noPlanShops = [];
try {
    $liveColumn = $pdo->query("SHOW COLUMNS FROM connect_shops LIKE 'live_in_connect'")->fetch() !== false;
    $loadNoPlan = static fn (): array => $pdo->query(
        "SELECT s.shop, s.first_seen_at, s.last_seen_at, " . ($liveColumn ? 's.live_in_connect' : '0 AS live_in_connect') . ",
                (SELECT COUNT(*) FROM connect_connections c WHERE c.shop = s.shop) AS connection_count
         FROM connect_shops s WHERE s.plan_status = 'none' ORDER BY s.first_seen_at"
    )->fetchAll();
    $noPlanShops = $loadNoPlan();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_no_plan') {
        $pickedNoPlan = array_values(array_unique(array_map('strval', (array) ($_POST['no_plan_shops'] ?? []))));
        if (empty($_POST['confirm_no_plan'])) {
            $noPlanFlash = ['warning', 'Bitte bestätigen (Häkchen), dass die Shops endgültig aus Connect entfernt werden sollen.'];
        } elseif ($pickedNoPlan === []) {
            $noPlanFlash = ['warning', 'Keine Shops ausgewählt.'];
        } else {
            $allowed = [];
            foreach ($noPlanShops as $row) {
                $allowed[(string) $row['shop']] = (int) $row['live_in_connect'] === 0;
            }
            $removedNoPlan = [];
            $skippedNoPlan = [];
            foreach ($pickedNoPlan as $shop) {
                if (!isset($allowed[$shop])) {
                    $skippedNoPlan[] = $shop . ': hat (inzwischen) ein Abo oder ist unbekannt';
                } elseif (!$allowed[$shop]) {
                    $skippedNoPlan[] = $shop . ': läuft live in Connect';
                } else {
                    \Connect\Setup\ShopRemover::remove($pdo, $shop);
                    ActivityLog::log($pdo, $shop, 'shopify', null, 'cleanup_removed', true, "Shop ohne Abo aus Connect entfernt, ausgelöst von {$adminEmail}.");
                    $removedNoPlan[] = $shop;
                }
            }
            $noPlanFlash = [
                $skippedNoPlan === [] ? 'success' : 'warning',
                count($removedNoPlan) . ' Shop(s) ohne Abo aus Connect entfernt' . ($removedNoPlan !== [] ? ': ' . implode(', ', $removedNoPlan) : '') . '.'
                    . ($skippedNoPlan !== [] ? ' Nicht entfernt: ' . implode(' · ', $skippedNoPlan) : ''),
            ];
            $noPlanShops = $loadNoPlan();
        }
        [$flashType, $flashMessage] = $noPlanFlash;
    }
} catch (\PDOException) {
    $planColumn = false; // Migration 0079 fehlt
}

$candidates = [];
$counts =['remove' => 0, 'review' => 0, 'watch' => 0, 'ok' => 0, 'unchecked' => 0];
foreach ($merchants as $m) {
    if ($m['world'] !== 'legacy') {
        continue;
    }
    [$kind, $badge, $text] = $classify($m['scopes_checked_at'] !== null, $m['scopes_error'], $m['subscription'], $m['charge_active'], false);
    $counts[$kind]++;
    if ($kind === 'ok' || $kind === 'unchecked') {
        continue;
    }
    $candidates[] = $m + ['kind' => $kind, 'badge' => $badge, 'reason' => $text, 'has_store' => true];
}
foreach ($directory->bindingOnlyShops() as $shop) {
    [$kind, $badge, $text] = $classify(false, null, null, null, true);
    $counts[$kind]++;
    $candidates[] = [
        'shop' => $shop, 'email' => null, 'plan_label' => '–', 'installed' => null, 'scopes_checked_at' => null,
        'subscription' => null, 'charge_active' => null, 'has_binding' => true, 'has_store' => false,
        'kind' => $kind, 'badge' => $badge, 'reason' => $text,
    ];
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
    Alle Shops der alten Welt ohne aktives Konto – aus beiden Tabellen: <code>oview_stores</code> (Shop-Konto, Shopify-Token, Abo) und
    <code>hellocash_binding</code> (helloCash-Anbindung). Grundlage ist die Live-Prüfung unter
    <a href="transition.php">Umstieg → „Berechtigungen jetzt prüfen“</a> (prüft Erreichbarkeit und aktives Abo bei Shopify) – vorher dort prüfen, sonst bleiben Shops „ungeprüft“.
    <strong>Rot</strong> = entfernen empfohlen (Shop geschlossen, App deinstalliert, kein Token mehr oder nur noch die helloCash-Bindung übrig – der Deinstallations-Webhook kam nie an).
    <strong>Orange</strong> = kein aktives Abo, oder Shopify verweigert den Zugriff (403, meist deaktiviert/gekündigt); auswählbar, aber bitte vorher kurz prüfen.
    <strong>Gelb</strong> = nur beobachten: eingefrorene Shops (Shopify-Rechnung offen, 402) oder gesperrte können zurückkommen.
</div>

<?php if ($activityError !== null): ?>
    <div class="alert alert-warning">Letzte Aktivität in der alten Welt nicht lesbar (<?php echo $e($activityError); ?>) – zur Sicherheit ist nichts auswählbar.</div>
<?php endif; ?>
<?php if ($directory->legacyError() !== null): ?>
    <div class="alert alert-warning"><?php echo $e($directory->legacyError()); ?></div>
<?php elseif (!$directory->legacyBindingsReadable()): ?>
    <div class="alert alert-warning"><code>hellocash_binding</code> der alten Welt nicht lesbar – Shops, die nur noch dort stehen, fehlen in der Liste.</div>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="action" value="remove">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?php echo (int) $counts['remove']; ?> zum Entfernen · <?php echo (int) $counts['review']; ?> prüfen · <?php echo (int) $counts['watch']; ?> beobachten</h3>
            <div class="card-actions small text-secondary">
                <?php echo (int) $counts['ok']; ?> mit aktivem Konto<?php echo $counts['unchecked'] > 0 ? ' · ' . (int) $counts['unchecked'] . ' mit Abo laut alter Welt, noch nicht live geprüft' : ''; ?>
            </div>
        </div>
        <div class="card-body border-bottom py-2 d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-sm" onclick="clSelect('.cl-remove')">Alle roten auswählen</button>
            <button type="button" class="btn btn-sm" onclick="clSelect('.cl-pick')">Alle auswählbaren auswählen</button>
            <button type="button" class="btn btn-sm" onclick="clSelect(null)">Keine auswählen</button>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th class="w-1"></th><th>Shop</th><th>Befund</th><th>Daten in der alten Welt</th><th>Letzte Aktivität (alte Welt)</th><th>Abo (alte Welt / Shopify)</th><th>Installiert</th><th>Geprüft</th></tr></thead>
                <tbody>
                    <?php foreach ($candidates as $m): ?>
                        <tr>
                            <td><?php if (($m['kind'] === 'remove' || $m['kind'] === 'review') && !$m['active']): ?><input type="checkbox" class="form-check-input cl-pick<?php echo $m['kind'] === 'remove' ? ' cl-remove' : ''; ?>" name="shops[]" value="<?php echo $e($m['shop']); ?>" aria-label="<?php echo $e($m['shop']); ?> entfernen"><?php endif; ?></td>
                            <td>
                                <?php if (preg_match('/^[a-z0-9][a-z0-9\-]*$/i', (string) $m['shop']) === 1): ?>
                                    <a href="https://<?php echo $e($m['shop']); ?>.myshopify.com" target="_blank" rel="noopener noreferrer" title="Shop im neuen Tab öffnen (Weiterleitung auf den Shop = existiert noch)"><?php echo $e($m['shop']); ?> ↗</a>
                                <?php else: ?>
                                    <?php echo $e($m['shop']); ?>
                                <?php endif; ?>
                                <div class="small text-secondary"><?php echo $e($m['email'] ?? 'keine Mail-Adresse'); ?></div></td>
                            <td><span class="badge <?php echo $e($m['badge']); ?>"><?php echo $e($m['reason']); ?></span></td>
                            <td class="small">
                                <div><?php echo $m['has_store'] ? 'Shop-Konto' : '<span class="text-danger">kein Shop-Konto</span>'; ?></div>
                                <div><?php echo $m['has_binding'] === null ? '<span class="text-secondary">Bindung unbekannt</span>' : ($m['has_binding'] ? 'helloCash-Bindung' : '<span class="text-secondary">keine helloCash-Bindung</span>'); ?></div>
                            </td>
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
                            <td class="small">
                                <div><?php echo $e($m['plan_label']); ?></div>
                                <div class="text-secondary">Shopify: <?php echo $m['subscription'] === null ? 'unbekannt' : ($m['subscription'] === '' ? '<span class="text-danger">keines</span>' : $e($m['subscription'])); ?></div>
                            </td>
                            <td class="small text-secondary"><?php echo $e($m['installed'] ?? '–'); ?></td>
                            <td class="small text-secondary"><?php echo $m['scopes_checked_at'] !== null ? $e(substr((string) $m['scopes_checked_at'], 0, 16)) : 'nicht geprüft'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($candidates === []): ?>
                        <tr><td colspan="8" class="text-secondary">Keine auffälligen Shops – oder noch nicht geprüft (Umstieg → „Berechtigungen jetzt prüfen“).</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-body border-top d-grid gap-2">
            <div class="small text-secondary">
                Entfernen löscht in der alten Welt alle Daten des Shops (Shop-Konto, helloCash-Bindung, Einstellungen, Produkte, Rechnungsprotokolle, Jobs) – genau wie eine echte Deinstallation mit anschließender Datenlöschung (<code>shop/redact</code>).
                Jeder Shop wird unmittelbar vorher noch einmal bei Shopify geprüft und nur entfernt, wenn er dann immer noch nicht erreichbar ist bzw. kein aktives Abo hat
                (Shops nur mit helloCash-Bindung haben keinen Token mehr – dort zählt der aktuelle Stand der Tabellen),
                und es in den letzten <?php echo ACTIVE_DAYS; ?> Tagen keine Spuren in der alten Welt gab (Bestellungen, Rechnungen, Lagerabgleiche, erfolgreiche Shopify-Aufrufe, App geöffnet – Details beim Darüberfahren über das Datum). Nicht rückgängig zu machen; installiert der Händler die App neu, beginnt er von vorne.
            </div>
            <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="confirm" value="1"> <span class="form-check-label">Die ausgewählten Shops endgültig aus der alten Welt entfernen</span></label>
            <div><button type="submit" class="btn btn-danger" <?php echo $counts['remove'] + $counts['review'] === 0 ? 'disabled' : ''; ?>>Ausgewählte entfernen</button></div>
        </div>
    </div>
</form>

<?php if ($planColumn): ?>
<form method="post" class="mt-3">
    <input type="hidden" name="action" value="remove_no_plan">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Shops in Connect ohne Abo (<?php echo count($noPlanShops); ?>)</h3>
            <div class="card-actions">
                <button type="button" class="btn btn-sm" onclick="document.querySelectorAll('.np-pick').forEach(function (b) { b.checked = true; })">Alle auswählen</button>
                <button type="button" class="btn btn-sm" onclick="document.querySelectorAll('.np-pick').forEach(function (b) { b.checked = false; })">Keine auswählen</button>
            </div>
        </div>
        <div class="card-body border-bottom small text-secondary">
            Shopify meldet für diese Shops kein aktives Abo. Sie haben in Connect nichts verloren. Entfernen löscht nur die Connect-Daten
            (Connections, Aktivierungen, Webhooks, Protokolle) – die alte Welt bleibt unberührt. Shops, die live in Connect laufen, sind nicht auswählbar
            (erst unter Shops zurück zur alten Welt stellen). Installiert der Händler die App erneut, beginnt er von vorne.
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th class="w-1"></th><th>Shop</th><th>Connections</th><th>Zuständig</th><th>Dabei seit</th><th>Zuletzt gesehen</th></tr></thead>
                <tbody>
                    <?php foreach ($noPlanShops as $row): ?>
                        <?php $isLiveNoPlan = (int) $row['live_in_connect'] === 1; ?>
                        <tr>
                            <td><?php if (!$isLiveNoPlan): ?><input type="checkbox" class="form-check-input np-pick" name="no_plan_shops[]" value="<?php echo $e($row['shop']); ?>" aria-label="<?php echo $e($row['shop']); ?> entfernen"><?php endif; ?></td>
                            <td><?php echo $e($row['shop']); ?></td>
                            <td><?php echo (int) $row['connection_count']; ?></td>
                            <td><span class="badge <?php echo $isLiveNoPlan ? 'bg-green-lt' : 'bg-secondary-lt'; ?>"><?php echo $isLiveNoPlan ? 'Connect (live)' : 'Alte Welt'; ?></span></td>
                            <td class="small text-secondary"><?php echo $e(substr((string) $row['first_seen_at'], 0, 10)); ?> (<?php echo (int) floor((time() - (int) strtotime((string) $row['first_seen_at'])) / 86400); ?> Tage)</td>
                            <td class="small text-secondary"><?php echo $e(substr((string) $row['last_seen_at'], 0, 16)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($noPlanShops === []): ?>
                        <tr><td colspan="6" class="text-secondary">Alle Shops in Connect haben ein Abo (oder wurden noch nicht abgefragt).</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-body border-top d-grid gap-2">
            <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="confirm_no_plan" value="1"> <span class="form-check-label">Die ausgewählten Shops endgültig aus Connect entfernen</span></label>
            <div><button type="submit" class="btn btn-danger" <?php echo $noPlanShops === [] ? 'disabled' : ''; ?>>Ausgewählte entfernen</button></div>
        </div>
    </div>
</form>
<?php endif; ?>
<script>
    // Auswahl setzen: selector = welche Haekchen an, null = alle aus
    function clSelect(selector) {
        document.querySelectorAll('.cl-pick').forEach(function (box) {
            box.checked = selector !== null && box.matches(selector);
        });
    }
</script>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
