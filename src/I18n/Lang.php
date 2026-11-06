<?php

declare(strict_types=1);

namespace Connect\I18n;

use PDO;

/**
 * Sprache der Haendler-Oberflaeche (Deutsch/Englisch).
 *
 * Quelltexte stehen im Code auf Deutsch: t('Übersicht') liefert bei Deutsch den Text selbst,
 * bei Englisch die Uebersetzung aus src/I18n/en/*.php (Schluessel = deutscher Text). Fehlt eine
 * Uebersetzung, erscheint der deutsche Text -- Seiten lassen sich so Stueck fuer Stueck umstellen.
 * Platzhalter: t('Schritt {n} von {m}', ['n' => 1, 'm' => 3]).
 *
 * Gespeichert in connect_shops.language (Migration 0109), ohne die Migration in der Sitzung.
 */
final class Lang
{
    /** @var array<string, string> Kuerzel => Name in der Sprache selbst */
    public const SUPPORTED = ['de' => 'Deutsch', 'en' => 'English'];

    private const SESSION_KEY = 'connect_lang';

    private static string $code = 'de';

    /** @var array<string, string>|null */
    private static ?array $english = null;

    public static function code(): string
    {
        return self::$code;
    }

    public static function isValid(string $code): bool
    {
        return isset(self::SUPPORTED[$code]);
    }

    /** Sprache des Shops laden (DB, sonst Sitzung, sonst Deutsch). */
    public static function load(PDO $pdo, string $shop): void
    {
        self::$code = 'de';
        try {
            $stmt = $pdo->prepare('SELECT language FROM connect_shops WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);
            $value = $stmt->fetchColumn();
            if (is_string($value) && self::isValid($value)) {
                self::$code = $value;

                return;
            }
        } catch (\Throwable) {
            // Migration 0109 fehlt noch: Sitzung unten
        }
        $session = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_string($session) && self::isValid($session)) {
            self::$code = $session;
        }
    }

    /** Sprache speichern und fuer diese Anfrage uebernehmen. */
    public static function save(PDO $pdo, string $shop, string $code): bool
    {
        if (!self::isValid($code)) {
            return false;
        }
        self::$code = $code;
        $_SESSION[self::SESSION_KEY] = $code;
        try {
            $pdo->prepare('UPDATE connect_shops SET language = :code WHERE shop = :shop')
                ->execute(['code' => $code, 'shop' => $shop]);
        } catch (\Throwable) {
            // Migration 0109 fehlt noch: gilt nur fuer die Sitzung
        }

        return true;
    }

    /**
     * @param array<string, scalar> $vars Platzhalter {name} => Wert (unveraendert eingesetzt, Ausgabe-Escaping macht der Aufrufer)
     */
    public static function t(string $german, array $vars = []): string
    {
        $text = $german;
        if (self::$code === 'en') {
            self::$english ??= self::loadEnglish();
            $text = self::$english[$german] ?? $german;
        }
        if ($vars !== []) {
            $pairs = [];
            foreach ($vars as $name => $value) {
                $pairs['{' . $name . '}'] = (string) $value;
            }
            $text = strtr($text, $pairs);
        }

        return $text;
    }

    /** @return array<string, string> */
    private static function loadEnglish(): array
    {
        $all = [];
        foreach (glob(__DIR__ . '/en/*.php') ?: [] as $file) {
            $part = require $file;
            if (is_array($part)) {
                $all = array_merge($all, $part);
            }
        }

        return $all;
    }

    /** Flagge als kleines Inline-SVG (Windows zeigt Flaggen-Emoji nur als Buchstaben). */
    public static function flag(string $code, int $height = 14): string
    {
        $width = (int) round($height * 1.5);
        $attrs = 'width="' . $width . '" height="' . $height . '" class="lang-flag" role="img" aria-hidden="true" focusable="false"';
        if ($code === 'en') {
            return '<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . ' viewBox="0 0 60 40" preserveAspectRatio="none">'
                . '<rect width="60" height="40" fill="#012169"/>'
                . '<path d="M0,0 L60,40 M60,0 L0,40" stroke="#fff" stroke-width="8"/>'
                . '<path d="M0,0 L60,40 M60,0 L0,40" stroke="#C8102E" stroke-width="3"/>'
                . '<path d="M30,0 V40 M0,20 H60" stroke="#fff" stroke-width="13"/>'
                . '<path d="M30,0 V40 M0,20 H60" stroke="#C8102E" stroke-width="8"/>'
                . '</svg>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . ' viewBox="0 0 3 2" preserveAspectRatio="none">'
            . '<rect width="3" height="2" fill="#FFCE00"/><rect width="3" height="1.3333" fill="#DD0000"/><rect width="3" height="0.6667" fill="#000"/>'
            . '</svg>';
    }

    /**
     * Sprachwahl fuer die Kopfzeile: aktuelle Flagge, Klick klappt die Liste auf.
     * Links setzen ?lang=xx auf die aktuelle Seite (bootstrap.php speichert und leitet zurueck).
     */
    public static function switcherHtml(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = basename((string) parse_url($uri, PHP_URL_PATH));
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        unset($query['lang'], $query['ct']);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $items = '';
        foreach (self::SUPPORTED as $code => $name) {
            $href = ($path !== '' ? $path : 'dashboard.php') . '?' . http_build_query(array_merge($query, ['lang' => $code]));
            $items .= '<a class="dropdown-item d-flex align-items-center gap-2' . ($code === self::$code ? ' active' : '') . '" href="' . $e($href) . '">'
                . self::flag($code, 14) . '<span>' . $e($name) . '</span></a>';
        }

        return '<div class="nav-item dropdown lang-switch">'
            . '<a class="nav-link px-2 d-flex align-items-center gap-1" href="#" data-bs-toggle="dropdown" role="button" aria-expanded="false" title="'
            . $e(self::t('Sprache') . ': ' . self::SUPPORTED[self::$code]) . '" aria-label="' . $e(self::t('Sprache wählen')) . '">'
            . self::flag(self::$code, 16) . '</a>'
            . '<div class="dropdown-menu dropdown-menu-end">' . $items . '</div></div>';
    }
}
