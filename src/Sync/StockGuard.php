<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Config;
use Connect\Notify\Notifier;
use PDO;

/**
 * Sicherheitsbremse fuer den Lagerabgleich (Migration 0080, Nutzer-Entscheidung 2026-10-08).
 *
 * Bremst NICHT bei vielen Aenderungen an sich -- die sind oft gewollt (Wareneingang,
 * Inventur, nach einem Ausfall) und eine Mail wuerde nur verunsichern. Gebremst wird nur
 * bei klaren Fehlerbildern der fuehrenden Quelle (typisch: halb leere Liste oder lauter
 * Nullen geliefert), gemessen VOR der 150er-Grenze je Lauf:
 *   - Quelle mehr als 20 % kuerzer als beim letzten freigegebenen Lauf
 *   - mind. 25 Artikel UND mind. 10 % der Paare fielen von Bestand > 0 auf 0
 *   - Anteil Nullen in der Quelle mind. 15 Prozentpunkte hoeher als beim letzten Lauf
 *
 * Ablauf: 1. Treffer = Lauf auslassen (oft nur ein Aussetzer), keine Mail. 2. Treffer in
 * Folge = Abgleich pausieren, Mail NUR an den Admin. Der Merchant sieht in der Uebersicht
 * einen ruhigen Hinweis (Connect\Setup\MerchantOverview). Freigabe im Admin: "Aenderungen
 * sind gewollt" fuehrt den naechsten Lauf ohne Pruefung aus und setzt den Vergleichsstand neu.
 *
 * Ausserdem: 3 fehlgeschlagene Laeufe in Folge (z.B. Quelle nicht erreichbar) = Mail an den
 * Admin, Entwarnung, sobald es wieder klappt.
 */
