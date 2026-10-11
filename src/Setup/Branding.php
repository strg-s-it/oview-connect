<?php

declare(strict_types=1);

namespace Connect\Setup;

use Connect\Config;
use PDO;

/**
 * Aussehen von oView an EINER Stelle (Admin -> Betrieb -> Branding, Migration 0083):
 * Name, Logo, Favicon, Hauptfarbe, Schrift und Mail-Fusszeile. Genutzt von der App-
 * Kopfzeile (app/_header.php), dem Admin-Login, dem Einrichtungs-Assistenten und allen
 * Mails an Merchants (Nachrichten, Wochenbericht, Hinweise).
 *
 * Werte in connect_settings (brand_*), Bilder in connect_brand_assets (ausgeliefert ueber
 * public/brand.php). Fehlt etwas (oder die Migration), gelten die Standardwerte -- das
 * Aussehen ist dann wie bisher.
 *
 * Schriften bewusst nur aus dem Geraet des Betrachters (keine Google Fonts o.ae.): jede
 * nachgeladene Schrift schickt die IP-Adresse an einen weiteren Anbieter (DSGVO) und
 * die App laeuft eingebettet im Shopify-Admin, wo fremde Schriften eher stoeren.
 */
final class Branding
{
    public const DEFAULT_NAME = 'oView Connect';
    public const DEFAULT_SHORT = 'oView';
    public const DEFAULT_PRIMARY = '#066fd1';
    public const DEFAULT_MAIL_FOOTER = 'oView · office@oview.at';

    /** key => [Bezeichnung, CSS-Schriftliste] -- null = Tabler-Standard (Systemschrift) */
    public const FONTS = [
        'system' => ['Systemschrift (Standard)', null],
        'segoe' => ['Segoe UI / Arial', '"Segoe UI", Arial, sans-serif'],
        'helvetica' => ['Helvetica / Arial', '"Helvetica Neue", Helvetica, Arial, sans-serif'],
        'verdana' => ['Verdana (breit, gut lesbar)', 'Verdana, Geneva, sans-serif'],
        'georgia' => ['Georgia (mit Serifen)', 'Georgia, "Times New Roman", serif'],
    ];

