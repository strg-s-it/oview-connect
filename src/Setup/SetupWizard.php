<?php

declare(strict_types=1);

namespace Connect\Setup;

use Connect\Sync\MatchCounter;
use Connect\Sync\ObjectCache;
use PDO;

/**
 * Einrichtung fuer neue Merchants (app/welcome.php, Migration 0078): eine einfache Frage
 * pro Fenster, erst nach "oView starten" geht es ins Dashboard.
 *
 * Die Antworten werden bis zum Start nur gemerkt (connect_shops.onboarding_state, Schluessel
 * "wizard") und erst in complete() wirksam -- neue Shops sind sofort live, ein halb
 * beantworteter Lagerabgleich mit Standardrichtung koennte sonst schon Bestaende
 * ueberschreiben. Ausnahme: die Software wird sofort verbunden (Test + Datenabruf), damit
 * die Trefferzahlen beim Vergleichswert schon Daten haben.
 *
 * Schritte: welcome, software, connect, compare, stock, stock_lead (nur bei Lagerabgleich),
 * invoice, prices (nur bei Rechnungen), done.
 */
final class SetupWizard
{
    public const STEPS = ['welcome', 'software', 'connect', 'compare', 'stock', 'stock_lead', 'invoice', 'prices', 'done'];

    /** Fortschrittsanzeige: Schritt => Abschnitt */
    public const PHASES = [
        'welcome' => 'Willkommen', 'software' => 'Verbinden', 'connect' => 'Verbinden', 'compare' => 'Verbinden',
        'stock' => 'Lagerbestand', 'stock_lead' => 'Lagerbestand', 'invoice' => 'Rechnungen', 'prices' => 'Rechnungen', 'done' => 'Fertig',
    ];

