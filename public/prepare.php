<?php

declare(strict_types=1);

/**
 * connect/public/prepare.php
 *
 * "Umstieg vorbereiten" fuer Merchants der alten Welt -- persoenlicher, signierter Link aus
 * der Nachricht (Platzhalter {vorbereitung_link}, Connect\Notify\MessageService::prepareUrl),
 * ohne Anmeldung, PREPARE_LINK_DAYS Tage gueltig. Zwei Dinge:
 *   1. Shopify-Berechtigungen bestaetigen (Link wie {berechtigungen_link})
 *   2. helloCash-Token neu generieren und eintragen. helloCash hat nur EINEN Token je Konto,
 *      "Neu generieren" macht den alten sofort ungueltig -- deshalb setzt
 *      Connect\Setup\TargetAccessCheck::prepare() einen gueltigen Token sofort in der alten
 *      Welt (hellocash_binding.token), in Connect und fuer den Umzug (Migration 0084) ein und
 *      prueft den v2-Zugriff je Object. Der Admin bekommt eine Mail (dringend, wenn die alte
 *      Welt nicht aktualisiert werden konnte).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Connect\Config;
use Connect\Connector\ConnectorFactory;
use Connect\Database;
use Connect\Http\HttpClient;
use Connect\Notify\MerchantDirectory;
use Connect\Notify\MessageService;
use Connect\Notify\Notifier;
use Connect\Security\Crypto;
use Connect\Setup\Branding;
use Connect\Setup\TargetAccessCheck;
use Connect\Shopify\ScopeCheck;

/**
 * Wo der Merchant den Token in helloCash erstellt. Bei Aenderungen in helloCash hier anpassen.
 */
const HELLOCASH_TOKEN_STEPS = [
    'Halte diese Seite offen und melde dich in einem zweiten Tab bei helloCash an (myhellocash.com). Öffne im Menü <strong>Registrierkasse → Einstellungen → „helloCash API“</strong>.',
    'Achte darauf, dass für den Token <strong>„Alle Scopes“</strong> (Lesen und Schreiben) gilt – vor allem Artikel, Rechnungen, Kunden, Mitarbeiter, Zahlungsarten und Firma.',
    'Klicke auf <strong>„Neu generieren“</strong>. Wichtig: Dein bisheriger Token funktioniert ab diesem Moment nicht mehr.',
    'Kopiere den neuen Token <strong>sofort</strong> und füge ihn unten ein. Wir setzen ihn gleich überall ein, damit Lagerabgleich und Rechnungen ohne Unterbrechung weiterlaufen.',
];

$config = Config::load();
$pdo = Database::connect($config)->pdo();
$crypto = new Crypto($config->require('CONNECT_ENCRYPTION_KEY'));
$service = new MessageService($pdo, $config);
$brand = Branding::load($pdo, $config);

header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$shop = (string) ($_GET['shop'] ?? $_POST['shop'] ?? '');
$expires = (int) ($_GET['exp'] ?? $_POST['exp'] ?? 0);
$sig = (string) ($_GET['sig'] ?? $_POST['sig'] ?? '');
$validFormat = $shop !== '' && preg_match('/^[a-zA-Z0-9\-]+$/', $shop) === 1;
$signatureOk = $validFormat && hash_equals($service->prepareSignature($shop, $expires), $sig);
$expired = $signatureOk && $expires < time();
$valid = $signatureOk && !$expired;

