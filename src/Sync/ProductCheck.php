<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use Connect\Connector\Generic\GenericHttpConnector;
use Connect\Http\HttpClient;
use PDO;

/**
 * Produkt-Check (Migration 0090): Datenqualitaet und SEO der Shopify-Produkte mit Punktwert.
 *
 * Ablauf: start() startet eine Shopify-Massenabfrage (bulkOperationRunQuery) -- Shopify sammelt
 * alle Produkte samt Bildern und Varianten im Hintergrund, ohne das Anfrage-Limit zu belasten.
 * advance() fragt den Stand ab und wertet nach Abschluss die JSONL-Datei aus. Aufgerufen beim
 * Oeffnen der Seite (und solange sie offen ist, alle 15 s).
 *
 * Pruefungen je Produkt (CHECKS): Gewicht = Bedeutung fuer Kunden/Suchmaschinen. Punktwert = Anteil
 * erfuellter Pruefungen, gewichtet, ueber alle aktiven Produkte.
 */
final class ProductCheck
{
    /** key => [Bezeichnung, Gewicht, Hinweis] */
    public const CHECKS = [
        'no_image' => ['Kein Produktbild', 3, 'Produkte ohne Bild werden kaum gekauft und von Google Shopping abgelehnt.'],
        'short_description' => ['Beschreibung fehlt oder unter 50 Zeichen', 3, 'Kunden und Suchmaschinen brauchen eine aussagekräftige Beschreibung.'],
        // Textqualitaet ohne KI (2026-10-10): nur Regeln, siehe textFindings()
        'duplicate_description' => ['Gleiche Beschreibung wie andere Produkte', 2, 'Oft ein kopierter Herstellertext – Google wertet doppelte Inhalte ab. Ein eigener Text hebt das Produkt hervor.'],
        'title_only' => ['Beschreibung wiederholt nur den Titel', 2, 'Sagt nichts über das Produkt – beschreib Nutzen, Einsatz und wichtige Eigenschaften.'],
        'thin_description' => ['Beschreibung kurz (unter 300 Zeichen)', 1, 'Ein paar Sätze mehr zu Nutzen, Material, Maßen oder Lieferumfang helfen Kunden und Suchmaschinen.'],
        'no_structure' => ['Langer Text ohne Absätze', 1, 'Ein Textblock wird kaum gelesen – Absätze, Zwischenüberschriften oder Aufzählungen machen ihn übersichtlich.'],
        'text_issues' => ['Auffälligkeiten im Text', 1, 'Viel GROSSSCHRIFT, mehrere Ausrufezeichen, Platzhalter (z. B. „Lorem ipsum“) oder HTML-Reste im Text.'],
        'other_language' => ['Text in anderer Sprache als der Shop', 1, 'Die meisten Beschreibungen sind in einer Sprache, diese in einer anderen – z. B. ein englischer Herstellertext.'],
        'missing_alt' => ['Bilder ohne Alt-Text', 2, 'Alt-Texte helfen bei der Google-Bildersuche und für Barrierefreiheit.'],
        'seo_title' => ['SEO-Titel fehlt oder über 70 Zeichen', 1, 'Wird in den Suchergebnissen angezeigt – kurz und aussagekräftig halten.'],
        'seo_description' => ['Meta-Beschreibung fehlt oder über 160 Zeichen', 1, 'Der Text unter dem Titel in den Suchergebnissen.'],
        'duplicate_title' => ['Titel mehrfach vergeben', 2, 'Gleiche Titel verwirren Kunden und Suchmaschinen.'],
        'missing_sku' => ['Variante ohne SKU', 2, 'Ohne SKU ist der Abgleich mit dem Kassensystem oft nicht möglich.'],
        'missing_barcode' => ['Variante ohne Barcode (EAN)', 1, 'Für Google Shopping und viele Marktplätze nötig.'],
        'no_product_type' => ['Kein Produkttyp', 1, 'Hilft bei Filtern, Kollektionen und Auswertungen.'],
    ];

