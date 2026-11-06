<?php

declare(strict_types=1);

namespace Connect\I18n;

/**
 * Uebersetzt die fertig gerenderte Seite (Ausgabepuffer, siehe Lang::startOutputTranslation()).
 *
 * Statt jeden Text im Code in t() zu packen, wird die HTML-Ausgabe bei Englisch Stueck fuer Stueck
 * mit dem Woerterbuch (src/I18n/en/*.php, Schluessel = deutscher Text) abgeglichen:
 *   - Textknoten zwischen Tags (Leerraum zusammengefasst, Entities aufgeloest)
 *   - Attribute title, placeholder, aria-label, alt, data-confirm, Schaltflaechen-value
 *   - Zeichenketten in <script> sowie confirm()/alert() in on*-Attributen (nur genaue Treffer)
 *   - JSON-Antworten (Textwerte), HTML-Schnipsel darin ebenfalls
 * Woerterbuch-Schluessel mit {platzhalter} passen auf Texte mit wechselnden Werten
 * ("{n} fehlen"); der erfasste Wert wird selbst noch einmal uebersetzt ("vor {n} Minuten").
 * Nicht Gefundenes bleibt Deutsch. Nur bei text/html und application/json, nie bei Downloads.
 */
final class OutputTranslator
{
    /** @var array<string, string|null> */
    private static array $cache = [];

    /** @var list<array{regex: string, names: list<string>, english: string, weight: int}>|null */
    private static ?array $patterns = null;

    /** Ausgabepuffer-Callback */
    public static function filter(string $buffer, int $phase = 0): string
    {
        if (Lang::code() !== 'en' || $buffer === '') {
            return $buffer;
        }
        $type = '';
        foreach (headers_list() as $header) {
            $lower = strtolower($header);
            if (str_starts_with($lower, 'content-disposition:') && str_contains($lower, 'attachment')) {
                return $buffer;
            }
            if (str_starts_with($lower, 'content-type:')) {
                $type = trim(substr($lower, strlen('content-type:')));
            }
        }
        try {
            if ($type === '' || str_starts_with($type, 'text/html')) {
                return self::html($buffer);
            }
            if (str_starts_with($type, 'application/json')) {
                return self::json($buffer);
            }
        } catch (\Throwable) {
            // lieber deutsche Seite als gar keine
        }

        return $buffer;
    }

    public static function html(string $html): string
    {
        $parts = preg_split(
            '~(<!--.*?-->|<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<[a-zA-Z/!][^>]*>)~is',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        if ($parts === false) {
            return $html;
        }
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            if ($i % 2 === 0) {
                $parts[$i] = self::textNode($part);
            } elseif (stripos($part, '<script') === 0) {
                $parts[$i] = self::script($part);
            } elseif ($part[0] === '<' && !str_starts_with($part, '<!') && stripos($part, '<style') !== 0) {
                $parts[$i] = self::tag($part);
            }
        }

        return implode('', $parts);
    }

