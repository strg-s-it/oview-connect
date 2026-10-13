<?php

declare(strict_types=1);

namespace Connect\Setup;

use PDO;

/**
 * Einrichtungs-Assistent fuer den Merchant (Dashboard, app/_dashboard_body.php):
 * fuehrt Schritt fuer Schritt durch die Einstellungen (app/_sync_mappings_body.php),
 * wie das Onboarding der alten Welt (dashboard.php: Vergleichswert, Lagerbestaende
 * importieren aus, Lager-/Rechnungsabgleich, Varianten).
 *
 * Ein Schritt gilt als erledigt, wenn
 *   - sein Wert eindeutig gesetzt ist (z.B. Software verbunden, Netto/Brutto gewaehlt), oder
 *   - der Merchant ihn bestaetigt hat: Karte in den Einstellungen gespeichert, "Passt so"
 *     im Assistenten oder Umzug aus der alten Welt (connect_shops.onboarding_state,
 *     Migration 0075). Noetig, weil Vergleichswert/Lagerrichtung Standardwerte haben.
 *
 * Neue Einstellung, die der Merchant pruefen soll: Schritt in steps() ergaenzen und
 * in SAVE_ACTIONS die Speicher-Aktion der Karte eintragen.
 */
final class SetupAssistant
{
    /** Speicher-Aktion in den Einstellungen => Schritte, die sie bestaetigt */
    public const SAVE_ACTIONS = [
        'save_comparison_key' => ['comparison'],
        'save_stock_settings' => ['stock_direction', 'stock_sync'],
        'save_price_tax_mode' => ['prices'],
        'refresh_price_tax_mode_from_shopify' => ['prices'],
        'save_invoice_settings' => ['invoice', 'invoice_mail'],
        'save_variant_comparison_key' => ['variants'],
        'save_default_tax_rate' => ['tax_rate'],
    ];

    /** Alle bestaetigbaren Schritte (fuer den Umzug aus der alten Welt) */
    public const CONFIRMABLE = ['comparison', 'stock_direction', 'stock_sync', 'prices', 'invoice', 'invoice_mail', 'variants', 'tax_rate'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Schritte als bestaetigt markieren. Fehlt die Spalte noch (Migration 0075), passiert nichts.
     *
     * @param array<int, string> $steps
     */
    public function confirm(string $shop, array $steps): void
    {
        $steps = array_values(array_intersect($steps, self::CONFIRMABLE));
        if ($steps === []) {
            return;
        }
        try {
            $state = $this->state($shop);
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Vienna')))->format('Y-m-d H:i:s');
            foreach ($steps as $step) {
                $state[$step] ??= $now;
            }
            $this->pdo->prepare('UPDATE connect_shops SET onboarding_state = :state WHERE shop = :shop')
                ->execute(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'shop' => $shop]);
        } catch (\Throwable) {
            // Assistent ist Komfort -- ein Fehler hier darf das Speichern der Einstellung nicht stoeren
        }
    }