$result = null;
if ($valid && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = trim((string) ($_POST['token'] ?? ''));
    if ($token === '' || strlen($token) > 2000 || preg_match('/\s/', $token) === 1) {
        $result = ['ok' => false, 'missing' => [], 'optional_missing' => [], 'objects' => [], 'error' => 'Bitte den Token ohne Leerzeichen einfügen.', 'valid' => false, 'v1_ok' => null, 'legacy' => 'skipped', 'applied' => false, 'throttled' => false];
    } else {
        try {
            $check = new TargetAccessCheck($pdo, $crypto, new ConnectorFactory($pdo, $crypto, new HttpClient()), $config, new HttpClient());
            $result = $check->prepare($shop, $token);
        } catch (\Throwable $ex) {
            error_log('[prepare] ' . $shop . ': ' . $ex);
            $result = ['ok' => false, 'missing' => [], 'optional_missing' => [], 'objects' => [], 'error' => 'Die Prüfung ist gerade nicht möglich. Bitte sofort an office@oview.at schreiben, falls du den Token schon neu generiert hast.', 'valid' => false, 'v1_ok' => null, 'legacy' => 'skipped', 'applied' => false, 'throttled' => false];
        }
        if ($result['valid']) {
            $legacyOk = $result['legacy'] === 'updated';
            $notifier = new Notifier($pdo, $config);
            $notifier->send(
                $notifier->adminEmail(),
                ($legacyOk ? '' : 'DRINGEND: ') . "Neuer helloCash-Token – {$shop}",
                "Der Merchant {$shop} hat über „Umstieg vorbereiten“ einen neu generierten helloCash-Token eingetragen.\n\n"
                . 'Alte Welt (hellocash_binding.token): ' . ($legacyOk ? 'aktualisiert' : ($result['legacy'] === 'not_found' ? 'Shop NICHT gefunden' : 'NICHT aktualisiert – ' . $result['legacy']))
                . ($legacyOk ? '' : "\n→ Der alte Token ist ungültig, die alte Welt steht für diesen Shop! Token bitte manuell in hellocash_binding.token eintragen.")
                . "\nAPI v1 (alte Welt) mit neuem Token: " . ($result['v1_ok'] === true ? 'funktioniert' : ($result['v1_ok'] === false ? 'FUNKTIONIERT NICHT' : 'nicht prüfbar'))
                . "\nAPI v2 (Connect): " . ($result['ok'] ? 'Zugriff vollständig' : 'es fehlen Berechtigungen für ' . implode(', ', $result['missing']))
                . "\nConnect-Connection: " . ($result['applied'] ? 'aktualisiert' : 'noch keine – wird beim Umzug (Schritt 2) verwendet')
            );
        }
    }
}

