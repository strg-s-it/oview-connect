<?php

declare(strict_types=1);

namespace Connect\Setup;

use Connect\Config;
use Connect\Http\HttpClient;
use Connect\Security\Crypto;
use Connect\Shopify\WebhookRegistrar;
use PDO;

/**
 * Lagerbestand-Abgleich und Rechnungserstellung eines Shops ein-/ausschalten: die
 * Aktivierungen (connect_sync_mappings mapping_kind stock_sync/invoice) und das
 * Shopify-Abo "Bestellung bezahlt". Gemeinsam genutzt von den Einstellungen
 * (app/_sync_mappings_body.php) und der Einrichtung (Connect\Setup\SetupWizard),
 * damit beide Wege dieselben Zeilen anlegen.
 *
 * Die Werte selbst (stock_sync_enabled, invoice_creation_enabled, ...) schreibt der
 * Aufrufer vorher in connect_shops.
 */
final class ShopActivations
{
    /**
     * Rechnungs-Ausloeser je Shop (connect_shops.invoice_trigger, Migration 0106) -> Shopify-Webhook-Thema.
     * Das Thema steht an der Aktivierung (connect_sync_mappings.trigger_topic); webhooks/_process_order.php
     * verarbeitet je Thema nur die Aktivierungen mit genau diesem Thema.
     */
    public const INVOICE_TRIGGER_TOPICS = [
        'paid' => 'orders/paid',
        'fulfilled' => 'orders/fulfilled',
        'create' => 'orders/create',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Crypto $crypto,
        private readonly Config $config
    ) {
    }

    /** Mit Konfiguration und Schluessel aus der Umgebung (fuer Seiten ohne AppContext zur Hand). */
    public static function fromEnvironment(PDO $pdo): self
    {
        $config = Config::load();

        return new self($pdo, new Crypto($config->require('CONNECT_ENCRYPTION_KEY')), $config);
    }