    /**
     * @return array<string, string> Schritt => Zeitpunkt der Bestaetigung
     */
    private function state(string $shop): array
    {
        $stmt = $this->pdo->prepare('SELECT onboarding_state FROM connect_shops WHERE shop = :shop');
        $stmt->execute(['shop' => $shop]);
        $raw = $stmt->fetchColumn();
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{available: bool, steps: array<int, array{key: string, number: ?int, title: string, hint: string, value: string, done: bool, optional: bool, confirmable: bool, anchor: string}>, required: int, required_done: int, data: array<int, string>}
     */
    public function forShop(string $shop): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM connect_shops WHERE shop = :shop');
        $stmt->execute(['shop' => $shop]);
        $s = $stmt->fetch();
        if ($s === false) {
            return ['available' => false, 'steps' => [], 'required' => 0, 'required_done' => 0, 'data' => []];
        }

        $available = array_key_exists('onboarding_state', $s);
        $state = $available && is_string($s['onboarding_state']) && $s['onboarding_state'] !== '' ? (json_decode($s['onboarding_state'], true) ?: []) : [];
        $confirmed = static fn (string $key): bool => isset($state[$key]);

        // Zielsystem (Software) und Shopify-Connection
        $target = null;
        if ($s['target_connection_id'] !== null) {
            $t = $this->pdo->prepare(
                'SELECT c.id, c.label, c.status, t.name AS type_name FROM connect_connections c
                 JOIN connect_connector_types t ON t.id = c.connector_type_id WHERE c.id = :id'
            );
            $t->execute(['id' => (int) $s['target_connection_id']]);
            $target = $t->fetch() ?: null;
        }
        $targetName = $target !== null ? (string) $target['type_name'] : 'Zielsystem';
        $targetOk = $target !== null && $target['status'] === 'active';

        $compareLabels = ['title' => 'Produktname', 'sku' => 'SKU', 'barcode' => 'Barcode'];
        $direction = (string) ($s['stock_sync_direction'] ?? 'shopify_to_target');
        $locationName = $this->locationName($shop, $s['stock_primary_location_id'] ?? null);
        $needsLocation = true; // Shopify-Bestand wird je Standort gelesen, in beide Richtungen
        $priceMode = $s['shopify_prices_include_tax'] ?? null;
        $invoiceOn = (int) ($s['invoice_creation_enabled'] ?? 0) === 1;
        $suppress = (int) ($s['invoice_suppress_and_adjust_stock'] ?? 0) === 1;

        $steps = [];
        $steps[] = $this->step('target', 'Software verbinden',
            'Mit welcher Software soll Shopify abgeglichen werden (z. B. HelloCash)?',
            $target === null ? 'nicht verbunden' : $targetName . ($targetOk ? ' · verbunden' : ' · Verbindung prüfen'),
            $targetOk, false, false, 'setup-target');
        $steps[] = $this->step('comparison', 'Produkt-Vergleichswert festlegen (Empfehlung: SKU)',
            'Woran erkennt oView, dass ein Shopify-Produkt und ein Artikel in ' . $targetName . ' zusammengehören?',
            $compareLabels[(string) ($s['comparison_key'] ?? 'sku')] ?? (string) $s['comparison_key'],
            $confirmed('comparison'), false, true, 'setup-comparison');
        $steps[] = $this->step('stock_direction', 'Wo pflegst du deinen Lagerbestand?',
            'Das führende System gibt den Bestand vor, das andere wird angepasst.'
                . ' Abgeglichen wird der Bestand eines Shopify-Standorts.',
            ($direction === 'target_to_shopify' ? 'in ' . $targetName : 'in Shopify') . ($locationName !== null ? ' · Standort ' . $locationName : ($needsLocation ? ' · Standort fehlt' : '')),
            $confirmed('stock_direction') && (!$needsLocation || $locationName !== null), false, !$needsLocation || $locationName !== null, 'setup-stock');
        $steps[] = $this->step('stock_sync', 'Automatischen Lagerstandsabgleich aktivieren',
            'Gleicht den Lagerbestand stündlich automatisch ab.',
            (int) ($s['stock_sync_enabled'] ?? 0) === 1 ? 'aktiv' : 'aus',
            (int) ($s['stock_sync_enabled'] ?? 0) === 1 || $confirmed('stock_sync'), false, true, 'setup-stock');
        $steps[] = $this->step('invoice', 'Automatischen Rechnungsabgleich aktivieren',
            'Legt für jede bezahlte Bestellung automatisch eine Rechnung in ' . $targetName . ' an.',
            $suppress ? 'nur Lagerbestand anpassen' : ($invoiceOn ? 'aktiv' . ((int) ($s['invoice_pos_only'] ?? 0) === 1 ? ' · nur Kasse' : '') : 'aus'),
            $invoiceOn || $suppress || $confirmed('invoice'), false, true, 'setup-invoice');
        $steps[] = $this->step('prices', 'Preise in Shopify: Netto oder Brutto?',
            'Wichtig für Rechnungen und Produktimport. „Aus Shopify übernehmen“ liest die Einstellung deines Shops.',
            $priceMode === null ? 'nicht festgelegt' : ((int) $priceMode === 1 ? 'Brutto (inkl. MwSt.)' : 'Netto'),
            $priceMode !== null, false, false, 'setup-prices');
        $steps[] = $this->step('invoice_mail', 'Rechnung per E-Mail an Kunden senden',
            'Optional: das PDF der Rechnung automatisch an die E-Mail-Adresse der Bestellung schicken.',
            (int) ($s['invoice_mail_enabled'] ?? 0) === 1 ? 'aktiv' : 'aus',
            (int) ($s['invoice_mail_enabled'] ?? 0) === 1 || $confirmed('invoice_mail'), true, true, 'setup-invoice');
        $steps[] = $this->step('tax_rate', 'Standard-Steuersatz',
            'Optional: Vorbelegung beim Import neuer Produkte.',
            ($s['default_tax_rate'] ?? null) !== null ? rtrim(rtrim((string) $s['default_tax_rate'], '0'), '.') . ' %' : 'keiner',
            ($s['default_tax_rate'] ?? null) !== null || $confirmed('tax_rate'), true, true, 'setup-prices');
        $steps[] = $this->step('variants', 'Varianten-Vergleichswert definieren',
            'Optional: neue Artikel aus ' . $targetName . ' als Variante eines bestehenden Shopify-Produkts importieren.',
            ($s['variant_comparison_key'] ?? null) !== null ? ($compareLabels[(string) $s['variant_comparison_key']] ?? (string) $s['variant_comparison_key']) : 'aus',
            ($s['variant_comparison_key'] ?? null) !== null || $confirmed('variants'), true, true, 'setup-variants');
        // Abo: nur "nicht erledigt", wenn Shopify sicher kein aktives Abo meldet (plan_status = none).
        // Unbekannt (noch nie abgefragt, Migration 0079 fehlt) zaehlt als erledigt -- kein falscher Alarm.
        $hasPlan = ($s['plan_status'] ?? null) !== 'none';
        $steps[] = $this->step('plan', 'Abo bestätigen',
            'Das erste Abo kannst du ' . \Connect\Billing\Plans::TRIAL_DAYS . ' Tage kostenlos testen. Die Bestätigung erfolgt in Shopify.',
            $hasPlan ? 'aktiv' : 'noch nicht bestätigt',
            $hasPlan, true, false, 'setup-plan');

        $number = 0;
        $required = 0;
        $requiredDone = 0;
        foreach ($steps as $i => $step) {
            if (!$step['optional']) {
                $steps[$i]['number'] = ++$number;
                $required++;
                $requiredDone += $step['done'] ? 1 : 0;
            }
        }

        return [
            'available' => $available,
            'steps' => $steps,
            'required' => $required,
            'required_done' => $requiredDone,
            'data' => $this->dataStatus($shop, $target, $targetName),
        ];
    }