    /**
     * Gruppen der Pruefungen mit Abo-Freigabe (Plans::FEATURES, null = jedes Abo). Punktwert und
     * Anzahl je Pruefung sehen alle, die Produktliste und die Textansicht nur freigegebene Gruppen.
     *
     * key => [Bezeichnung, Feature oder null, Pruefungen]
     */
    public const GROUPS = [
        'basic' => ['Grunddaten', null, ['no_image', 'short_description', 'missing_sku', 'missing_barcode', 'no_product_type', 'duplicate_title']],
        'seo' => ['Suchmaschinen (SEO)', 'product_check_seo', ['missing_alt', 'seo_title', 'seo_description']],
        'text' => ['Textqualität', 'product_check_text', ['duplicate_description', 'title_only', 'thin_description', 'no_structure', 'text_issues', 'other_language']],
    ];

    /** Pruefungen, deren Befunde man in der Textansicht sieht */
    public const TEXT_CHECKS = ['short_description', 'duplicate_description', 'title_only', 'thin_description', 'no_structure', 'text_issues', 'other_language'];

    private const LANGUAGE_NAMES = ['de' => 'Deutsch', 'en' => 'Englisch'];

    private const PLACEHOLDER_PATTERN = '/\b(lorem ipsum|todo|tbd|platzhalter|placeholder|text folgt|beschreibung folgt)\b/iu';
    private const HTML_REMNANT_PATTERN = '/<\/?[a-z][^>]*>|&[a-z]+;|\{\{/i';

    private const PRODUCT_TEXT = <<<'GRAPHQL'
query ($id: ID!) {
  product(id: $id) { id title descriptionHtml }
}
GRAPHQL;

    private const QUERY = <<<'GRAPHQL'
{
  products(query: "status:active") {
    edges {
      node {
        id
        title
        handle
        productType
        descriptionHtml
        seo { title description }
        media { edges { node { ... on MediaImage { id alt } } } }
        variants { edges { node { id sku barcode } } }
      }
    }
  }
}
GRAPHQL;

    private const START = <<<'GRAPHQL'
mutation ($query: String!) {
  bulkOperationRunQuery(query: $query) {
    bulkOperation { id status }
    userErrors { field message }
  }
}
GRAPHQL;

    private const STATUS = <<<'GRAPHQL'
query ($id: ID!) {
  node(id: $id) { ... on BulkOperation { id status errorCode objectCount url } }
}
GRAPHQL;

    public function __construct(private readonly PDO $pdo, private readonly ConnectorFactory $factory)
    {
    }

    /** @return array<string, mixed>|null gespeicherter Stand (summary/issues dekodiert) */
    public function stored(string $shop): ?array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM connect_product_checks WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        if ($row === false) {
            return null;
        }
        $row['summary'] = json_decode((string) ($row['summary'] ?? ''), true) ?: [];
        $row['issues'] = json_decode((string) ($row['issues'] ?? ''), true) ?: [];

