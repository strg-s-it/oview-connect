<?php

declare(strict_types=1);

/**
 * connect/bootstrap.php
 *
 * Zentraler Einstiegspunkt fuer jede Seite unter connect/public/. Laedt
 * Autoload + Konfiguration, baut die DB-Verbindung auf und ermittelt den
 * Shop-Kontext -- liegt bewusst AUSSERHALB von public/, ist also ueber HTTP
 * nicht direkt aufrufbar (siehe Plan, Abschnitt "Portabilitaet").
 *
 * Verwendung in einer public/-Seite:
 *   $app = require dirname(__DIR__, 2) . '/bootstrap.php';
 *
 * Gibt ein Connect\AppContext-Objekt zurueck (siehe dort).
 */

require __DIR__ . '/vendor/autoload.php';

use Connect\AppContext;
use Connect\Config;
use Connect\Database;
use Connect\Security\Crypto;

$config = Config::load();
$db = Database::connect($config);

$crypto = new Crypto($config->require('CONNECT_ENCRYPTION_KEY'));

// Shop-Kontext + Vertrauen: entweder ein frischer, signierter Handoff aus der
// alten Welt (siehe connect_beta.php dort, der Button auf dem Login-Screen)
// oder eine bereits per Handoff etablierte Session. Vorher wurde hier blind
// $_GET['shop'] vertraut -- das war nur unkritisch, solange nichts oeffentlich
// darauf verlinkte. Jetzt tut der Button das, deshalb echte Pruefung noetig:
// ohne gueltige Signatur UND ohne passende Session wird abgelehnt.
// Eigener Session-Name, getrennt von der Admin-Session (AdminAuth nutzt
// "connect_admin"): beide Vertrauensmodelle (Shopify-Signatur hier, Passwort-
// Login dort) teilten sich sonst dasselbe Cookie auf derselben Domain.
// Name bewusst neu (vorher "connect_app"): Reste des alten Cookies -- auch eine kurzzeitig ausgelieferte
// partitionierte Variante -- erzeugten zwei gleichnamige Cookies, PHP las das falsche, und jedes Absenden
// endete mit 403. Unter neuem Namen werden sie ignoriert; Merchants melden sich wie immer ueber den Link an.
session_name('connect_sess');
ini_set('session.use_strict_mode', '1'); // unbekannte Session-IDs ablehnen statt uebernehmen (Fixation)
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'samesite' => 'None', // noetig, damit die Session-Cookie im Shopify-iframe (cross-site) funktioniert
    'secure' => true,
    'httponly' => true,
    'path' => '/',
]);
session_start();

$shop = $_GET['shop'] ?? null;
if (!is_string($shop) || $shop === '') {
    \Connect\Http\ErrorPage::show(
        400,
        'Shop nicht angegeben',
        'Diese Seite muss über die oView-App in Shopify geöffnet werden.'
    );
}

$sig = $_GET['sig'] ?? null;
$sigValid = false;
$expires = 0;
if ($sig !== null) {
    $expires = (int) ($_GET['expires'] ?? 0);
    $appSecret = $config->require('CONNECT_SHOPIFY_API_SECRET');
    // Eigener, aus dem App-Secret abgeleiteter Schluessel nur fuer den Handoff (gleiche Ableitung in
    // connect_beta.php dort): das App-Secret selbst signiert auch Webhooks und interne Aufrufe, eine
    // im Browser sichtbare Signatur darf damit nichts zu tun haben.
    $handoffKey = hash_hmac('sha256', 'oview-connect-handoff-v1', $appSecret);
    $expected = hash_hmac('sha256', $shop . '|' . $expires, $handoffKey);

    $sigValid = $expires >= time()
        && preg_match('/^[a-z0-9][a-z0-9-]*$/', $shop) === 1
        && hash_equals($expected, (string) $sig);
}

// Diagnose fuer Anmelde-Ablehnungen (401/403): eine Zeile im PHP-Error-Log, ohne Token/Signatur-Werte.
// Zeigt, ob das Session-Cookie ankam (und wie oft), ob ein Zugangslink in der URL stand und ob er galt.
$authLog = static function (string $reason) use (&$sig, &$sigValid, &$expires, $shop): void {
    $rawCookies = (string) ($_SERVER['HTTP_COOKIE'] ?? '');
    @error_log('connect auth: ' . $reason . ' shop=' . $shop
        . ' script=' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''))
        . ' method=' . (string) ($_SERVER['REQUEST_METHOD'] ?? '')
        . ' sig=' . ($sig === null ? 'nein' : ($sigValid ? 'gueltig' : ($expires < time() ? 'abgelaufen' : 'ungueltig')))
        . ' cookie_session=' . substr_count($rawCookies, session_name() . '=')
        . ' session_shop=' . (isset($_SESSION['connect_shop']) ? ((string) $_SESSION['connect_shop'] === $shop ? 'passt' : 'anderer') : 'leer')
        . ' dest=' . (string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '-')
        . ' site=' . (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '-'));
};