final class StockGuard
{
    private const MAX_COUNT_DROP = 0.20;
    private const MIN_TO_ZERO = 25;
    private const MIN_TO_ZERO_SHARE = 0.10;
    private const MAX_ZERO_SHARE_JUMP = 15.0; // Prozentpunkte
    private const FAIL_STREAK_MAIL = 3;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Zustand lesen; null = Migration 0080 fehlt (dann keine Bremse).
     *
     * @return array<string, mixed>|null
     */
    public function load(string $shop): ?array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT stock_guard FROM connect_shops WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);
            $raw = $stmt->fetchColumn();
        } catch (\PDOException) {
            return null;
        }
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    public function save(string $shop, array $state): void
    {
        try {
            $this->pdo->prepare('UPDATE connect_shops SET stock_guard = :state WHERE shop = :shop')
                ->execute(['state' => json_encode($state, JSON_UNESCAPED_UNICODE), 'shop' => $shop]);
        } catch (\PDOException) {
            // Migration 0080 fehlt
        }
    }

    public static function isPaused(?array $state): bool
    {
        return is_array($state) && ($state['state'] ?? null) === 'paused';
    }

    /**
     * Pruefen, ob der geplante Lauf verdaechtig ist.
     *
     * @param array<string, mixed> $state aus load()
     * @param array{source_count: int, zero_share: ?float, pairs: int, to_zero: int} $plan
     * @return list<string> Gruende (leer = unauffaellig)
     */
    public function evaluate(array $state, array $plan): array
    {
        $reasons = [];
        $lastCount = (int) ($state['last_count'] ?? 0);
        if ($lastCount > 0 && $plan['source_count'] < $lastCount * (1 - self::MAX_COUNT_DROP)) {
            $reasons[] = "Die Quelle liefert nur noch {$plan['source_count']} statt bisher {$lastCount} Artikel ("
                . (int) round((1 - $plan['source_count'] / $lastCount) * 100) . ' % weniger).';
        }
        if ($plan['to_zero'] >= self::MIN_TO_ZERO && $plan['pairs'] > 0 && $plan['to_zero'] >= $plan['pairs'] * self::MIN_TO_ZERO_SHARE) {
            $reasons[] = "{$plan['to_zero']} Artikel mit Bestand würden auf 0 fallen ("
                . (int) round($plan['to_zero'] / $plan['pairs'] * 100) . ' % der zugeordneten Artikel).';
        }
        $lastShare = isset($state['last_zero_share']) ? (float) $state['last_zero_share'] : null;
        if ($lastShare !== null && $plan['zero_share'] !== null && $plan['zero_share'] - $lastShare >= self::MAX_ZERO_SHARE_JUMP) {
            $reasons[] = 'Der Anteil der Artikel mit Bestand 0 in der Quelle ist von '
                . number_format($lastShare, 0) . ' % auf ' . number_format($plan['zero_share'], 0) . ' % gestiegen.';
        }

        return $reasons;
    }

    /**
     * Treffer verbuchen: beim ersten nur merken, beim zweiten in Folge pausieren + Admin-Mail.
     *
     * @param array<string, mixed> $state
     * @param list<string> $reasons
     * @param list<string> $examples z.B. "Ring Gold (123): 5 -> 0"
     * @return string Meldung fuer das Lauf-Protokoll
     */
    public function hit(string $shop, array $state, array $reasons, array $examples): string
    {
        $state['reason'] = $reasons;
        $state['examples'] = array_slice($examples, 0, 10);
        $state['at'] = date('Y-m-d H:i:s');

        if (($state['state'] ?? null) !== 'warned') {
            $state['state'] = 'warned';
            $this->save($shop, $state);

            return 'Sicherheitsbremse: Lauf ausgelassen, nichts geschrieben (1. Auffälligkeit, beim nächsten Lauf wird erneut geprüft). ' . implode(' ', $reasons);
        }

        $state['state'] = 'paused';
        $this->save($shop, $state);
        $this->mailAdmin(
            $shop,
            "oView Connect: Lagerabgleich angehalten – {$shop}",
            "Der Lagerabgleich von {$shop} wurde angehalten, weil zwei Läufe hintereinander auffällige Daten der führenden Quelle zeigten. Es wurde nichts geschrieben.\n\n"
                . 'Gründe:' . "\n- " . implode("\n- ", $reasons) . "\n\n"
                . ($examples !== [] ? "Beispiele (Shopify-/Zielbestand -> geplanter Wert):\n- " . implode("\n- ", array_slice($examples, 0, 10)) . "\n\n" : '')
                . "Prüfen und freigeben: " . $this->adminUrl($shop) . "\n"
                . "Sind die Änderungen gewollt (z. B. Inventur), dort „Änderungen sind gewollt – ausführen“ klicken. Sonst die Ursache in der Quelle beheben und danach freigeben."
        );

        return 'Sicherheitsbremse: Lagerabgleich angehalten (2. Auffälligkeit in Folge), Admin benachrichtigt. ' . implode(' ', $reasons);
    }

    /**
     * Unauffaelliger bzw. freigegebener Lauf: Bremse loesen, Vergleichsstand fuer die
     * naechste Pruefung setzen.
     *
     * @param array<string, mixed> $state
     * @param array{source_count: int, zero_share: ?float, pairs: int, to_zero: int} $plan
     */
    public function pass(string $shop, array $state, array $plan): void
    {
        unset($state['state'], $state['reason'], $state['examples'], $state['release_once']);
        $state['last_count'] = $plan['source_count'];
        $state['last_zero_share'] = $plan['zero_share'];
        $state['baseline_at'] = date('Y-m-d H:i:s');
        $this->save($shop, $state);
    }

    /** Admin: "Aenderungen sind gewollt" -- naechster Lauf ohne Pruefung, Abgleich laeuft weiter. */
    public function release(string $shop): void
    {
        $state = $this->load($shop) ?? [];
        $state['release_once'] = true;
        $state['state'] = 'released';
        $this->save($shop, $state);
    }

    /**
     * Ergebnis eines Laufs fuer die Ausfall-Meldung: error zaehlt hoch, success/partial setzt
     * zurueck (mit Entwarnung, falls gemeldet), skipped aendert nichts.
     */
    public function recordOutcome(string $shop, string $status, string $message): void
    {
        if ($status === 'skipped') {
            return;
        }
        $state = $this->load($shop);
        if ($state === null) {
            return;
        }

        if ($status === 'error') {
            $state['fail_streak'] = (int) ($state['fail_streak'] ?? 0) + 1;
            $state['last_error'] = mb_substr($message, 0, 500);
            if ($state['fail_streak'] === self::FAIL_STREAK_MAIL && empty($state['fail_notified'])) {
                $state['fail_notified'] = true;
                $this->mailAdmin(
                    $shop,
                    "oView Connect: Lagerabgleich schlägt fehl – {$shop}",
                    "Der Lagerabgleich von {$shop} ist " . self::FAIL_STREAK_MAIL . " Mal hintereinander fehlgeschlagen. Es wurde dabei nichts geschrieben, die Bestände in Shopify veralten aber.\n\n"
                        . "Letzter Fehler:\n{$message}\n\n"
                        . "Typische Ursachen: Quelle nicht erreichbar (Server, IP-Freigabe), Zugangsdaten abgelaufen.\n"
                        . 'Jobs ansehen: ' . $this->adminUrl($shop) . "\n\nSobald es wieder klappt, kommt eine Entwarnung."
                );
            }
        } else {
            if (!empty($state['fail_notified'])) {
                $this->mailAdmin(
                    $shop,
                    "oView Connect: Lagerabgleich läuft wieder – {$shop}",
                    "Der Lagerabgleich von {$shop} läuft wieder (nach " . (int) ($state['fail_streak'] ?? 0) . ' fehlgeschlagenen Läufen).'
                );
            }
            $state['fail_streak'] = 0;
            unset($state['fail_notified'], $state['last_error']);
        }
        $this->save($shop, $state);
    }

    private function mailAdmin(string $shop, string $subject, string $text): void
    {
        try {
            $notifier = new Notifier($this->pdo, Config::load());
            $notifier->send($notifier->adminEmail(), $subject, $text);
        } catch (\Throwable $e) {
            error_log('[connect] StockGuard-Mail fuer ' . $shop . ' fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function adminUrl(string $shop): string
    {
        try {
            $base = rtrim(Config::load()->optional('CONNECT_APP_URL', 'https://connect.oview.at'), '/');
        } catch (\Throwable) {
            $base = 'https://connect.oview.at';
        }

        return $base . '/admin/sync_mappings.php?shop=' . rawurlencode($shop) . '#stock-guard';
    }
}