        return $row;
    }

    public function start(string $shop): ?string
    {
        try {
            $connector = $this->shopifyConnector($shop);
            $response = $connector->graphQlQuery(self::START, ['query' => self::QUERY]);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        $errors = $response['data']['bulkOperationRunQuery']['userErrors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            $message = implode('; ', array_map(static fn ($e): string => (string) ($e['message'] ?? ''), $errors));

            return str_contains(strtolower($message), 'already in progress')
                ? 'In Shopify läuft gerade schon eine Massenabfrage für diesen Shop – bitte in ein paar Minuten noch einmal.'
                : $message;
        }
        $operationId = (string) ($response['data']['bulkOperationRunQuery']['bulkOperation']['id'] ?? '');
        if ($operationId === '') {
            return 'Shopify hat die Abfrage nicht angenommen.';
        }
        $this->pdo->prepare(
            "INSERT INTO connect_product_checks (shop, operation_id, status, started_at, error) VALUES (:shop, :op, 'running', CURRENT_TIMESTAMP, NULL)
             ON DUPLICATE KEY UPDATE operation_id = VALUES(operation_id), status = 'running', started_at = CURRENT_TIMESTAMP, error = NULL"
        )->execute(['shop' => $shop, 'op' => $operationId]);

        return null;
    }

    /**
     * Laufende Pruefung weiterfuehren: Stand bei Shopify abfragen, bei Abschluss auswerten.
     *
     * @return string running | done | failed | none
     */
    public function advance(string $shop): string
    {
        $row = $this->stored($shop);
        if ($row === null || $row['status'] !== 'running') {
            return $row['status'] ?? 'none';
        }
        try {
            $connector = $this->shopifyConnector($shop);
            $status = $connector->graphQlQuery(self::STATUS, ['id' => (string) $row['operation_id']])['data']['node'] ?? null;
            if (!is_array($status)) {
                throw new \RuntimeException('Massenabfrage bei Shopify nicht gefunden.');
            }
            $state = (string) ($status['status'] ?? '');
            if (in_array($state, ['CREATED', 'RUNNING'], true)) {
                // Haengt sie seit ueber einer Stunde, aufgeben
                if (strtotime((string) $row['started_at']) < time() - 3600) {
                    throw new \RuntimeException('Shopify hat die Massenabfrage nach einer Stunde nicht abgeschlossen.');
                }

                return 'running';
            }
            if ($state !== 'COMPLETED') {
                throw new \RuntimeException('Shopify meldet ' . $state . (($status['errorCode'] ?? null) ? ' (' . $status['errorCode'] . ')' : '') . '.');
            }
            $url = (string) ($status['url'] ?? '');
            $result = $url === '' ? $this->evaluate([]) : $this->evaluate($this->download($url));
            $this->pdo->prepare(
                "UPDATE connect_product_checks SET status = 'done', score = :score, products = :products, summary = :summary, issues = :issues, finished_at = CURRENT_TIMESTAMP WHERE shop = :shop"
            )->execute([
                'score' => $result['score'], 'products' => $result['products'],
                'summary' => json_encode($result['summary']), 'issues' => json_encode($result['issues'], JSON_UNESCAPED_UNICODE), 'shop' => $shop,
            ]);

            return 'done';
        } catch (\Throwable $e) {
            $this->pdo->prepare("UPDATE connect_product_checks SET status = 'failed', error = :e, finished_at = CURRENT_TIMESTAMP WHERE shop = :shop")
                ->execute(['e' => mb_substr($e->getMessage(), 0, 500), 'shop' => $shop]);

            return 'failed';
        }
    }

    /**
     * JSONL der Massenabfrage auswerten.
     *
     * @param list<array<string, mixed>> $lines
     * @return array{score: int, products: int, summary: array<string, int>, issues: list<array{id: string, title: string, issues: list<string>}>}
     */
    public function evaluate(array $lines): array
    {
        $products = [];
        foreach ($lines as $line) {
            $id = (string) ($line['id'] ?? '');
            $parent = (string) ($line['__parentId'] ?? '');
            if ($parent === '' && str_contains($id, '/Product/')) {
                $products[$id] = ['node' => $line, 'images' => 0, 'missing_alt' => 0, 'variants' => 0, 'no_sku' => 0, 'no_barcode' => 0];
            } elseif ($parent !== '' && isset($products[$parent])) {
                if (str_contains($id, '/MediaImage/')) {
                    $products[$parent]['images']++;
                    if (trim((string) ($line['alt'] ?? '')) === '') {
                        $products[$parent]['missing_alt']++;
                    }
                } elseif (str_contains($id, '/ProductVariant/')) {
                    $products[$parent]['variants']++;
                    $products[$parent]['no_sku'] += trim((string) ($line['sku'] ?? '')) === '' ? 1 : 0;
                    $products[$parent]['no_barcode'] += trim((string) ($line['barcode'] ?? '')) === '' ? 1 : 0;
                }
            }
        }

        $titleCount = [];
        // Gleiche Beschreibungen: Fingerabdruck nur aus Buchstaben (ohne Zahlen/Satzzeichen), damit
        // Vorlagen-Texte mit anderen Massen ("5 Watt" / "15 Watt") ebenfalls auffallen
        $descriptionCount = [];
        // Sprache je Produkt (nur bei genug Text), Mehrheit = Sprache des Shops
        $languageOf = [];
        foreach ($products as $id => $p) {
            $key = mb_strtolower(trim((string) ($p['node']['title'] ?? '')));
            $titleCount[$key] = ($titleCount[$key] ?? 0) + 1;
            $text = self::plainText((string) ($p['node']['descriptionHtml'] ?? ''));
            $fingerprint = self::fingerprint($text);
            if ($fingerprint !== null) {
                $descriptionCount[$fingerprint] = ($descriptionCount[$fingerprint] ?? 0) + 1;
            }
            $language = self::language($text);
            if ($language !== null) {
                $languageOf[$id] = $language;
            }
        }
        $languageVotes = array_count_values($languageOf);
        arsort($languageVotes);
        $shopLanguage = $languageVotes !== [] ? (string) array_key_first($languageVotes) : null;
        // Nur eindeutige Mehrheit (mind. 60 %) -- zweisprachige Shops nicht alle markieren
        if ($shopLanguage !== null && $languageVotes[$shopLanguage] < 0.6 * count($languageOf)) {
            $shopLanguage = null;
        }

        $summary = array_fill_keys(array_keys(self::CHECKS), 0);
        $issues = [];
        $weightTotal = array_sum(array_column(self::CHECKS, 1));
        $scoreSum = 0.0;
        foreach ($products as $id => $p) {
            $node = $p['node'];
            $title = trim((string) ($node['title'] ?? ''));
            $description = trim(html_entity_decode(strip_tags((string) ($node['descriptionHtml'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $seoTitle = trim((string) ($node['seo']['title'] ?? ''));
            $seoDescription = trim((string) ($node['seo']['description'] ?? ''));
            // Pruefung => kurze Angabe fuer die Liste (z.B. "182 Zeichen"), '' = keine
            $found = [];
            if ($p['images'] === 0) {
                $found['no_image'] = '';
            }
            if (mb_strlen($description) < 50) {
                $found['short_description'] = $description === '' ? 'fehlt' : mb_strlen($description) . ' Zeichen';
            }
            $plain = self::plainText((string) ($node['descriptionHtml'] ?? ''));
            $fingerprint = self::fingerprint($plain);
            if ($fingerprint !== null && ($descriptionCount[$fingerprint] ?? 0) > 1) {
                $found['duplicate_description'] = 'gleich bei ' . $descriptionCount[$fingerprint] . ' Produkten';
            }
            $found += self::textFindings($title, $plain, (string) ($node['descriptionHtml'] ?? ''));
            if ($shopLanguage !== null && isset($languageOf[$id]) && $languageOf[$id] !== $shopLanguage) {
                $found['other_language'] = (self::LANGUAGE_NAMES[$languageOf[$id]] ?? $languageOf[$id]) . ', Shop meist ' . (self::LANGUAGE_NAMES[$shopLanguage] ?? $shopLanguage);
            }
            if ($p['missing_alt'] > 0) {
                $found['missing_alt'] = $p['missing_alt'] . ' von ' . $p['images'] . ' Bildern';
            }
            // Shopify nimmt ohne eigenen SEO-Titel den Produkttitel -- dann zaehlt dessen Laenge
            $seoTitleLength = mb_strlen($seoTitle !== '' ? $seoTitle : $title);
            if ($seoTitleLength > 70) {
                $found['seo_title'] = $seoTitleLength . ' Zeichen';
            }
            // ohne eigene Meta-Beschreibung nimmt Shopify den Anfang der Beschreibung
            if (($seoDescription === '' && $description === '') || mb_strlen($seoDescription) > 160) {
                $found['seo_description'] = $seoDescription === '' ? 'fehlt' : mb_strlen($seoDescription) . ' Zeichen';
            }
            if (($titleCount[mb_strtolower($title)] ?? 0) > 1) {
                $found['duplicate_title'] = $titleCount[mb_strtolower($title)] . ' Produkte';
            }
            if ($p['no_sku'] > 0) {
                $found['missing_sku'] = $p['no_sku'] . ' von ' . $p['variants'] . ' Varianten';
            }
            if ($p['no_barcode'] > 0) {
                $found['missing_barcode'] = $p['no_barcode'] . ' von ' . $p['variants'] . ' Varianten';
            }
            if (trim((string) ($node['productType'] ?? '')) === '') {
                $found['no_product_type'] = '';
            }

            $lost = 0;
            foreach (array_keys($found) as $key) {
                $summary[$key]++;
                $lost += self::CHECKS[$key][1];
            }
            $scoreSum += ($weightTotal - $lost) / $weightTotal;
            if ($found !== []) {
                $issues[] = [
                    'id' => (string) $id,
                    'title' => $title !== '' ? $title : (string) ($node['handle'] ?? $id),
                    'issues' => array_keys($found),
                    'details' => array_filter($found, static fn (string $d): bool => $d !== ''),
                ];
            }
        }
        usort($issues, static fn (array $a, array $b): int => count($b['issues']) <=> count($a['issues']) ?: strnatcasecmp($a['title'], $b['title']));

        return [
            'score' => $products === [] ? 100 : (int) round($scoreSum / count($products) * 100),
            'products' => count($products),
            'summary' => $summary,
            'issues' => $issues,
        ];
    }

    /** Beschreibung als reiner Text, Leerraum zusammengefasst. */
    private static function plainText(string $html): string
    {
        // Block-Elemente als Leerzeichen, sonst kleben Woerter aus Absaetzen zusammen
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** Fingerabdruck fuer "gleiche Beschreibung" -- null bei zu kurzem Text (das zaehlt schon als "kurz"). */
    private static function fingerprint(string $text): ?string
    {
        $letters = preg_replace('/[^\p{L}]+/u', '', mb_strtolower($text)) ?? '';

        return mb_strlen($letters) >= 80 ? md5($letters) : null;
    }

    /**
     * Grobe Spracherkennung ueber haeufige Woerter (de/en) -- nur bei mindestens 40 Woertern und
     * deutlichem Ueberhang, sonst null.
     */
    private static function language(string $text): ?string
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 40) {
            return null;
        }
        $stop = [
            'de' => ['und', 'der', 'die', 'das', 'mit', 'für', 'ist', 'nicht', 'ein', 'eine', 'auch', 'sie', 'zu', 'den', 'dem', 'auf', 'von', 'sich', 'wird', 'oder'],
            'en' => ['the', 'and', 'with', 'for', 'is', 'this', 'you', 'your', 'of', 'to', 'are', 'it', 'its', 'that', 'from', 'can', 'on', 'be', 'an', 'or'],
        ];
        $hits = [];
        foreach ($stop as $language => $list) {
            $set = array_flip($list);
            $hits[$language] = count(array_filter($words, static fn (string $w): bool => isset($set[$w])));
        }
        arsort($hits);
        [$first, $second] = array_keys($hits);

        return $hits[$first] >= 3 && $hits[$first] >= 3 * max(1, $hits[$second]) ? $first : null;
    }

    /**
     * Regeln zur Textqualitaet (ohne KI).
     *
     * @return array<string, string> Pruefung => kurze Angabe
     */
    private static function textFindings(string $title, string $text, string $html): array
    {
        $found = [];
        $length = mb_strlen($text);
        if ($length < 50) {
            return $found; // schon "fehlt oder unter 50 Zeichen"
        }

        // Nur der Titel (evtl. mit ein paar Zeichen drumherum)
        $normalize = static fn (string $s): string => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)) ?? '';
        $titleNorm = $normalize($title);
        $textNorm = $normalize($text);
        if ($titleNorm !== '' && str_contains($textNorm, $titleNorm) && mb_strlen($textNorm) <= mb_strlen($titleNorm) + 20) {
            $found['title_only'] = '';
        } elseif ($length < 300) {
            $found['thin_description'] = $length . ' Zeichen';
        }

        // Langer Text ohne Absaetze/Aufzaehlung/Zwischenueberschrift
        if ($length >= 600 && preg_match_all('#<(p|br|li|h[1-6])\b#i', $html) < 2) {
            $found['no_structure'] = number_format($length, 0, ',', '.') . ' Zeichen am Stück';
        }

        $reasons = self::textIssueReasons($text);
        if ($reasons !== []) {
            $found['text_issues'] = implode(', ', $reasons);
        }

        return $found;
    }

    /**
     * Grossschrift, Ausrufezeichen, Platzhalter, HTML-Reste.
     *
     * @return list<string>
     */
    private static function textIssueReasons(string $text): array
    {
        $reasons = [];
        $words = preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $longWords = array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 4);
        $caps = array_filter($longWords, static fn (string $w): bool => $w === mb_strtoupper($w));
        if (count($caps) >= 5 && count($caps) > 0.3 * max(1, count($longWords))) {
            $reasons[] = 'viel GROSSSCHRIFT';
        }
        if (preg_match('/!{2,}/', $text) === 1) {
            $reasons[] = 'mehrere Ausrufezeichen';
        }
        if (preg_match(self::PLACEHOLDER_PATTERN, $text) === 1) {
            $reasons[] = 'Platzhalter';
        }
        // escapte Tags, doppelt kodierte Entities (&amp;nbsp;), Template-Klammern
        if (preg_match(self::HTML_REMNANT_PATTERN, $text) === 1) {
            $reasons[] = 'HTML-Reste';
        }

        return $reasons;
    }

    /**
     * Beschreibung eines Produkts live aus Shopify, als Text mit markierten Problemstellen
     * (<mark>), fuer die Textansicht in der Liste. Nichts wird gespeichert.
     *
     * @return array{title: string, html: string, length: int, paragraphs: int, language: ?string}
     */
    public function productText(string $shop, string $productGid): array
    {
        $product = $this->shopifyConnector($shop)->graphQlQuery(self::PRODUCT_TEXT, ['id' => $productGid])['data']['product'] ?? null;
        if (!is_array($product)) {
            throw new \RuntimeException('Produkt in Shopify nicht gefunden.');
        }
        $title = (string) ($product['title'] ?? '');
        $html = (string) ($product['descriptionHtml'] ?? '');

        // Absaetze erhalten: Block-Elemente als Zeilenumbruch, dann Tags entfernen
        $withBreaks = preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;
        $withBreaks = preg_replace('#<li\b[^>]*>#i', '• ', $withBreaks) ?? $withBreaks;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\n\s*\n+/u", "\n\n", $text) ?? $text) ?? $text);

        // Markierungen nur fuer das, was auch als Befund zaehlt (sonst waere z.B. jedes "HDMI" gelb):
        // Fundstellen als Byte-Bereiche sammeln, dann abschnittsweise escapen
        $plain = self::plainText($html);
        $findings = self::textFindings($title, $plain, $html);
        $reasons = self::textIssueReasons($plain);
        $patterns = [];
        if (in_array('viel GROSSSCHRIFT', $reasons, true)) {
            $patterns[] = '/\b\p{Lu}{4,}\b/u';
        }
        $patterns[] = '/!{2,}/';
        $patterns[] = self::PLACEHOLDER_PATTERN;
        $patterns[] = self::HTML_REMNANT_PATTERN;
        if ($title !== '' && isset($findings['title_only'])) {
            $patterns[] = '/' . preg_quote($title, '/') . '/iu';
        }
        $ranges = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [$match, $offset]) {
                    $ranges[] = [$offset, $offset + strlen($match)];
                }
            }
        }
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $out = '';
        $pos = 0;
        foreach ($ranges as [$start, $end]) {
            if ($start < $pos) {
                continue; // ueberlappt eine schon markierte Stelle
            }
            $out .= htmlspecialchars(substr($text, $pos, $start - $pos), ENT_QUOTES, 'UTF-8')
                . '<mark>' . htmlspecialchars(substr($text, $start, $end - $start), ENT_QUOTES, 'UTF-8') . '</mark>';
            $pos = $end;
        }
        $out .= htmlspecialchars(substr($text, $pos), ENT_QUOTES, 'UTF-8');

        return [
            'title' => $title,
            'html' => nl2br($out),
            'length' => mb_strlen($plain),
            'paragraphs' => preg_match_all('#<(p|br|li|h[1-6])\b#i', $html) ?: 0,
            'language' => self::LANGUAGE_NAMES[self::language($plain) ?? ''] ?? null,
        ];
    }

    /** Gruppe einer Pruefung (basic/seo/text). */
    public static function groupOf(string $check): string
    {
        foreach (self::GROUPS as $group => [, , $checks]) {
            if (in_array($check, $checks, true)) {
                return $group;
            }
        }

        return 'basic';
    }

    /** @return list<array<string, mixed>> */
    private function download(string $url): array
    {
        $response = (new HttpClient())->request('GET', $url);
        if (!$response->isSuccess()) {
            throw new \RuntimeException('Ergebnis der Massenabfrage nicht abrufbar (HTTP ' . $response->statusCode . ').');
        }
        $lines = [];
        foreach (preg_split('/\r?\n/', $response->body) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }

        return $lines;
    }

    private function shopifyConnector(string $shop): GenericHttpConnector
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.shop = :shop AND t.type_key = 'shopify' ORDER BY c.status = 'active' DESC, c.id LIMIT 1"
        );
        $stmt->execute(['shop' => $shop]);
        $connectionId = $stmt->fetchColumn();
        if ($connectionId === false) {
            throw new \RuntimeException('Keine Shopify-Verbindung für diesen Shop.');
        }
        $connector = $this->factory->forConnection((int) $connectionId);
        if (!$connector instanceof GenericHttpConnector) {
            throw new \RuntimeException('Shopify-Verbindung unbekannten Typs.');
        }

        return $connector;
    }
}
