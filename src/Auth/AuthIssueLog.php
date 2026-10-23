<?php

declare(strict_types=1);

namespace Connect\Auth;

use PDO;

/**
 * Merkt sich Anmelde-Probleme je Shop (Migration 0107), damit die Shopliste sie anzeigen kann.
 * Alles best-effort: fehlt die Tabelle oder die DB hakt, bleibt die Anmeldung davon unberuehrt.
 *
 * Geschrieben wird nur fuer Shops, die in connect_shops stehen -- sonst koennte jeder mit beliebigem
 * ?shop=... Zeilen anlegen.
 */
final class AuthIssueLog
{
    public const COOKIE_MISSING = 'cookie_fehlt';
    public const COOKIE_BLOCKED_TOKEN_OK = 'cookie_blockiert';
    public const LINK_EXPIRED = 'link_abgelaufen';
    public const SESSION_MISMATCH = 'sitzung_abgelaufen';
    public const CSRF = 'csrf';

    public static function record(PDO $pdo, string $shop, string $kind, string $userAgent = ''): void
    {
        try {
            $pdo->prepare(
                'INSERT INTO connect_auth_issues (shop, kind, hits, detail, first_seen_at, last_seen_at)
                 SELECT :shop, :kind, 1, :detail, NOW(), NOW() FROM connect_shops WHERE shop = :shop2 LIMIT 1
                 ON DUPLICATE KEY UPDATE hits = hits + 1, detail = VALUES(detail), last_seen_at = NOW()'
            )->execute(['shop' => $shop, 'kind' => $kind, 'detail' => self::describeBrowser($userAgent), 'shop2' => $shop]);
        } catch (\Throwable) {
            // Migration 0107 fehlt oder DB nicht erreichbar -- nie die Anfrage stoeren
        }
    }

    /**
     * @return array<string, list<array{kind: string, hits: int, detail: string, first_seen_at: string, last_seen_at: string}>> je Shop
     */
    public static function recent(PDO $pdo, int $days = 7): array
    {
        $result = [];
        try {
            $stmt = $pdo->prepare(
                'SELECT shop, kind, hits, detail, first_seen_at, last_seen_at FROM connect_auth_issues
                 WHERE last_seen_at >= NOW() - INTERVAL ' . (int) $days . ' DAY ORDER BY last_seen_at DESC'
            );
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                $result[(string) $row['shop']][] = [
                    'kind' => (string) $row['kind'],
                    'hits' => (int) $row['hits'],
                    'detail' => (string) ($row['detail'] ?? ''),
                    'first_seen_at' => (string) $row['first_seen_at'],
                    'last_seen_at' => (string) $row['last_seen_at'],
                ];
            }
        } catch (\Throwable) {
            // Migration 0107 fehlt
        }

        return $result;
    }

    public static function clear(PDO $pdo, string $shop): void
    {
        try {
            $pdo->prepare('DELETE FROM connect_auth_issues WHERE shop = :shop')->execute(['shop' => $shop]);
        } catch (\Throwable) {
        }
    }

    /** Grobe Angabe fuer die Anzeige, z.B. "Chrome 154, Mac" -- kein vollstaendiger User-Agent gespeichert */
    public static function describeBrowser(string $userAgent): string
    {
        if ($userAgent === '') {
            return '';
        }
        $browser = 'Browser';
        if (preg_match('~Edg/(\d+)~', $userAgent, $m) === 1) {
            $browser = 'Edge ' . $m[1];
        } elseif (preg_match('~(?:Chrome|CriOS)/(\d+)~', $userAgent, $m) === 1) {
            $browser = 'Chrome ' . $m[1];
        } elseif (preg_match('~(?:Firefox|FxiOS)/(\d+)~', $userAgent, $m) === 1) {
            $browser = 'Firefox ' . $m[1];
        } elseif (preg_match('~Version/(\d+).*Safari~', $userAgent, $m) === 1) {
            $browser = 'Safari ' . $m[1];
        }
        $os = '';
        if (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) {
            $os = 'iOS';
        } elseif (str_contains($userAgent, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($userAgent, 'Macintosh')) {
            $os = 'Mac';
        } elseif (str_contains($userAgent, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($userAgent, 'Linux')) {
            $os = 'Linux';
        }

        return $browser . ($os !== '' ? ', ' . $os : '');
    }
}