if ($sigValid) {
    session_regenerate_id(true); // neue Session-ID bei frisch verifiziertem Handoff -- schuetzt vor Session-Fixation
    $_SESSION['connect_shop'] = $shop;
    $_SESSION['connect_host'] = (string) ($_GET['host'] ?? '');
} elseif (($_SESSION['connect_shop'] ?? null) !== $shop) {
    // Ein abgelaufener Zugangslink in der URL ist unschaedlich, solange die Session des Shops noch gilt
    // (die Einrichtungsseiten tragen den Link in der URL, das Formular schickt dorthin -- nach 5 Minuten
    // waere sonst jede Eingabe ein 401). Nur ohne passende Session gilt der Link als Grund.
    $authLog($sig !== null ? '401 link-abgelaufen-ohne-session' : '401 nicht-angemeldet');
    if ($sig !== null) {
        \Connect\Http\ErrorPage::show(
            401,
            'Zugangslink abgelaufen',
            'Der Link ist ungültig oder nicht mehr gültig. Bitte öffne die App erneut über Shopify.'
        );
    }
    \Connect\Http\ErrorPage::show(
        401,
        'Nicht angemeldet',
        'Deine Sitzung ist abgelaufen oder gehört zu einem anderen Shop. Bitte öffne die App erneut über Shopify.'
    );
}

// CSRF-Schutz: Das Session-Cookie ist wegen des Shopify-iframes SameSite=None,
// wird also auch bei Anfragen von fremden Seiten mitgeschickt -- ohne Token
// koennte jede fremde Seite POSTs im Namen des Merchants ausloesen. Das Token
// steckt in der Session und wird per Script aus public/app/_footer.php in jedes
// POST-Formular eingetragen. Laeuft bewusst NACH der Authentifizierung oben.
$_SESSION['connect_csrf'] ??= bin2hex(random_bytes(32));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfSent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($csrfSent) || $csrfSent === '' || !hash_equals((string) $_SESSION['connect_csrf'], $csrfSent)) {
        $authLog('403 csrf ' . (is_string($csrfSent) && $csrfSent !== '' ? 'token-passt-nicht' : 'token-fehlt'));
        \Connect\Http\ErrorPage::show(
            403,
            'Anfrage abgelehnt',
            'Aus Sicherheitsgründen wurde die Anfrage nicht ausgeführt (Sicherheitstoken fehlt oder ist abgelaufen). '
            . 'Bitte lade die Seite neu und versuche es noch einmal.',
            ['label' => 'Seite neu laden', 'url' => \Connect\Http\ErrorPage::selfUrl()]
        );
    }
}

// Der Handoff-Link (sig) steht in der URL -- ohne Referrer-Policy wuerde er bei
// jedem Aufruf einer externen Ressource/eines Links mitgesendet. Die Seiten
// enthalten Kundendaten, daher zusaetzlich kein MIME-Sniffing.
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

// Erlaubt das Einbetten in Shopify Admin (wie die alte Welt in index.php) --
// ohne diesen Header wuerde der Browser die Seite im iframe blockieren.
header('Content-Security-Policy: frame-ancestors https://' . preg_replace('/[^a-zA-Z0-9\-]/', '', $shop) . '.myshopify.com https://admin.shopify.com;');

// Erstes Fenster (app/welcome.php): Software waehlen und Rechtstexten zustimmen, bevor
// irgendeine andere Merchant-Seite erreichbar ist (Connect\Setup\WelcomeGate).
$scriptPath = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
if (basename(dirname($scriptPath)) === 'app' && basename($scriptPath) !== 'welcome.php'
    && \Connect\Setup\WelcomeGate::isRequired($db->pdo(), $shop)
) {
    // Zugangslink (sig/expires/host) mitgeben: der Folgeaufruf bleibt so auch dann angemeldet,
    // wenn das Session-Cookie der Weiterleitung nicht ankommt. Verwendet wird er nur, wenn er
    // gerade frisch verifiziert wurde (sonst ist $sig null und es gilt die Session).
    $gateUrl = 'welcome.php?shop=' . urlencode($shop);
    if ($sigValid) {
        $gateUrl .= '&host=' . urlencode((string) ($_GET['host'] ?? ''))
            . '&expires=' . $expires
            . '&sig=' . urlencode((string) $sig);
    }
    header('Location: ' . $gateUrl);
    exit;
}

return new AppContext($db->pdo(), $crypto, $shop, $config, (string) ($_SESSION['connect_host'] ?? ''));