    public static function json(string $json): string
    {
        $data = json_decode($json, true);
        if ($data === null) {
            return $json;
        }
        $walk = static function (mixed $value) use (&$walk): mixed {
            if (is_array($value)) {
                return array_map($walk, $value);
            }
            if (is_string($value) && $value !== '') {
                if (str_contains($value, '<') && str_contains($value, '>')) {
                    return self::html($value);
                }

                return self::translate($value) ?? $value;
            }

            return $value;
        };

        return (string) json_encode($walk($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function textNode(string $node): string
    {
        if (!preg_match('/\p{L}/u', $node) || !preg_match('/^(\s*)(.*?)(\s*)$/su', $node, $m)) {
            return $node;
        }
        $english = self::translate(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $english === null ? $node : $m[1] . htmlspecialchars($english, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . $m[3];
    }

    private static function tag(string $tag): string
    {
        $isButton = (bool) preg_match('~^<(?:button|input\b[^>]*\btype=["\']?(?:submit|button|reset))~i', $tag);
        $names = 'title|placeholder|aria-label|alt|data-confirm|data-bs-original-title' . ($isButton ? '|value' : '');

        $tag = (string) preg_replace_callback(
            '~(\s(?:' . $names . ')=)(["\'])(.*?)\2~is',
            static function (array $m): string {
                $english = self::translate(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                return $english === null ? $m[0] : $m[1] . $m[2] . htmlspecialchars($english, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $m[2];
            },
            $tag
        );

        // onclick="return confirm('...')" und Verwandte: Zeichenketten genau uebersetzen
        return (string) preg_replace_callback(
            '~(\son[a-z]+=)(["\'])(.*?)\2~is',
            static function (array $m): string {
                $code = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $new = self::scriptStrings($code);

                return $new === $code ? $m[0] : $m[1] . $m[2] . htmlspecialchars($new, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $m[2];
            },
            $tag
        );
    }

    private static function script(string $block): string
    {
        $open = strpos($block, '>');
        $close = strripos($block, '</script>');
        if ($open === false || $close === false || $close <= $open) {
            return $block;
        }
        $body = substr($block, $open + 1, $close - $open - 1);
        if (str_contains(substr($block, 0, $open), 'src=')) {
            return $block;
        }

        return substr($block, 0, $open + 1) . self::scriptStrings($body) . substr($block, $close);
    }

    /** Zeichenketten in JS-Code, die genau einem Woerterbuch-Eintrag entsprechen (auch mit \' darin). */
    private static function scriptStrings(string $code): string
    {
        return (string) preg_replace_callback(
            '~(["\'])((?:\\\\.|(?!\1)[^\\\\\r\n])*)\1~u',
            static function (array $m): string {
                $quote = $m[1];
                $raw = stripcslashes($m[2]);
                if (!preg_match('/\p{L}{2}/u', $raw)) {
                    return $m[0];
                }
                // Muster nur bei mehreren Woertern (confirm-Texte mit eingesetzten Werten)
                $english = self::translate($raw, str_contains(trim($raw), ' '));
                if ($english === null) {
                    return $m[0];
                }

                return $quote . addcslashes($english, $quote . '\\' . "\n\r") . $quote;
            },
            $code
        );
    }

    /**
     * Deutschen Text (Entities schon aufgeloest) uebersetzen; null = nichts gefunden.
     *
     * @param bool $patterns false = nur genaue Treffer (Skripte)
     */
    public static function translate(string $text, bool $patterns = true): ?string
    {
        $norm = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($norm === '') {
            return null;
        }
        $cacheKey = ($patterns ? 'p|' : 'e|') . $norm;
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $dictionary = Lang::dictionary();
        $result = $dictionary[$norm] ?? null;

        if ($result === null && $patterns) {
            foreach (self::patterns() as $pattern) {
                if (!preg_match($pattern['regex'], $norm, $m)) {
                    continue;
                }
                $pairs = [];
                foreach ($pattern['names'] as $name) {
                    $value = (string) ($m[$name] ?? '');
                    $pairs['{' . $name . '}'] = self::translate($value) ?? $value;
                }
                $result = strtr($pattern['english'], $pairs);
                break;
            }
        }

        // "Name · Shop": jedes Stueck einzeln
        if ($result === null && $patterns && str_contains($norm, ' · ')) {
            $pieces = explode(' · ', $norm);
            $changed = false;
            foreach ($pieces as $k => $piece) {
                $english = self::translate($piece);
                if ($english !== null) {
                    $pieces[$k] = $english;
                    $changed = true;
                }
            }
            $result = $changed ? implode(' · ', $pieces) : null;
        }

        return self::$cache[$cacheKey] = $result;
    }

    /** @return list<array{regex: string, names: list<string>, english: string, weight: int}> */
    private static function patterns(): array
    {
        if (self::$patterns !== null) {
            return self::$patterns;
        }
        $list = [];
        foreach (Lang::dictionary() as $german => $english) {
            if (!str_contains($german, '{')) {
                continue;
            }
            $regex = '';
            $offset = 0;
            $literal = 0;
            preg_match_all('/\{([a-z0-9_]+)\}/i', $german, $positions, PREG_OFFSET_CAPTURE);
            $seen = [];
            foreach ($positions[0] as $k => [$token, $pos]) {
                $chunk = substr($german, $offset, $pos - $offset);
                $regex .= preg_quote($chunk, '~');
                $literal += strlen($chunk);
                $name = $positions[1][$k][0];
                // Zahlen-Platzhalter (n, m, a, b, days, done, total) passen nur auf Ziffern: "{n} offen" soll
                // "Noch offen" nicht treffen
                $capture = in_array($name, ['n', 'm', 'a', 'b', 'days', 'done', 'total'], true) ? '\d[\d.,]*' : '.+?';
                $regex .= isset($seen[$name]) ? '\k<' . $name . '>' : '(?<' . $name . '>' . $capture . ')';
                $seen[$name] = true;
                $offset = $pos + strlen($token);
            }
            $tail = substr($german, $offset);
            $regex .= preg_quote($tail, '~');
            $literal += strlen($tail);
            $list[] = ['regex' => '~^' . $regex . '$~su', 'names' => array_keys($seen), 'english' => $english, 'weight' => $literal];
        }
        usort($list, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return self::$patterns = $list;
    }
}