    /**
     * Rechnungs-Ausloeser des Shops (paid | fulfilled | create). Fehlt die Spalte noch (Migration
     * 0106) oder ist der Wert unbekannt, gilt "paid" -- das bisherige Verhalten.
     */
    public static function invoiceTrigger(PDO $pdo, string $shop): string
    {
        try {
            $stmt = $pdo->prepare('SELECT invoice_trigger FROM connect_shops WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);
            $value = (string) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 'paid';
        }

        return isset(self::INVOICE_TRIGGER_TOPICS[$value]) ? $value : 'paid';
    }

    /** Shopify-Webhook-Thema, das beim Shop die Rechnung ausloest. */
    public static function invoiceTopic(PDO $pdo, string $shop): string
    {
        return self::INVOICE_TRIGGER_TOPICS[self::invoiceTrigger($pdo, $shop)];
    }

    /**
     * Aktivierung "Lagerbestand-Abgleich" (stuendlich). source_connection_id/source_object_id
     * sind nur fuer die Anzeige (NOT NULL), StockSyncRunner liest Quelle/Ziel aus connect_shops.
     */
    public function syncStockMapping(string $shop, bool $enabled): void
    {
        $existing = $this->pdo->prepare("SELECT id FROM connect_sync_mappings WHERE shop = :shop AND mapping_kind = 'stock_sync'");
        $existing->execute(['shop' => $shop]);
        $mappingId = $existing->fetchColumn();

        if ($mappingId !== false) {
            $this->pdo->prepare('UPDATE connect_sync_mappings SET enabled = :enabled, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['enabled' => $enabled ? 1 : 0, 'id' => $mappingId]);

            return;
        }
        if (!$enabled) {
            return;
        }

        $shopify = $this->shopifyConnection($shop);
        $productObjectId = $this->pdo->query(
            "SELECT o.id FROM connect_objects o JOIN connect_connector_types t ON t.id = o.connector_type_id
             WHERE t.type_key = 'shopify' AND o.object_key = 'product' LIMIT 1"
        )->fetchColumn();

        if ($shopify !== null && $productObjectId !== false) {
            $this->pdo->prepare(
                "INSERT INTO connect_sync_mappings
                    (shop, name, source_connection_id, source_object_id, mapping_kind, trigger_type, schedule_interval_minutes, enabled, managed_by)
                 VALUES (:shop, 'Lagerbestand-Abgleich', :source_connection_id, :source_object_id, 'stock_sync', 'schedule', 60, 1, 'system')"
            )->execute(['shop' => $shop, 'source_connection_id' => (int) $shopify['id'], 'source_object_id' => (int) $productObjectId]);
        }

        // Haeufigkeit nach Abo (60/30/15 Min.), auch fuer die zugehoerigen Daten-Jobs
        \Connect\Billing\Plans::applyIntervals($this->pdo, $shop);
    }

    /** Rueckbuchung bei Kassenverkauf: oft genug, damit der Lagerabgleich einen Verkauf nicht vorher ueberschreibt. */
    public const STOCK_BACK_MINUTES = 5;

    /**
     * Aktivierung "Rueckbuchung bei Kassenverkauf" (Connect\Sync\StockBackRunner, Migration 0104).
     * source_* nur fuer die Anzeige (NOT NULL), der Runner liest alles aus connect_shops.
     */
    public function syncStockBackMapping(string $shop, bool $enabled): void
    {
        $existing = $this->pdo->prepare("SELECT id FROM connect_sync_mappings WHERE shop = :shop AND mapping_kind = 'stock_back'");
        $existing->execute(['shop' => $shop]);
        $mappingId = $existing->fetchColumn();

        if ($mappingId !== false) {
            $this->pdo->prepare('UPDATE connect_sync_mappings SET enabled = :enabled, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['enabled' => $enabled ? 1 : 0, 'id' => $mappingId]);

            return;
        }
        if (!$enabled) {
            return;
        }

        $shopify = $this->shopifyConnection($shop);
        $productObjectId = $this->pdo->query(
            "SELECT o.id FROM connect_objects o JOIN connect_connector_types t ON t.id = o.connector_type_id
             WHERE t.type_key = 'shopify' AND o.object_key = 'product' LIMIT 1"
        )->fetchColumn();

        if ($shopify !== null && $productObjectId !== false) {
            $this->pdo->prepare(
                "INSERT INTO connect_sync_mappings
                    (shop, name, source_connection_id, source_object_id, mapping_kind, trigger_type, schedule_interval_minutes, enabled, managed_by)
                 VALUES (:shop, 'Kassenverkäufe zurückbuchen', :source_connection_id, :source_object_id, 'stock_back', 'schedule', :minutes, 1, 'system')"
            )->execute([
                'shop' => $shop,
                'source_connection_id' => (int) $shopify['id'],
                'source_object_id' => (int) $productObjectId,
                'minutes' => self::STOCK_BACK_MINUTES,
            ]);
        }
    }

    /**
     * Aktivierung "Rechnungserstellung" (Webhook orders/paid). target_connection_id muss
     * gesetzt sein, sonst greift webhooks/order_paid.php sie nicht auf. Neue Aktivierungen
     * bekommen die Beleg-Vorlage, die die uebrigen Shops mit demselben Zielsystem am
     * haeufigsten nutzen (ohne = bisheriger InvoiceService-Weg).
     *
     * @return string|null Hinweis, wenn die Aktivierung nicht angelegt werden konnte
     */
    public function syncInvoiceMapping(string $shop, bool $enabled): ?string
    {
        $targetStmt = $this->pdo->prepare(
            'SELECT s.target_connection_id, c.connector_type_id FROM connect_shops s
             LEFT JOIN connect_connections c ON c.id = s.target_connection_id WHERE s.shop = :shop'
        );
        $targetStmt->execute(['shop' => $shop]);
        $target = $targetStmt->fetch() ?: ['target_connection_id' => null, 'connector_type_id' => null];
        $targetConnectionId = $target['target_connection_id'] !== null ? (int) $target['target_connection_id'] : null;

        if ($enabled && $targetConnectionId === null) {
            return 'Kein Zielsystem verbunden – die Rechnungserstellung kann so nicht auslösen.';
        }

        $invoiceTopic = self::invoiceTopic($this->pdo, $shop);

        $existing = $this->pdo->prepare("SELECT id, enabled, schedule_interval_minutes, settings FROM connect_sync_mappings WHERE shop = :shop AND mapping_kind = 'invoice'");
        $existing->execute(['shop' => $shop]);
        $existingRow = $existing->fetch();
        $mappingId = $existingRow !== false ? $existingRow['id'] : false;

        if ($mappingId !== false) {
            // Beim (Wieder-)Einschalten: Nachlauf stuendlich, aber erst ab JETZT -- Bestellungen aus
            // der Zeit, in der die Rechnungserstellung aus war, werden nicht nachtraeglich abgerechnet.
            $settings = $existingRow['settings'] !== null ? (json_decode((string) $existingRow['settings'], true) ?: []) : [];
            $interval = $existingRow['schedule_interval_minutes'] ?? ($enabled ? self::INVOICE_BACKFILL_MINUTES : null);
            $newlyBackfilling = $existingRow['schedule_interval_minutes'] === null && $interval !== null;
            if ($enabled && ((int) $existingRow['enabled'] !== 1 || $newlyBackfilling || !isset($settings['backfill_since']))) {
                $settings['backfill_since'] = time();
            }

            $this->pdo->prepare(
                "UPDATE connect_sync_mappings
                 SET enabled = :enabled, target_connection_id = :target_connection_id,
                     trigger_type = 'webhook', trigger_topic = :topic,
                     schedule_interval_minutes = :interval, settings = :settings, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            )->execute([
                'topic' => $invoiceTopic,
                'enabled' => $enabled ? 1 : 0,
                'target_connection_id' => $targetConnectionId,
                'interval' => $interval,
                'settings' => $settings !== [] ? json_encode($settings) : null,
                'id' => $mappingId,
            ]);

            return null;
        }
        if (!$enabled) {
            return null;
        }

        $shopify = $this->shopifyConnection($shop);
        if ($shopify === null || $shopify['order_object_id'] === null) {
            return 'Keine Shopify-Connection mit „Bestellung“-Objekt gefunden – die Rechnungserstellung kann so nicht auslösen.';
        }

        $templateId = self::defaultInvoiceTemplateId($this->pdo, (int) $target['connector_type_id']) ?? false;

        $this->pdo->prepare(
            "INSERT INTO connect_sync_mappings
                (shop, name, source_connection_id, source_object_id, target_connection_id, mapping_template_id, trigger_type, trigger_topic,
                 schedule_interval_minutes, settings, enabled, managed_by, mapping_kind)
             VALUES (:shop, 'Rechnungserstellung', :source_connection_id, :source_object_id, :target_connection_id, :template_id, 'webhook', :topic,
                 :interval, :settings, 1, 'system', 'invoice')"
        )->execute([
            'topic' => $invoiceTopic,
            'shop' => $shop,
            'source_connection_id' => (int) $shopify['id'],
            'source_object_id' => (int) $shopify['order_object_id'],
            'target_connection_id' => $targetConnectionId,
            'template_id' => $templateId !== false ? (int) $templateId : null,
            'interval' => self::INVOICE_BACKFILL_MINUTES,
            'settings' => json_encode(['backfill_since' => time()]),
        ]);

        return null;
    }

    /**
     * Rechnungs-Aktivierung: Nachlauf (Sicherheitsnetz fuer Bestellungen, deren Shopify-Meldung
     * nicht ankam) alle 60 Minuten -- wie der Nachlauf der alten Welt
     * (jobs/cron_sync_3rd-party_invoices.php). Er verarbeitet nur Bestellungen ab dem
     * Einschalten bzw. Umschalten, siehe Connect\Sync\SyncRunner::backfillSince().
     */
    public const INVOICE_BACKFILL_MINUTES = 60;

    /**
     * Beleg-Vorlage fuer eine neue Rechnungs-Aktivierung eines Zielsystems:
     *   1. die bei Rechnungs-Aktivierungen haeufigste Vorlage dieses Zielsystems,
     *   2. sonst die neueste Vorlage "Bestellung (aufbereitet)" -> Object MIT Belegprofil
     *      dieses Zielsystems (z.B. HelloCash Rechnung). Ohne diesen Rueckfall bekam eine neue
     *      Aktivierung KEINE Vorlage (= alter, fest programmierter InvoiceService), sobald gerade
     *      kein Shop die Vorlage nutzte -- z.B. nachdem der einzige Test-Shop entfernt wurde.
     *      Kunden-Vorlagen (Ziel ohne Belegprofil) kommen dabei nicht in Frage.
     */
    public static function defaultInvoiceTemplateId(PDO $pdo, int $targetConnectorTypeId): ?int
    {
        $stmt = $pdo->prepare(
            "SELECT m.mapping_template_id FROM connect_sync_mappings m
             JOIN connect_mapping_templates t ON t.id = m.mapping_template_id
             WHERE m.mapping_kind = 'invoice' AND t.target_connector_type_id = :type_id
             GROUP BY m.mapping_template_id ORDER BY COUNT(*) DESC LIMIT 1"
        );
        $stmt->execute(['type_id' => $targetConnectorTypeId]);
        $id = $stmt->fetchColumn();
        if ($id !== false && $id !== null) {
            return (int) $id;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT t.id FROM connect_mapping_templates t
                 JOIN connect_objects so ON so.id = t.source_object_id AND so.object_key = 'order_prepared'
                 JOIN connect_objects tobj ON tobj.id = t.target_object_id AND tobj.document_profile IS NOT NULL
                 WHERE t.target_connector_type_id = :type_id
                 ORDER BY t.id DESC LIMIT 1"
            );
            $stmt->execute(['type_id' => $targetConnectorTypeId]);
            $id = $stmt->fetchColumn();
        } catch (\PDOException) {
            return null; // Belegprofil-Spalte fehlt (Migration 0067)
        }

        return $id !== false ? (int) $id : null;
    }

    /**
     * Shopify-Abo "Bestellung bezahlt" passend zum Schalter (ab)bestellen. Vom Admin angelegte
     * Uebersetzungs-Aktivierungen fuer "Bestellung bezahlt" (z.B. MGM-Auftrag) folgen dem Schalter.
     *
     * @return string|null Hinweis, wenn das Abo nicht aktualisiert werden konnte
     */
    public function syncOrdersPaidWebhook(string $shop, bool $enabled): ?string
    {
        $shopify = $this->shopifyConnection($shop);
        if ($shopify === null) {
            return null;
        }

        $warning = null;
        try {
            $credentials = \Connect\Shopify\TokenRefresher::fromConfig($this->config)->freshCredentials($this->pdo, $this->crypto, (int) $shopify['id']);
            $shopDomain = str_replace(['https://', 'http://'], '', (string) ($credentials['base_url'] ?? ''));
            $accessToken = (string) ($credentials['header_value'] ?? '');

            $registrar = new WebhookRegistrar(
                $this->pdo,
                new HttpClient(),
                $this->config->require('CONNECT_APP_URL'),
                $this->config->optional('CONNECT_SHOPIFY_API_VERSION', '2026-07')
            );
            if ($enabled) {
                $registrar->subscribe((int) $shopify['id'], 'orders/paid', $shopDomain, $accessToken);
            } else {
                $registrar->unsubscribe((int) $shopify['id'], 'orders/paid', $shopDomain, $accessToken);
            }
            // Ausloeser "angelegt"/"erfuellt": zusaetzlich dieses Abo (bei "bezahlt" ist es das obige)
            $invoiceTopic = self::invoiceTopic($this->pdo, $shop);
            if ($invoiceTopic !== 'orders/paid') {
                if ($enabled) {
                    $registrar->subscribe((int) $shopify['id'], $invoiceTopic, $shopDomain, $accessToken);
                } elseif (!$this->topicInUse((int) $shopify['id'], $invoiceTopic)) {
                    $registrar->unsubscribe((int) $shopify['id'], $invoiceTopic, $shopDomain, $accessToken);
                }
            }
        } catch (\Throwable $e) {
            $warning = 'Shopify-Abo für die Rechnungserstellung konnte nicht aktualisiert werden: ' . $e->getMessage();
        }

        $this->pdo->prepare(
            "UPDATE connect_sync_mappings SET enabled = :enabled
             WHERE shop = :shop AND source_connection_id = :connection_id AND trigger_type = 'webhook' AND trigger_topic = 'orders/paid'"
        )->execute(['enabled' => $enabled ? 1 : 0, 'shop' => $shop, 'connection_id' => (int) $shopify['id']]);

        return $warning;
    }

    /**
     * Rechnungs-Ausloeser des Shops setzen (paid | fulfilled | create): Wert in connect_shops, Thema der
     * Rechnungs-Aktivierung, Shopify-Abo. Beim Wechsel zaehlen fuer den Nachlauf nur Bestellungen ab JETZT
     * (settings.backfill_since), sonst wuerden bisher unberuecksichtigte Bestellungen nachtraeglich abgerechnet.
     *
     * @return string|null Hinweis, wenn etwas nicht sauber umgestellt werden konnte
     */
    public function applyInvoiceTrigger(string $shop, string $trigger): ?string
    {
        if (!isset(self::INVOICE_TRIGGER_TOPICS[$trigger])) {
            return 'Unbekannter Auslöser.';
        }
        $old = self::invoiceTrigger($this->pdo, $shop);
        $oldTopic = self::INVOICE_TRIGGER_TOPICS[$old];
        $newTopic = self::INVOICE_TRIGGER_TOPICS[$trigger];

        try {
            $this->pdo->prepare('UPDATE connect_shops SET invoice_trigger = :trigger WHERE shop = :shop')
                ->execute(['trigger' => $trigger, 'shop' => $shop]);
        } catch (\Throwable) {
            return 'Die Einstellung braucht die Migration 0106 (php bin/install.php).';
        }
        if ($old === $trigger) {
            return null;
        }

        // Aktivierung auf das neue Thema, Nachlauf erst ab jetzt
        $mapping = $this->pdo->prepare("SELECT id, settings FROM connect_sync_mappings WHERE shop = :shop AND mapping_kind = 'invoice'");
        $mapping->execute(['shop' => $shop]);
        $row = $mapping->fetch();
        if ($row !== false) {
            $settings = $row['settings'] !== null ? (json_decode((string) $row['settings'], true) ?: []) : [];
            $settings['backfill_since'] = time();
            $this->pdo->prepare(
                'UPDATE connect_sync_mappings SET trigger_topic = :topic, settings = :settings, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute(['topic' => $newTopic, 'settings' => json_encode($settings), 'id' => $row['id']]);
        }

        // Abos: neues Thema bestellen (nur bei aktiver Rechnungserstellung), altes abbestellen,
        // wenn keine andere aktive Aktivierung es braucht
        $invoiceOn = $this->pdo->prepare('SELECT invoice_creation_enabled FROM connect_shops WHERE shop = :shop');
        $invoiceOn->execute(['shop' => $shop]);
        $enabled = (int) $invoiceOn->fetchColumn() === 1;

        $shopify = $this->shopifyConnection($shop);
        if ($shopify === null) {
            return null;
        }
        try {
            $credentials = \Connect\Shopify\TokenRefresher::fromConfig($this->config)->freshCredentials($this->pdo, $this->crypto, (int) $shopify['id']);
            $shopDomain = str_replace(['https://', 'http://'], '', (string) ($credentials['base_url'] ?? ''));
            $accessToken = (string) ($credentials['header_value'] ?? '');
            $registrar = new WebhookRegistrar(
                $this->pdo,
                new HttpClient(),
                $this->config->require('CONNECT_APP_URL'),
                $this->config->optional('CONNECT_SHOPIFY_API_VERSION', '2026-07')
            );
            if ($enabled) {
                $registrar->subscribe((int) $shopify['id'], $newTopic, $shopDomain, $accessToken);
            }
            if ($oldTopic !== 'orders/paid' && $oldTopic !== $newTopic && !$this->topicInUse((int) $shopify['id'], $oldTopic)) {
                $registrar->unsubscribe((int) $shopify['id'], $oldTopic, $shopDomain, $accessToken);
            }
        } catch (\Throwable $e) {
            return 'Shopify-Abo konnte nicht umgestellt werden: ' . $e->getMessage() . ' (die Einstellung ist gespeichert).';
        }

        return null;
    }

    /** Lauscht noch eine aktive, webhook-getriggerte Aktivierung dieser Connection auf das Thema? */
    private function topicInUse(int $connectionId, string $topic): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM connect_sync_mappings
             WHERE source_connection_id = :connection_id AND trigger_type = 'webhook' AND trigger_topic = :topic AND enabled = 1 LIMIT 1"
        );
        $stmt->execute(['connection_id' => $connectionId, 'topic' => $topic]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return array{id: int, credentials: string, order_object_id: ?int}|null
     */
    private function shopifyConnection(string $shop): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.credentials, o.id AS order_object_id FROM connect_connections c
             JOIN connect_connector_types t ON t.id = c.connector_type_id
             LEFT JOIN connect_objects o ON o.connector_type_id = t.id AND o.object_key = 'order'
             WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
        );
        $stmt->execute(['shop' => $shop]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'credentials' => (string) $row['credentials'],
            'order_object_id' => $row['order_object_id'] !== null ? (int) $row['order_object_id'] : null,
        ];
    }
}
