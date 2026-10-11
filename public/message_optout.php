<?php

declare(strict_types=1);

/**
 * connect/public/message_optout.php
 *
 * Abmeldung von Informations-Mails (Nachrichten der Art "info", Connect\Notify\MessageService).
 * Link mit Signatur je Shop aus der Mail, ohne Anmeldung. Abgemeldet wird erst per Knopf
 * (POST) -- manche Mail-Filter rufen Links vorab auf und wuerden sonst ungewollt abmelden.
 * Service-Mails (Wartung, Berechtigungen, Stoerungen) gehen weiterhin.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Connect\Config;
use Connect\Database;
use Connect\Notify\MessageService;

$config = Config::load();
$pdo = Database::connect($config)->pdo();
$service = new MessageService($pdo, $config);

header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$shop = (string) ($_GET['shop'] ?? $_POST['shop'] ?? '');
$sig = (string) ($_GET['sig'] ?? $_POST['sig'] ?? '');
$valid = $shop !== '' && preg_match('/^[a-zA-Z0-9\-]+$/', $shop) === 1 && hash_equals($service->optoutSignature($shop), $sig);
$done = false;

if ($valid && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $pdo->prepare('INSERT IGNORE INTO connect_message_optouts (shop) VALUES (:shop)')->execute(['shop' => $shop]);
    $done = true;
}
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$brand = \Connect\Setup\Branding::load($pdo, $config);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Abmelden · <?php echo $e($brand->shortName); ?></title>
    <?php echo $brand->headHtml(); ?>
    <style>
        body { margin: 0; background: #f3f5f8; font-family: <?php echo $brand->mailFontStack(); /* feste Liste aus Branding::FONTS, in <style> nicht escapen */ ?>; color: #1d2733; }
        .box { max-width: 480px; margin: 48px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #dfe4ea; border-radius: 12px; padding: 24px; line-height: 1.55; }
        .brand { font-weight: 700; color: <?php echo $brand->primary; ?>; margin-bottom: 12px; display: flex; align-items: center; }
        button { background: <?php echo $brand->primary; ?>; color: <?php echo $brand->primaryForeground(); ?>; border: 0; border-radius: 8px; padding: 10px 18px; font: inherit; font-weight: 600; cursor: pointer; }
        .muted { color: #5b6573; font-size: 14px; }
    </style>
</head>
<body>
<div class="box"><div class="card">
    <div class="brand"><?php echo $brand->logoHtml(32); ?></div>
    <?php if (!$valid): ?>
        <p>Dieser Abmelde-Link ist ungültig. Schreib uns bitte an office@oview.at, dann melden wir dich ab.</p>
    <?php elseif ($done): ?>
        <p><strong>Du bist abgemeldet.</strong> Der Shop <?php echo $e($shop); ?> bekommt keine Informations-Mails von <?php echo $e($brand->shortName); ?> mehr.</p>
        <p class="muted">Wichtige Mitteilungen zu deinem Konto (z. B. Wartungen, Störungen, nötige Berechtigungen) schicken wir weiterhin.</p>
    <?php else: ?>
        <p>Keine Informations-Mails mehr für den Shop <strong><?php echo $e($shop); ?></strong>?</p>
        <form method="post">
            <input type="hidden" name="shop" value="<?php echo $e($shop); ?>">
            <input type="hidden" name="sig" value="<?php echo $e($sig); ?>">
            <button type="submit">Abmelden</button>
        </form>
        <p class="muted">Wichtige Mitteilungen zu deinem Konto (z. B. Wartungen, Störungen, nötige Berechtigungen) schicken wir weiterhin.</p>
    <?php endif; ?>
</div></div>
</body>
</html>
