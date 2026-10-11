<?php

declare(strict_types=1);

namespace Connect\Billing;

use PDO;

/**
 * Die drei Abos -- wie in der alten Welt (index.php/help.php, Preisliste im Shopify App Store):
 *   OVIEW HELLOCASH     19.90 USD  Lagerabgleich alle 60 Min.  Rechnungsversand ueber oview.at
 *   OVIEW HELLOCASH +   25.90 USD  alle 30 Min.               eigene SMTP-Adresse
 *   OVIEW HELLOCASH ++  35.90 USD  alle 15 Min.               eigene SMTP-Adresse
 * Erstes Abo eines Shops mit 14 Tagen Test, jeder Wechsel danach ohne (Nutzer 2026-10-05).
 *
 * Der Name ist der Schluessel zu Shopify: ein Abo aus der alten Welt mit demselben Namen
 * wird unveraendert erkannt.
 */
final class Plans
{
    public const TRIAL_DAYS = 14;
    public const CURRENCY = 'USD';
    public const DEFAULT_STOCK_MINUTES = 60;

    public const PLANS = [
        'standard' => [
            'name' => 'OVIEW HELLOCASH',
            'price' => '19.90',
            'stock_minutes' => 60,
            'own_smtp' => false,
            'features' => ['Lagerstand synchronisieren alle 60 Min.', 'Rechnungen von Shopify zu HelloCash', 'Support schriftlich per E-Mail oder Chat', 'Rechnungsversand über die oview.at-Adresse', 'Produkt-Check: Grunddaten (Bilder, Beschreibung, SKU, Barcode)'],
        ],
        'plus' => [
            'name' => 'OVIEW HELLOCASH +',
            'price' => '25.90',
            'stock_minutes' => 30,
            'own_smtp' => true,
            'features' => ['Lagerstand synchronisieren alle 30 Min.', 'Rechnungen von Shopify zu HelloCash', 'Support schriftlich per E-Mail oder Chat', 'Rechnungsversand über deine eigene SMTP-Adresse', 'Produkt-Check komplett: Grunddaten, SEO und Textqualität', 'Preise abgleichen und gesammelt ändern', 'Sets, Anhänge an der Rechnungsmail'],
        ],
        'plusplus' => [
            'name' => 'OVIEW HELLOCASH ++',
            'price' => '35.90',
            'stock_minutes' => 15,
            'own_smtp' => true,
            'features' => ['Lagerstand synchronisieren alle 15 Min.', 'Rechnungen von Shopify zu HelloCash', 'Support schriftlich per E-Mail oder Chat', 'Rechnungsversand über deine eigene SMTP-Adresse', 'Produkt-Check komplett: Grunddaten, SEO und Textqualität', 'Preise abgleichen und gesammelt ändern', 'Sets, Anhänge an der Rechnungsmail'],
        ],
    ];

    public static function keyForName(string $name): ?string
    {
        $normalized = strtoupper(trim((string) preg_replace('/\s+/', ' ', $name)));
        foreach (self::PLANS as $key => $plan) {
            if ($plan['name'] === $normalized) {
                return $key;
            }
        }

        return null;
    }

    /** Aktuelles Abo eines Shops (NULL = unbekannt oder Migration 0079 fehlt). */
    public static function forShop(PDO $pdo, string $shop): ?string
    {
        try {
            $stmt = $pdo->prepare('SELECT plan FROM connect_shops WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);
            $plan = $stmt->fetchColumn();

            return is_string($plan) && isset(self::PLANS[$plan]) ? $plan : null;
        } catch (\PDOException) {
            return null;
        }
    }

    /**
     * Eigenes SMTP erlaubt? Wie alte Welt: nur + und ++. Unbekanntes Abo (noch nicht
     * abgefragt) erlaubt es, damit kein bisher funktionierender Versand still wegfaellt.
     */
    public static function allowsOwnSmtp(?string $plan): bool
    {
        return $plan === null || self::PLANS[$plan]['own_smtp'];
    }

    /**
     * Zusatzfunktionen je Abo (Nutzer 2026-10-10: Vorschlag, hier an EINER Stelle anpassbar).
     * Unbekanntes Abo erlaubt alles (wie allowsOwnSmtp), der Admin sieht ohnehin alles.
     */
    public const FEATURES = [
        'price_tools' => ['plus', 'plusplus'],   // Preissynchronisation + Sammelaenderungen
        'attachments' => ['plus', 'plusplus'],   // Anhaenge an der Rechnungsmail
        'bundles' => ['plus', 'plusplus'],       // Sets und Bundles
        // Produkt-Check: Grunddaten in jedem Abo, SEO- und Text-Pruefungen ab + (Punktwert und
        // Anzahl je Pruefung sehen alle, gesperrt ist die Liste der Produkte und die Textansicht)
        'product_check_seo' => ['plus', 'plusplus'],
        'product_check_text' => ['plus', 'plusplus'],
    ];

    public static function allowsFeature(?string $plan, string $feature): bool
    {
        return $plan === null || !isset(self::FEATURES[$feature]) || in_array($plan, self::FEATURES[$feature], true);
    }

    public static function allowsPriceTools(?string $plan): bool
    {
        return self::allowsFeature($plan, 'price_tools');
    }

    /** Name des kleinsten Abos, das eine Funktion enthaelt (fuer Hinweise "ab ..."). */
    public static function lowestPlanFor(string $feature): string
    {
        foreach (self::PLANS as $key => $plan) {
            if (self::allowsFeature($key, $feature)) {
                return $plan['name'];
            }
        }

        return '';
    }

    public static function stockMinutes(?string $plan): int
    {
        return $plan !== null ? self::PLANS[$plan]['stock_minutes'] : self::DEFAULT_STOCK_MINUTES;
    }

    /**
     * Intervall des Abos auf den Lagerabgleich UND die Daten-Jobs anwenden, deren Daten er
     * braucht (Objects mit Lagerbestand-Feld, z.B. Shopify-Produkt, HelloCash-Artikel) --
     * ein Abgleich alle 15 Min. auf stuendlich geladenen Daten braechte nichts. Ohne
     * bekanntes Abo bleibt alles, wie es ist.
     */
    public static function applyIntervals(PDO $pdo, string $shop): void
    {
        $plan = self::forShop($pdo, $shop);
        if ($plan === null) {
            return;
        }
        $pdo->prepare(
            "UPDATE connect_sync_mappings m SET m.schedule_interval_minutes = :minutes
             WHERE m.shop = :shop AND m.schedule_interval_minutes IS NOT NULL
               AND (m.mapping_kind = 'stock_sync'
                    OR (m.mapping_kind = 'default' AND m.target_connection_id IS NULL
                        AND EXISTS (SELECT 1 FROM connect_object_fields f WHERE f.object_id = m.source_object_id AND f.is_stock_quantity = 1)))"
        )->execute(['minutes' => self::PLANS[$plan]['stock_minutes'], 'shop' => $shop]);
    }
}