    public const COMPARE_LABELS = ['sku' => 'Artikelnummer (SKU)', 'barcode' => 'Barcode (EAN)', 'title' => 'Produktname'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Migration 0078 eingespielt? Ohne sie gibt es keine Einrichtung (alte Sperre nur ohne Software). */
    public function isAvailable(): bool
    {
        try {
            $this->pdo->query('SELECT setup_completed_at, onboarding_state FROM connect_shops LIMIT 0');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isCompleted(string $shop): bool
    {
        $stmt = $this->pdo->prepare('SELECT setup_completed_at FROM connect_shops WHERE shop = :shop');
        $stmt->execute(['shop' => $shop]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null;
    }

    /**
     * Shop-Zeile plus Zielsystem (Name, Typ, Status).
     *
     * @return array<string, mixed>
     */
    public function shop(string $shop): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, c.status AS target_status, c.connector_type_id AS target_type_id, t.name AS target_name
             FROM connect_shops s
             LEFT JOIN connect_connections c ON c.id = s.target_connection_id
             LEFT JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE s.shop = :shop'
        );
        $stmt->execute(['shop' => $shop]);

        return $stmt->fetch() ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    public function answers(string $shop): array
    {
        $state = $this->state($shop);

        return is_array($state['wizard'] ?? null) ? $state['wizard'] : [];
    }

    /**
     * @param array<string, mixed> $values
     */
    public function remember(string $shop, array $values): void
    {
        $state = $this->state($shop);
        $state['wizard'] = array_merge(is_array($state['wizard'] ?? null) ? $state['wizard'] : [], $values);
        $this->pdo->prepare('UPDATE connect_shops SET onboarding_state = :state WHERE shop = :shop')
            ->execute(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'shop' => $shop]);
    }

    /**
     * Einrichtung erneut durchgehen (eingerichteter Shop): Antworten mit den aktuell
     * wirksamen Einstellungen vorbelegen. Geaendert wird erst beim Abschluss.
     */
    public function startRerun(string $shop): void
    {
        $s = $this->shop($shop);
        $invoiceOn = (int) ($s['invoice_creation_enabled'] ?? 0) === 1;
        $answers = [
            'welcome' => date('c'),
            'compare' => (string) ($s['comparison_key'] ?? 'sku'),
            'stock' => (int) ($s['stock_sync_enabled'] ?? 0) === 1 ? 'yes' : 'no',
            'lead' => ($s['stock_sync_direction'] ?? '') === 'shopify_to_target' ? 'shopify' : 'target',
            'location' => (string) ($s['stock_primary_location_id'] ?? ''),
            'invoice' => !$invoiceOn ? 'none' : ((int) ($s['invoice_suppress_and_adjust_stock'] ?? 0) === 1 ? 'stockonly' : 'invoice'),
            'mail' => (int) ($s['invoice_mail_enabled'] ?? 0),
        ];
        if (($s['target_type_id'] ?? null) !== null) {
            $answers['type_id'] = (int) $s['target_type_id'];
        }
        if (($s['shopify_prices_include_tax'] ?? null) !== null) {
            $answers['prices'] = (string) (int) $s['shopify_prices_include_tax'];
        }
        $state = $this->state($shop);
        $state['wizard'] = $answers;
        $this->pdo->prepare('UPDATE connect_shops SET onboarding_state = :state WHERE shop = :shop')
            ->execute(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'shop' => $shop]);
    }

    /**
     * Schritte, die fuer die bisherigen Antworten gezeigt werden.
     *
     * @return array<int, string>
     */
    public function visibleSteps(string $shop): array
    {
        $a = $this->answers($shop);

        return array_values(array_filter(self::STEPS, static function (string $step) use ($a): bool {
            return match ($step) {
                'stock_lead' => ($a['stock'] ?? null) === 'yes',
                'prices' => ($a['invoice'] ?? null) === 'invoice',
                default => true,
            };
        }));
    }

    public function isStepDone(string $shop, string $step): bool
    {
        $a = $this->answers($shop);
        $s = $this->shop($shop);

        return match ($step) {
            'welcome' => isset($a['welcome']) && (new LegalTexts($this->pdo))->pendingForShop($shop) === [],
            'software' => isset($a['type_id']) || ($s['target_connection_id'] ?? null) !== null,
            'connect' => ($s['target_connection_id'] ?? null) !== null,
            'compare' => isset($a['compare']),
            'stock' => isset($a['stock']),
            'stock_lead' => isset($a['lead']),
            'invoice' => isset($a['invoice']),
            'prices' => isset($a['prices']),
            default => false,
        };
    }

    /** Erster offener Schritt (= wohin die Einrichtung beim Oeffnen springt). */
    public function firstOpenStep(string $shop): string
    {
        foreach ($this->visibleSteps($shop) as $step) {
            if (!$this->isStepDone($shop, $step)) {
                return $step;
            }
        }

        return 'done';
    }

    /** Ein Schritt ist erreichbar, wenn alle davor erledigt sind (Zurueck-Navigation). */
    public function isReachable(string $shop, string $step): bool
    {
        $visible = $this->visibleSteps($shop);
        $target = array_search($step, $visible, true);
        $open = array_search($this->firstOpenStep($shop), $visible, true);

        return $target !== false && $open !== false && $target <= $open;
    }

    /**
     * Wie viele Artikel des Zielsystems je Vergleichswert ein Gegenstueck in Shopify haben.
     * null = Artikel des Zielsystems noch nicht geladen. Rolle ohne Feld im Connector
     * Builder fehlt im Ergebnis.
     *
     * @return array<string, array{matched: int, total: int}>|null
     */
    public function matchCounts(string $shop): ?array
    {
        $s = $this->shop($shop);
        $shopifyConnectionId = $this->shopifyConnectionId($shop);
        if (($s['target_connection_id'] ?? null) === null || $shopifyConnectionId === null) {
            return null;
        }
        $counter = new MatchCounter($this->pdo);
        $result = [];
        $targetLoaded = false;

        foreach (array_keys(self::COMPARE_LABELS) as $role) {
            $count = $counter->count($shopifyConnectionId, (int) $s['target_connection_id'], $role);
            if ($count === null) {
                continue;
            }
            $targetLoaded = $targetLoaded || $count['target_total'] > 0;
            $result[$role] = ['matched' => $count['matched'], 'total' => $count['target_total']];
        }

        return $targetLoaded ? $result : null;
    }

    /** Vergleichswert mit den meisten Treffern (bei Gleichstand SKU vor Barcode vor Name). */
    public function recommendedCompare(?array $counts): string
    {
        if ($counts === null) {
            return 'sku';
        }
        $best = 'sku';
        $bestCount = -1;
        foreach (array_keys(self::COMPARE_LABELS) as $role) {
            if (isset($counts[$role]) && $counts[$role]['matched'] > $bestCount) {
                $best = $role;
                $bestCount = $counts[$role]['matched'];
            }
        }

        return $best;
    }

    /**
     * Shopify-Standorte aus dem Zwischenspeicher.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function shopifyLocations(string $shop): array
    {
        $connectionId = $this->shopifyConnectionId($shop);
        if ($connectionId === null) {
            return [];
        }
        $locations = [];
        foreach ((new ObjectCache($this->pdo))->getCached($connectionId, 'location') as $row) {
            if (($row['_status'] ?? 'active') !== 'orphaned' && ($row['shopify_id'] ?? '') !== '') {
                $locations[] = ['id' => (string) $row['shopify_id'], 'name' => (string) ($row['name'] ?? $row['shopify_id'])];
            }
        }
        usort($locations, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $locations;
    }

    /** "Nur Lagerbestand abziehen" braucht die Operation adjust_stock am Zielsystem. */
    public function supportsStockOnly(string $shop): bool
    {
        $s = $this->shop($shop);
        if (($s['target_type_id'] ?? null) === null) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM connect_object_operations op JOIN connect_objects o ON o.id = op.object_id
             WHERE o.connector_type_id = :type_id AND op.operation_type = 'adjust_stock' LIMIT 1"
        );
        $stmt->execute(['type_id' => (int) $s['target_type_id']]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * "oView starten": Antworten wirksam machen, Aktivierungen anlegen, Einrichtung abschliessen.
     *
     * @return array<int, string> Hinweise (leer = alles in Ordnung)
     */
    public function complete(string $shop, ShopActivations $activations): array
    {
        $a = $this->answers($shop);
        $s = $this->shop($shop);

        $compare = isset(self::COMPARE_LABELS[$a['compare'] ?? '']) ? (string) $a['compare'] : (string) ($s['comparison_key'] ?? 'sku');
        $stockOn = ($a['stock'] ?? 'no') === 'yes';
        $direction = ($a['lead'] ?? 'target') === 'shopify' ? 'shopify_to_target' : 'target_to_shopify';
        $location = $stockOn && $direction === 'target_to_shopify' && ($a['location'] ?? '') !== '' ? (string) $a['location'] : null;
        $invoice = in_array($a['invoice'] ?? '', ['invoice', 'stockonly', 'none'], true) ? (string) $a['invoice'] : 'none';
        $prices = in_array((string) ($a['prices'] ?? ''), ['0', '1'], true) ? (int) $a['prices'] : null;

        $sets = [
            'comparison_key = :compare',
            'stock_sync_enabled = :stock_on',
            'invoice_creation_enabled = :invoice_on',
            'invoice_suppress_and_adjust_stock = :stock_only',
            'invoice_mail_enabled = :mail',
            'setup_completed_at = CURRENT_TIMESTAMP',
        ];
        $params = [
            'compare' => $compare,
            'stock_on' => $stockOn ? 1 : 0,
            'invoice_on' => $invoice === 'none' ? 0 : 1,
            'stock_only' => $invoice === 'stockonly' ? 1 : 0,
            'mail' => $invoice === 'invoice' && !empty($a['mail']) ? 1 : 0,
            'shop' => $shop,
        ];
        if ($stockOn) {
            $sets[] = 'stock_sync_direction = :direction';
            $sets[] = 'stock_primary_location_id = :location';
            $params['direction'] = $direction;
            $params['location'] = $location;
        }
        if ($prices !== null) {
            $sets[] = 'shopify_prices_include_tax = :prices';
            $params['prices'] = $prices;
        }
        $this->pdo->prepare('UPDATE connect_shops SET ' . implode(', ', $sets) . ' WHERE shop = :shop')->execute($params);

        $notes = [];
        $activations->syncStockMapping($shop, $stockOn);
        \Connect\Billing\Plans::applyIntervals($this->pdo, $shop); // Daten-Jobs der neuen Software nach Abo
        $invoiceWarning = $activations->syncInvoiceMapping($shop, $invoice !== 'none');
        if ($invoiceWarning !== null) {
            $notes[] = $invoiceWarning;
        }
        if ($invoice !== 'none') {
            $webhookWarning = $activations->syncOrdersPaidWebhook($shop, true);
            if ($webhookWarning !== null) {
                $notes[] = $webhookWarning;
            }
        }

        // Einrichtungs-Assistent im Dashboard: alles, was hier beantwortet wurde, gilt als erledigt
        $confirmed = ['comparison', 'stock_sync', 'invoice'];
        if ($stockOn) {
            $confirmed[] = 'stock_direction';
        }
        if ($invoice === 'invoice') {
            $confirmed[] = 'invoice_mail';
        }
        if ($prices !== null) {
            $confirmed[] = 'prices';
        }
        (new SetupAssistant($this->pdo))->confirm($shop, $confirmed);

        return $notes;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(string $shop): array
    {
        $stmt = $this->pdo->prepare('SELECT onboarding_state FROM connect_shops WHERE shop = :shop');
        $stmt->execute(['shop' => $shop]);
        $raw = $stmt->fetchColumn();
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function shopifyConnectionId(string $shop): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
        );
        $stmt->execute(['shop' => $shop]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

}
