<?php

declare(strict_types=1);

namespace Connect\Setup;

use PDO;

/**
 * Rechtstexte (Datenschutz, AVV, Haftungshinweis) mit Versionen und Zustimmungen der
 * Shops (Migration 0076). Gepflegt unter admin/legal_texts.php, zugestimmt im ersten
 * Fenster app/welcome.php.
 *
 * Nur die neueste VEROEFFENTLICHTE Version einer Art muss zugestimmt werden. Eine neue
 * Version (Text geaendert und als neue Version veroeffentlicht) fragt alle Shops erneut.
 * Eine "Korrektur" aendert die aktuelle Version ohne neue Zustimmung (z.B. Tippfehler).
 */
final class LegalTexts
{
    /** Art => [Kachel-Titel, Zustimmungs-Satz] */
    public const DOCS = [
        // Nutzungsbedingungen enthalten die allgemeine Haftungsregel. Sind sie veroeffentlicht,
        // braucht es den eigenen Text "liability" nicht mehr (unveroeffentlicht = nicht abgefragt).
        'terms' => ['Nutzungsbedingungen', 'Ich akzeptiere die Nutzungsbedingungen.'],
        'privacy' => ['Datenschutzerklärung', 'Ich habe die Datenschutzerklärung gelesen und akzeptiere sie.'],
        'dpa' => ['Auftragsverarbeitung (AVV)', 'Ich akzeptiere die Vereinbarung zur Auftragsverarbeitung.'],
        // "zustimmen" statt "zur Kenntnis nehmen": eine Haftungsbegrenzung wirkt als vereinbarter Vertragsbestandteil
        'liability' => ['Verantwortung und Haftung für Belege', 'Ich stimme den Bedingungen zu Verantwortung und Haftung für erstellte Belege zu.'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Neueste veroeffentlichte Version je Art (fehlende Arten fehlen im Ergebnis).
     *
     * @return array<string, array{id: int, doc_key: string, version: int, title: string, summary: ?string, body: string, published_at: string}>
     */
    public function published(): array
    {
        $rows = $this->pdo->query(
            'SELECT t.* FROM connect_legal_texts t
             JOIN (SELECT doc_key, MAX(version) AS v FROM connect_legal_texts WHERE published_at IS NOT NULL GROUP BY doc_key) cur
               ON cur.doc_key = t.doc_key AND cur.v = t.version'
        )->fetchAll();

        $result = [];
        foreach (array_keys(self::DOCS) as $docKey) {
            foreach ($rows as $row) {
                if ($row['doc_key'] === $docKey) {
                    $row['id'] = (int) $row['id'];
                    $row['version'] = (int) $row['version'];
                    $result[$docKey] = $row;
                }
            }
        }

        return $result;
    }

    /**
     * Veroeffentlichte Texte, deren aktuelle Version der Shop noch nicht zugestimmt hat.
     *
     * @return array<string, array<string, mixed>>
     */
    public function pendingForShop(string $shop): array
    {
        $published = $this->published();
        if ($published === []) {
            return [];
        }
        // Zustimmungen eines frueher geloeschten Shops (Migration 0077) zaehlen nicht -- Neuinstallation = neue Zustimmung
        try {
            $stmt = $this->pdo->prepare('SELECT DISTINCT doc_key, version FROM connect_shop_consents WHERE shop = :shop AND shop_deleted_at IS NULL');
            $stmt->execute(['shop' => $shop]);
        } catch (\PDOException) {
            $stmt = $this->pdo->prepare('SELECT DISTINCT doc_key, version FROM connect_shop_consents WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);
        }
        $accepted = [];
        foreach ($stmt->fetchAll() as $row) {
            $accepted[$row['doc_key'] . ':' . $row['version']] = true;
        }

        return array_filter($published, static fn (array $doc): bool => !isset($accepted[$doc['doc_key'] . ':' . $doc['version']]));
    }

    /**
     * Shop geloescht (shop/redact oder Admin): Nachweise bleiben, werden aber als geloescht
     * markiert und von der Bereinigung nach der Aufbewahrungsfrist entfernt (Migration 0077).
     * Fehlt die Spalte noch, passiert nichts (Nachweise bleiben unveraendert).
     */
    public static function markShopDeleted(PDO $pdo, string $shop): int
    {
        try {
            $stmt = $pdo->prepare('UPDATE connect_shop_consents SET shop_deleted_at = CURRENT_TIMESTAMP WHERE shop = :shop AND shop_deleted_at IS NULL');
            $stmt->execute(['shop' => $shop]);

            return $stmt->rowCount();
        } catch (\PDOException) {
            return 0;
        }
    }

    public function accept(string $shop, string $docKey, int $version, ?string $ip, ?string $userAgent): void
    {
        $this->pdo->prepare(
            'INSERT INTO connect_shop_consents (shop, doc_key, version, ip, user_agent) VALUES (:shop, :doc, :version, :ip, :ua)'
        )->execute([
            'shop' => $shop,
            'doc' => $docKey,
            'version' => $version,
            'ip' => $ip !== null ? mb_substr($ip, 0, 64) : null,
            'ua' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ]);
    }

    /**
     * Neueste Version je Art, veroeffentlicht oder Entwurf (fuer den Admin-Editor).
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function latestForEditing(): array
    {
        $result = [];
        foreach (array_keys(self::DOCS) as $docKey) {
            $stmt = $this->pdo->prepare('SELECT * FROM connect_legal_texts WHERE doc_key = :doc ORDER BY version DESC LIMIT 1');
            $stmt->execute(['doc' => $docKey]);
            $result[$docKey] = $stmt->fetch() ?: null;
        }

        return $result;
    }

    /**
     * Speichern aus dem Admin-Editor.
     *   draft      -- Entwurf: aendert den bestehenden Entwurf bzw. legt einen neuen an
     *   publish    -- neue Version veroeffentlichen (alle Shops stimmen neu zu)
     *   correction -- aktuelle veroeffentlichte Version korrigieren, ohne neue Zustimmung
     *
     * @return int Version, die gespeichert wurde
     */
    public function save(string $docKey, string $mode, string $title, ?string $summary, string $body, ?string $editor): int
    {
        if (!isset(self::DOCS[$docKey])) {
            throw new \InvalidArgumentException('Unbekannte Art.');
        }
        $latest = $this->latestForEditing()[$docKey];
        $values = ['title' => mb_substr($title, 0, 190), 'summary' => $summary !== null ? mb_substr($summary, 0, 500) : null, 'body' => $body, 'editor' => $editor];

        if ($mode === 'correction') {
            if ($latest === null || $latest['published_at'] === null) {
                throw new \RuntimeException('Es gibt noch keine veröffentlichte Version, die korrigiert werden könnte.');
            }
            $this->pdo->prepare('UPDATE connect_legal_texts SET title = :title, summary = :summary, body = :body, updated_by = :editor WHERE id = :id')
                ->execute($values + ['id' => (int) $latest['id']]);

            return (int) $latest['version'];
        }

        // Offener Entwurf wird ueberschrieben bzw. veroeffentlicht, sonst neue Version
        if ($latest !== null && $latest['published_at'] === null) {
            $this->pdo->prepare(
                'UPDATE connect_legal_texts SET title = :title, summary = :summary, body = :body, updated_by = :editor, published_at = '
                . ($mode === 'publish' ? 'CURRENT_TIMESTAMP' : 'NULL') . ' WHERE id = :id'
            )->execute($values + ['id' => (int) $latest['id']]);

            return (int) $latest['version'];
        }

        $version = $latest !== null ? (int) $latest['version'] + 1 : 1;
        $this->pdo->prepare(
            'INSERT INTO connect_legal_texts (doc_key, version, title, summary, body, updated_by, published_at)
             VALUES (:doc, :version, :title, :summary, :body, :editor, ' . ($mode === 'publish' ? 'CURRENT_TIMESTAMP' : 'NULL') . ')'
        )->execute($values + ['doc' => $docKey, 'version' => $version]);

        return $version;
    }

    /**
     * Wie viele Shops der aktuellen Version zugestimmt haben (fuer die Admin-Uebersicht).
     *
     * @return array<string, int>
     */
    public function acceptedCounts(): array
    {
        $counts = [];
        foreach ($this->published() as $docKey => $doc) {
            $stmt = $this->pdo->prepare('SELECT COUNT(DISTINCT shop) FROM connect_shop_consents WHERE doc_key = :doc AND version = :v');
            $stmt->execute(['doc' => $docKey, 'v' => $doc['version']]);
            $counts[$docKey] = (int) $stmt->fetchColumn();
        }

        return $counts;
    }

    /**
     * Text sicher als HTML: Leerzeile = Absatz, Zeilenumbruch = <br>, Zeile mit "# " = Ueberschrift,
     * Adressen (https://...) werden zu Links. Kein freies HTML.
     */
    public static function render(string $body): string
    {
        $html = '';
        foreach (preg_split('/\R{2,}/', trim($body)) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            // Ueberschrift: erste Zeile mit "# " bzw. "## ", der Rest des Blocks ist normaler Text
            if (preg_match('/^(#{1,2}) (.+?)(?:\R(.*))?$/s', $block, $m)) {
                $tag = $m[1] === '#' ? 'h4' : 'h5';
                $html .= "<{$tag}>" . htmlspecialchars(trim($m[2])) . "</{$tag}>";
                if (trim($m[3] ?? '') === '') {
                    continue;
                }
                $block = trim($m[3]);
            }
            $escaped = nl2br(htmlspecialchars($block));
            $escaped = (string) preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', $escaped);
            $html .= '<p>' . $escaped . '</p>';
        }

        return $html;
    }
}
