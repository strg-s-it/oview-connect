<?php

declare(strict_types=1);

/**
 * connect/bin/mail_test.php
 *
 * Schickt eine Test-Mail ueber denselben Weg wie die App (Notifier::send: PHP mail(), Absender
 * CONNECT_SYSTEM_MAIL_FROM bzw. no-reply@<Host von CONNECT_APP_URL>, Envelope-From -f). Zum Pruefen,
 * wie Postfaecher die Mails einstufen (Spam). Aendert nichts in der Datenbank.
 *
 * Aufruf (auf dem Server, mit der PHP-Version der App):
 *   php bin/mail_test.php <empfaenger> [stoerung|rechnung|kurz]
 *
 * Empfaenger z. B. die Adresse von mail-tester.com (Punktzahl + Details zu SPF/DKIM/DMARC/Inhalt),
 * ein eigenes Gmail-/Outlook-Postfach (Ordner "Spam" ansehen, "Original anzeigen" fuer die Header).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Nur per CLI ausfuehrbar.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

$to = trim((string) ($argv[1] ?? ''));
$kind = (string) ($argv[2] ?? 'stoerung');
if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Aufruf: php bin/mail_test.php <empfaenger> [stoerung|rechnung|kurz]\n");
    exit(1);
}

$config = \Connect\Config::load();
$pdo = \Connect\Database::connect($config)->pdo();
$notifier = new \Connect\Notify\Notifier($pdo, $config);
$shop = 'test-shop.myshopify.com';

$mails = [
    'stoerung' => [
        'oView: Verbindung zu helloCash gestört',
        "Hallo,\n\noView erreicht helloCash seit etwa 20 Minuten nicht mehr. "
        . "Solange das so ist, werden Bestände, Rechnungen und Produkte nicht abgeglichen.\n\n"
        . "Meldung: HTTP 401 - Invalid API key or access token\n\n"
        . "Meist ist der Zugangsschlüssel (API-Key bzw. Token) in helloCash abgelaufen oder wurde geändert. "
        . "Bitte prüfe ihn in oView unter „Status“ und trage bei Bedarf den neuen Schlüssel ein:\n"
        . $notifier->appUrl($shop) . "\n\n"
        . "Diese Meldungen kannst du in oView unter Einstellungen → Benachrichtigungen anpassen.\n",
    ],
    'rechnung' => [
        'oView: Rechnung für Bestellung #1001 wurde nicht erstellt',
        "Hallo,\n\nfür die Bestellung #1001 in deinem Shop {$shop} konnte oView keine Rechnung anlegen.\n\n"
        . "Grund: Test\n\nBitte prüfe die Bestellung. Details siehst du in oView unter „Rechnungen“:\n"
        . $notifier->appUrl($shop) . "\n",
    ],
    'kurz' => ['oView Testmail', "Dies ist eine Testmail von oView Connect.\n"],
];
if (!isset($mails[$kind])) {
    fwrite(STDERR, 'Unbekannte Art "' . $kind . '". Moeglich: ' . implode(', ', array_keys($mails)) . "\n");
    exit(1);
}

[$subject, $text] = $mails[$kind];
echo 'Absender:  ' . $notifier->systemFrom() . "\n";
echo "Empfaenger: {$to}\nArt:        {$kind}\n";
$sent = $notifier->send($to, $subject, $text);
echo $sent ? "mail() hat die Mail angenommen (heisst nur: an den lokalen Mailserver uebergeben).\n" : "mail() hat die Mail abgelehnt.\n";
exit($sent ? 0 : 1);