$merchant = null;
$scopesMissing = null;
$authorizeUrl = '';
if ($valid) {
    try {
        foreach ((new MerchantDirectory($pdo, $config))->all() as $m) {
            if ($m['shop'] === $shop) {
                $merchant = $m;
            }
        }
    } catch (\Throwable) {
        $merchant = null;
    }
    $scopesMissing = $merchant['scopes_missing'] ?? null;
    $authorizeUrl = (new ScopeCheck($pdo, $crypto, $config))->authorizeUrl($shop);
}
$prepared = $valid ? TargetAccessCheck::preparedRow($pdo, $shop) : null;
$tokenDone = $prepared !== null && (int) $prepared['access_ok'] === 1;

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Umstieg vorbereiten · <?php echo $e($brand->shortName); ?></title>
    <?php echo $brand->headHtml(); ?>
    <style>
        body { margin: 0; background: #f3f5f8; font-family: <?php echo $brand->mailFontStack(); /* feste Liste, nicht escapen */ ?>; color: #1d2733; line-height: 1.55; }
        .box { max-width: 640px; margin: 40px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #dfe4ea; border-top: 4px solid <?php echo $brand->primary; ?>; border-radius: 12px; padding: 24px; margin-bottom: 16px; }
        .brand { font-weight: 700; color: <?php echo $brand->primary; ?>; margin-bottom: 16px; display: flex; align-items: center; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        h2 { font-size: 17px; margin: 0 0 8px; display: flex; align-items: center; gap: 8px; }
        .num { display: inline-grid; place-items: center; width: 26px; height: 26px; border-radius: 50%; background: <?php echo $brand->primary; ?>; color: <?php echo $brand->primaryForeground(); ?>; font-size: 14px; flex: none; }
        .num.done { background: #2f8a4e; color: #fff; }
        .btn { display: inline-block; background: <?php echo $brand->primary; ?>; color: <?php echo $brand->primaryForeground(); ?>; border: 0; border-radius: 8px; padding: 10px 18px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        .muted { color: #5b6573; font-size: 14px; }
        .ok { background: #e4f4ea; color: #2f6b45; border-radius: 8px; padding: 10px 12px; margin: 12px 0 0; }
        .bad { background: #fde8e8; color: #8a2a2a; border-radius: 8px; padding: 10px 12px; margin: 12px 0 0; }
        ol { padding-left: 20px; margin: 8px 0 12px; }
        li { margin-bottom: 4px; }
        input[type=text] { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #c9d1db; border-radius: 8px; font: 14px ui-monospace, Consolas, monospace; margin: 4px 0 12px; }
    </style>
</head>
<body>
<div class="box">
    <div class="card">
        <div class="brand"><?php echo $brand->logoHtml(32); ?></div>
        <?php if (!$signatureOk): ?>
            <p>Dieser Link ist ungültig. Bitte verwende den Link aus unserer Mail oder schreib uns an office@oview.at.</p>
        <?php elseif ($expired): ?>
            <p>Dieser Link ist abgelaufen. Schreib uns bitte an office@oview.at, dann schicken wir dir einen neuen.</p>
        <?php else: ?>
            <h1>Umstieg vorbereiten</h1>
            <p>Wir stellen <?php echo $e($brand->shortName); ?> für deinen Shop <strong><?php echo $e($shop); ?></strong> auf eine neue, schnellere Technik um. Dafür brauchen wir zwei Dinge von dir – das dauert etwa fünf Minuten. Bis zur Umstellung läuft alles wie gewohnt weiter.</p>
        <?php endif; ?>
    </div>

    <?php if ($valid): ?>
        <div class="card">
            <h2><span class="num<?php echo $scopesMissing === [] ? ' done' : ''; ?>"><?php echo $scopesMissing === [] ? '✓' : '1'; ?></span> Berechtigungen in Shopify bestätigen</h2>
            <?php if ($scopesMissing === []): ?>
                <p class="muted">Erledigt – <?php echo $e($brand->shortName); ?> hat alle Berechtigungen, die es braucht.</p>
            <?php else: ?>
                <p>Melde dich in deinem Shopify-Admin an und klicke auf den Button. Shopify zeigt dir, welche Berechtigungen <?php echo $e($brand->shortName); ?> bekommt – bitte bestätigen.</p>
                <a class="btn" href="<?php echo $e($authorizeUrl); ?>" target="_blank" rel="noopener">Berechtigungen in Shopify bestätigen</a>
                <p class="muted" style="margin-top:10px">Danach kannst du hierher zurückkommen. Die Anzeige aktualisiert sich, sobald du <?php echo $e($brand->shortName); ?> das nächste Mal in Shopify öffnest.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2><span class="num<?php echo $tokenDone ? ' done' : ''; ?>"><?php echo $tokenDone ? '✓' : '2'; ?></span> helloCash-Token neu generieren</h2>
            <?php if ($result !== null && $result['ok']): ?>
                <div class="ok"><strong>Danke, der neue Token funktioniert und ist überall eingesetzt.</strong> <?php echo $e($brand->shortName); ?> kann damit alles lesen, was es braucht. Du musst nichts weiter tun – wir melden uns, sobald dein Shop umgestellt ist.</div>
            <?php else: ?>
                <?php if ($tokenDone): ?>
                    <p class="muted">Erledigt am <?php echo $e(date('d.m.Y', strtotime((string) $prepared['submitted_at']) ?: time())); ?>. Nur wenn du den Token in helloCash noch einmal neu generierst, musst du den neuen hier wieder eintragen.</p>
                <?php else: ?>
                    <p>helloCash vergibt Berechtigungen inzwischen je Token. Dein bisheriger Token hat nicht alle, die die neue Technik braucht. So geht es:</p>
                <?php endif; ?>
                <ol>
                    <?php foreach (HELLOCASH_TOKEN_STEPS as $stepHtml): ?>
                        <li><?php echo $stepHtml; ?></li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($result !== null && $result['valid']): ?>
                    <div class="bad">
                        <strong>Der neue Token ist eingesetzt – Lagerabgleich und Rechnungen laufen weiter.</strong>
                        Ihm fehlen aber noch Berechtigungen für: <?php echo $e(implode(', ', $result['missing'])); ?>.
                        Bitte in helloCash die fehlenden Berechtigungen aktivieren, den Token noch einmal neu generieren und hier eintragen.
                    </div>
                <?php elseif ($result !== null): ?>
                    <div class="bad"><?php echo $e((string) ($result['error'] ?? 'Der Token funktioniert nicht.')); ?></div>
                <?php endif; ?>
                <form method="post" autocomplete="off" style="margin-top:12px">
                    <input type="hidden" name="shop" value="<?php echo $e($shop); ?>">
                    <input type="hidden" name="exp" value="<?php echo (int) $expires; ?>">
                    <input type="hidden" name="sig" value="<?php echo $e($sig); ?>">
                    <label for="token" class="muted">helloCash-API-Token</label>
                    <input id="token" type="text" name="token" required spellcheck="false" autocapitalize="off">
                    <button type="submit" class="btn">Prüfen und speichern</button>
                </form>
                <p class="muted" style="margin-top:12px">Wir prüfen den Token sofort, nur lesend – dabei wird in helloCash nichts verändert. Gespeichert wird er verschlüsselt und nur, wenn helloCash ihn annimmt.</p>
            <?php endif; ?>
        </div>

        <p class="muted" style="text-align:center">Fragen? Antworte einfach auf unsere Mail oder schreib an office@oview.at.</p>
    <?php endif; ?>
</div>
</body>
</html>