    /**
     * @return array{key: string, number: ?int, title: string, hint: string, value: string, done: bool, optional: bool, confirmable: bool, anchor: string}
     */
    private function step(string $key, string $title, string $hint, string $value, bool $done, bool $optional, bool $confirmable, string $anchor): array
    {
        return ['key' => $key, 'number' => null, 'title' => $title, 'hint' => $hint, 'value' => $value, 'done' => $done,
            'optional' => $optional, 'confirmable' => $confirmable && in_array($key, self::CONFIRMABLE, true), 'anchor' => $anchor];
    }

    private function locationName(string $shop, mixed $locationId): ?string
    {
        if ($locationId === null || $locationId === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT r.data FROM connect_object_records r
             JOIN connect_connections c ON c.id = r.connection_id
             JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.shop = :shop AND t.type_key = 'shopify' AND r.object_key = 'location' AND r.external_id = :id LIMIT 1"
        );
        $stmt->execute(['shop' => $shop, 'id' => (string) $locationId]);
        $data = json_decode((string) $stmt->fetchColumn(), true);

        return is_array($data) && ($data['name'] ?? '') !== '' ? (string) $data['name'] : 'gewählt';
    }

    /**
     * Info-Zeilen: wie viele Produkte schon geladen sind (Abgleich startet erst mit Daten auf beiden Seiten).
     *
     * @param array<string, mixed>|null $target
     * @return array<int, string>
     */
    private function dataStatus(string $shop, ?array $target, string $targetName): array
    {
        $lines = [];
        $shopify = $this->pdo->prepare(
            "SELECT COUNT(*) FROM connect_object_records r
             JOIN connect_connections c ON c.id = r.connection_id
             JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.shop = :shop AND t.type_key = 'shopify' AND r.object_key = 'product' AND r.status = 'active'"
        );
        $shopify->execute(['shop' => $shop]);
        $lines[] = 'Shopify: ' . (int) $shopify->fetchColumn() . ' Produkte/Varianten geladen';

        if ($target !== null) {
            $t = $this->pdo->prepare(
                "SELECT o.label, COUNT(*) AS n FROM connect_object_records r
                 JOIN connect_connections c ON c.id = r.connection_id
                 JOIN connect_objects o ON o.connector_type_id = c.connector_type_id AND o.object_key = r.object_key
                 WHERE r.connection_id = :id AND r.status = 'active'
                 GROUP BY o.label ORDER BY n DESC LIMIT 1"
            );
            $t->execute(['id' => (int) $target['id']]);
            $row = $t->fetch();
            $lines[] = $targetName . ': ' . ($row !== false ? (int) $row['n'] . ' ' . $row['label'] : 'noch keine Daten') . ' geladen';
        }

        return $lines;
    }
}
