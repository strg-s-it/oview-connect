<?php

declare(strict_types=1);

namespace Connect;

/**
 * Code-Stand dieser Installation als Fingerabdruck -- ohne Build-Schritt (Upload per FTP/WinSCP,
 * Worker per rsync): sha1 ueber Pfad + Inhalt aller Programmdateien (src, public, bin,
 * migrations; nicht .env, nicht vendor). Gleicher Code = gleiche Version, egal auf welchem
 * Server und mit welchen Datei-Zeitstempeln.
 *
 * Angezeigt im Admin-Footer (app/_footer.php) und je Worker in Betrieb -> Lastverteilung
 * (connect_scheduler_heartbeats.code_version, Migration 0086, gemeldet von bin/scheduler.php):
 * weicht ein Worker von main ab, fehlt dort der rsync.
 *
 * Berechnung kostet einige Millisekunden (Dateien lesen), deshalb CACHE_SECONDS zwischengespeichert.
 */
final class Version
{
    private const DIRS = ['src', 'public', 'bin', 'migrations'];
    private const EXTENSIONS = ['php', 'sql', 'js', 'css'];
    private const CACHE_SECONDS = 60;

    /** @var array{hash: string, updated_at: string, files: int}|null */
    private static ?array $current = null;

    /**
     * @return array{hash: string, updated_at: string, files: int} hash = 8 Zeichen, updated_at = juengste Datei (Y-m-d H:i)
     */
    public static function current(): array
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $root = dirname(__DIR__);
        $cacheFile = sys_get_temp_dir() . '/connect_version_' . md5($root) . '.json';
        $cached = @json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['at'] ?? 0) > time() - self::CACHE_SECONDS && isset($cached['hash'], $cached['updated_at'], $cached['files'])) {
            return self::$current = ['hash' => (string) $cached['hash'], 'updated_at' => (string) $cached['updated_at'], 'files' => (int) $cached['files']];
        }

        $entries = [];
        $newest = 0;
        foreach (self::DIRS as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || !in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                // Zeilenenden vereinheitlichen: derselbe Code mit CRLF (Windows) und LF zaehlt gleich
                $content = str_replace("\r\n", "\n", (string) @file_get_contents($file->getPathname()));
                $entries[$relative] = md5($content);
                $newest = max($newest, (int) $file->getMTime());
            }
        }
        ksort($entries);
        $hashInput = '';
        foreach ($entries as $relative => $md5) {
            $hashInput .= $relative . ':' . $md5 . "\n";
        }

        $result = [
            'hash' => substr(sha1($hashInput), 0, 8),
            'updated_at' => $newest > 0 ? date('Y-m-d H:i', $newest) : '',
            'files' => count($entries),
        ];
        @file_put_contents($cacheFile, (string) json_encode($result + ['at' => time()]));

        return self::$current = $result;
    }

    /** Nur der Fingerabdruck, scheitert nie (z.B. fuer den Heartbeat). */
    public static function hash(): string
    {
        try {
            return self::current()['hash'];
        } catch (\Throwable) {
            return '';
        }
    }
}
