<?php

declare(strict_types=1);

/**
 * connect/bin/worker_check.php
 *
 * Prueft eine Connect-Installation (Hauptserver oder Worker-Abo), bevor dort der
 * Scheduler eingeplant wird: PHP-Version, .env, Worker-Name, Datenbank,
 * Migrationen und -- vor allem -- mit welcher oeffentlichen IP ausgehende Aufrufe
 * tatsaechlich hinausgehen (CONNECT_OUTBOUND_IP). Aendert nichts.
 *
 * Aufruf (als Systembenutzer des Abos, mit derselben PHP-Version wie spaeter der Scheduler):
 *   /opt/plesk/php/8.x/bin/php /var/www/vhosts/<abo>/connect/bin/worker_check.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Nur per CLI ausfuehrbar.');
}

$ok = true;
$line = static function (bool $pass, string $label, string $detail = '') use (&$ok): void {
    $ok = $ok && $pass;
    echo ($pass ? '  [OK]     ' : '  [FEHLER] ') . $label . ($detail !== '' ? ': ' . $detail : '') . "\n";
};

echo "Connect Worker-Check\n";
$line(PHP_VERSION_ID >= 80100, 'PHP-Version', PHP_VERSION . (PHP_VERSION_ID >= 80100 ? '' : ' -- Connect braucht mindestens 8.1'));
if (PHP_VERSION_ID < 80100) {
    exit(1);
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
$line(is_file($autoload), 'Composer-Abhängigkeiten (vendor/)', is_file($autoload) ? '' : 'vendor/autoload.php fehlt -- vendor/ mitkopieren');
if (!is_file($autoload)) {
    exit(1);
}
require $autoload;

try {
    $config = \Connect\Config::load();
    $line(true, '.env gelesen', dirname(__DIR__) . '/.env');
} catch (\Throwable $e) {
    $line(false, '.env', $e->getMessage());
    exit(1);
}

$workerName = trim($config->optional('CONNECT_WORKER_NAME', ''));
$line(true, 'Worker-Name', $workerName !== '' ? $workerName : '(nicht gesetzt -- Rechnername ' . gethostname() . ')');
$outboundIp = \Connect\Http\HttpClient::outboundIp();
$configuredRaw = trim($config->optional('CONNECT_OUTBOUND_IP', ''));
$line($configuredRaw === '' || $outboundIp !== '', 'CONNECT_OUTBOUND_IP', $configuredRaw === '' ? '(nicht gesetzt -- Standard-IP des Servers)' : ($outboundIp !== '' ? $outboundIp : "\"{$configuredRaw}\" ist keine gültige IP"));

try {
    $pdo = \Connect\Database::connect($config)->pdo();
    $line(true, 'Datenbank', (string) $pdo->query('SELECT VERSION()')->fetchColumn());

    $files = array_map('basename', glob(dirname(__DIR__) . '/migrations/*.sql') ?: []);
    $applied = array_flip($pdo->query('SELECT migration FROM connect_schema_migrations')->fetchAll(\PDO::FETCH_COLUMN));
    // bewusst keine Arrow-Function: das Skript soll auch mit zu altem PHP noch bis zur Versionsmeldung kommen
    $pending = array_values(array_filter($files, static function ($f) use ($applied) {
        return !isset($applied[$f]);
    }));
    $line($pending === [], 'Migrationen', $pending === [] ? count($files) . ' eingespielt' : 'nicht eingespielt: ' . implode(', ', $pending) . ' -- install.php auf dem Hauptserver ausführen');
} catch (\Throwable $e) {
    $line(false, 'Datenbank', $e->getMessage());
}

try {
    $response = (new \Connect\Http\HttpClient(15))->request('GET', 'https://api.ipify.org?format=json');
    $publicIp = (string) (json_decode($response->body, true)['ip'] ?? '');
    $matches = $outboundIp === '' || $publicIp === $outboundIp;
    $line($response->isSuccess() && $matches, 'Ausgehende IP laut Internet', $publicIp !== ''
        ? $publicIp . ($outboundIp !== '' && !$matches ? " -- erwartet {$outboundIp} (NAT? IP der Netzwerkkarte statt öffentlicher IP eingetragen?)" : '')
        : 'unbekannt (HTTP ' . $response->statusCode . ')');
} catch (\Throwable $e) {
    $line(false, 'Ausgehende IP', $e->getMessage() . ($outboundIp !== '' ? ' -- ist ' . $outboundIp . ' auf diesem Server eingerichtet?' : ''));
}

echo $ok ? "Alles bereit. Jetzt den Scheduler einplanen.\n" : "Bitte die Fehler oben beheben.\n";
exit($ok ? 0 : 1);