    public const ASSETS = ['logo' => 'Logo', 'favicon' => 'Favicon'];
    public const ASSET_MIME = ['image/png' => 'png', 'image/svg+xml' => 'svg', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'];
    public const ASSET_MAX_BYTES = 512000;

    private static ?self $current = null;

    /**
     * @param array<string, string> $assetHashes asset_key => sha1
     * @param array<string, string> $assetMimes asset_key => MIME-Typ
     */
    private function __construct(
        public readonly string $name,
        public readonly string $shortName,
        public readonly string $primary,
        public readonly string $font,
        public readonly string $mailFooter,
        public readonly bool $showNameWithLogo,
        private readonly array $assetHashes,
        private readonly array $assetMimes,
        private readonly string $baseUrl,
    ) {
    }

    /** Branding fuer diese Anfrage (einmal geladen). Ohne PDO: Standardwerte. */
    public static function load(?PDO $pdo, ?Config $config = null): self
    {
        if (self::$current !== null && $pdo !== null) {
            return self::$current;
        }
        $baseUrl = rtrim(($config ?? self::configOrNull())?->optional('CONNECT_APP_URL', 'https://connect.oview.at') ?? 'https://connect.oview.at', '/');
        if ($pdo === null) {
            return self::defaults($baseUrl);
        }

        $values = [];
        $hashes = [];
        $mimes = [];
        try {
            foreach ($pdo->query("SELECT setting_key, setting_value FROM connect_settings WHERE setting_key LIKE 'brand\\_%'")->fetchAll() as $row) {
                $values[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
            foreach ($pdo->query('SELECT asset_key, hash, mime FROM connect_brand_assets')->fetchAll() as $row) {
                $hashes[(string) $row['asset_key']] = (string) $row['hash'];
                $mimes[(string) $row['asset_key']] = (string) $row['mime'];
            }
        } catch (\Throwable) {
            // Migration 0076/0083 fehlt: Standard
        }

        $primary = self::normalizeColor($values['brand_primary'] ?? '') ?? self::DEFAULT_PRIMARY;
        $font = isset(self::FONTS[$values['brand_font'] ?? '']) ? (string) $values['brand_font'] : 'system';

        return self::$current = new self(
            trim($values['brand_name'] ?? '') !== '' ? trim($values['brand_name']) : self::DEFAULT_NAME,
            trim($values['brand_short'] ?? '') !== '' ? trim($values['brand_short']) : self::DEFAULT_SHORT,
            $primary,
            $font,
            trim($values['brand_mail_footer'] ?? '') !== '' ? trim($values['brand_mail_footer']) : self::DEFAULT_MAIL_FOOTER,
            ($values['brand_show_name'] ?? '1') !== '0',
            $hashes,
            $mimes,
            $baseUrl
        );
    }

    /** Nach dem Speichern im Admin neu laden. */
    public static function forget(): void
    {
        self::$current = null;
    }

    public static function defaults(string $baseUrl = 'https://connect.oview.at'): self
    {
        return new self(self::DEFAULT_NAME, self::DEFAULT_SHORT, self::DEFAULT_PRIMARY, 'system', self::DEFAULT_MAIL_FOOTER, true, [], [], $baseUrl);
    }

    public function hasAsset(string $key): bool
    {
        return isset($this->assetHashes[$key]);
    }

    /** URL eines Bildes -- relativ zur Domain (Seiten) oder absolut (Mails). */
    public function assetUrl(string $key, bool $absolute = false): ?string
    {
        if (!isset($this->assetHashes[$key])) {
            return null;
        }

        return ($absolute ? $this->baseUrl : '') . '/brand.php?a=' . rawurlencode($key) . '&v=' . substr($this->assetHashes[$key], 0, 12);
    }

    public function fontStack(): ?string
    {
        return self::FONTS[$this->font][1] ?? null;
    }

    /** Schrift fuer Mails (dort gibt es keine Tabler-Systemschrift). */
    public function mailFontStack(): string
    {
        return $this->fontStack() ?? '"Segoe UI", Arial, sans-serif';
    }

    /** Text-/Iconfarbe auf der Hauptfarbe: weiss oder dunkel, je nach Helligkeit. */
    public function primaryForeground(): string
    {
        return self::contrastRatio($this->primary, '#ffffff') >= 3.0 ? '#ffffff' : '#1d2733';
    }

    /**
     * Fuer den <head>: Favicon und CSS-Variablen fuer Tabler. Leer, wenn alles Standard ist.
     */
    public function headHtml(): string
    {
        $html = '';
        if (($favicon = $this->assetUrl('favicon')) !== null) {
            $html .= '<link rel="icon" href="' . htmlspecialchars($favicon, ENT_QUOTES) . '">' . "\n";
        }
        $css = '';
        if (strtolower($this->primary) !== self::DEFAULT_PRIMARY) {
            [$r, $g, $b] = self::rgb($this->primary);
            [$lr, $lg, $lb] = self::rgb(self::mix($this->primary, '#ffffff', 0.9));
            $css .= '--tblr-primary:' . $this->primary . ';--tblr-primary-rgb:' . "{$r},{$g},{$b}" . ';'
                . '--tblr-primary-darken:' . self::mix($this->primary, '#000000', 0.15) . ';'
                . '--tblr-primary-lt:' . self::mix($this->primary, '#ffffff', 0.9) . ';--tblr-primary-lt-rgb:' . "{$lr},{$lg},{$lb}" . ';'
                . '--tblr-primary-fg:' . $this->primaryForeground() . ';'
                . '--tblr-link-color:' . $this->primary . ';--tblr-link-color-rgb:' . "{$r},{$g},{$b}" . ';'
                . '--tblr-link-hover-color:' . self::mix($this->primary, '#000000', 0.15) . ';';
        }
        if (($stack = $this->fontStack()) !== null) {
            $css .= '--tblr-font-sans-serif:' . $stack . ';--tblr-body-font-family:' . $stack . ';';
        }
        if ($css !== '') {
            $html .= '<style>:root,[data-bs-theme]{' . $css . '}</style>' . "\n";
        }

        return $html;
    }

    /** Logo (falls hochgeladen) und/oder Name fuer Kopfzeilen. $height in px. */
    public function logoHtml(int $height = 32, bool $forceName = false): string
    {
        $name = htmlspecialchars($this->name, ENT_QUOTES);
        $logo = $this->assetUrl('logo');
        if ($logo === null) {
            return $name;
        }
        $html = '<img src="' . htmlspecialchars($logo, ENT_QUOTES) . '" alt="' . $name . '" style="height:' . $height . 'px;width:auto;max-width:220px;vertical-align:middle">';

        return $this->showNameWithLogo || $forceName ? $html . ' <span class="ms-2 align-middle">' . $name . '</span>' : $html;
    }

    /**
     * Gemeinsamer Rahmen fuer alle HTML-Mails: Kopf mit Logo/Name, Inhalt, Fusszeile.
     *
     * @param string $innerHtml bereits sicheres HTML
     * @param string $extraFooterHtml z.B. Abmelde-Link, bereits sicheres HTML
     */
    public function mailHtml(string $innerHtml, string $extraFooterHtml = '', bool $useShortName = true): string
    {
        $label = htmlspecialchars($useShortName ? $this->shortName : $this->name, ENT_QUOTES);
        // SVG zeigen Gmail/Outlook nicht an: dann der Name als Text
        $logo = ($this->assetMimes['logo'] ?? '') !== 'image/svg+xml' ? $this->assetUrl('logo', true) : null;
        $head = $logo !== null
            ? '<img src="' . htmlspecialchars($logo, ENT_QUOTES) . '" alt="' . $label . '" height="32" style="height:32px;width:auto;max-width:220px;border:0;display:block">'
            : '<span style="font-weight:700;color:' . $this->primary . '">' . $label . '</span>';
        $footer = nl2br(self::linkify(htmlspecialchars($this->mailFooter, ENT_QUOTES)), false);
        if ($extraFooterHtml !== '') {
            $footer .= '<br>' . $extraFooterHtml;
        }

        return '<!doctype html><html><body style="margin:0;background:#f3f5f8;font-family:' . htmlspecialchars($this->mailFontStack(), ENT_QUOTES) . ';color:#1d2733">'
            . '<div style="max-width:600px;margin:0 auto;padding:24px 16px">'
            . '<div style="background:#fff;border:1px solid #dfe4ea;border-top:4px solid ' . $this->primary . ';border-radius:12px;padding:24px;font-size:15px;line-height:1.55">'
            . '<div style="margin-bottom:16px">' . $head . '</div>'
            . $innerHtml
            . '</div>'
            . '<p style="font-size:12px;color:#8a94a3;margin:12px 4px 0">' . $footer . '</p>'
            . '</div></body></html>';
    }

    /** Button fuer Mails in der Hauptfarbe. */
    public function mailButton(string $url, string $label): string
    {
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:inline-block;background:' . $this->primary . ';color:' . $this->primaryForeground()
            . ';text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600">' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
    }

    /** Fusszeile als Text (fuer die Textfassung der Mails). */
    public function mailFooterText(): string
    {
        return "\n\n--\n" . $this->mailFooter;
    }

    // ---- Farben -----------------------------------------------------------------------

    public static function normalizeColor(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#?([0-9a-f]{3})$/', $value, $m)) {
            $value = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        if (preg_match('/^#?([0-9a-f]{6})$/', $value, $m)) {
            return '#' . $m[1];
        }

        return null;
    }

    /** WCAG-Kontrast zweier Farben (1 bis 21). */
    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /** $a mit $weight Anteil von $b gemischt. */
    private static function mix(string $a, string $b, float $weight): string
    {
        $ca = self::rgb($a);
        $cb = self::rgb($b);
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= str_pad(dechex((int) round($ca[$i] * (1 - $weight) + $cb[$i] * $weight)), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    private static function luminance(string $hex): float
    {
        $sum = 0.0;
        foreach (self::rgb($hex) as $i => $channel) {
            $c = $channel / 255;
            $c = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            $sum += [0.2126, 0.7152, 0.0722][$i] * $c;
        }

        return $sum;
    }

    private static function linkify(string $escaped): string
    {
        $escaped = (string) preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:#8a94a3">$1</a>', $escaped);

        return (string) preg_replace('~(?<![\w.:/])([\w.+-]+@[\w-]+(?:\.[\w-]+)+)~', '<a href="mailto:$1" style="color:#8a94a3">$1</a>', $escaped);
    }

    private static function configOrNull(): ?Config
    {
        try {
            return Config::load();
        } catch (\Throwable) {
            return null;
        }
    }
}
