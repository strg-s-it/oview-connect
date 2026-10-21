<?php

declare(strict_types=1);

namespace Connect\Legacy;

use Connect\Config;
use Connect\Connector\ConnectorFactory;
use Connect\Database;
use Connect\Security\Crypto;
use PDO;

/**
 * Umzug eines Shops aus der alten Welt: liest dessen Einstellungen aus der
 * alten Datenbank (hellocash_binding, hellofy_sync_settings_invoice,
 * hellofy_sync_settings_stock -- gepflegt in settings.php der alten Welt),
 * vergleicht sie mit Connect (plan()) und uebernimmt sie (apply()):
 * Shop-Einstellungen, HelloCash-Connection mit dem Token der alten Welt,
 * Zielsystem, Aktivierungen fuer Rechnung und Lagerbestand.
 *
 * Bewusst NICHT hier: der Shopify-Token (bestehende Migrationsbruecke
 * "Token uebernehmen" in admin/shops.php) und das Umschalten selbst
 * (live_in_connect + Webhooks, Connect\Routing\LiveSwitch) -- beides bleibt ein
 * eigener, bewusster Schritt auf der Umzugsseite (admin/migrate.php).
 *
 * Funktionen der alten Welt ohne Gegenstueck in Connect werden nicht still
 * verworfen, sondern im Plan als "nicht in Connect" bzw. "manuell" gemeldet.
 */
final class ShopMigrator
{
    public const STATUS_SAME = 'same';
    public const STATUS_CHANGE = 'change';
    public const STATUS_MANUAL = 'manual';
    public const STATUS_UNSUPPORTED = 'unsupported';

    /** Shops, fuer die die alte Welt Kunden nur per E-Mail zuordnete (inc/functions_hellocash.php, $email_only_shops) */
    private const LEGACY_EMAIL_ONLY_SHOPS = ['fgnpc9-wa'];

    /** @var array<string, mixed>|null */
    private ?array $legacy = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly Crypto $crypto
    ) {
    }

    /**
     * Einstellungen der alten Welt, null wenn der Shop dort nicht gefunden wurde.
     *
     * @return array{binding: ?array<string, mixed>, invoice: ?array<string, mixed>, stock: ?array<string, mixed>, store: ?array<string, mixed>, legacy_shop: string}|null
     */
    public function loadLegacy(string $shop): ?array
    {
        if ($this->legacy !== null) {
            return $this->legacy;
        }
        if (!LegacyBridge::isConfigured($this->config)) {
            throw new \RuntimeException('Migrationsbrücke nicht konfiguriert: LEGACY_DB_* fehlt in connect/.env.');
        }
        $legacyPdo = Database::connectTo(
            $this->config->require('LEGACY_DB_HOST'),
            $this->config->optional('LEGACY_DB_PORT', '3306'),
            $this->config->require('LEGACY_DB_NAME'),
            $this->config->require('LEGACY_DB_USER'),
            $this->config->optional('LEGACY_DB_PASS')
        )->pdo();

        // Die alte Welt speichert den Shop je nach Tabelle mit oder ohne .myshopify.com
        $candidates = [$shop . '.myshopify.com', $shop];
        $fetch = static function (string $table) use ($legacyPdo, $candidates): ?array {
            $stmt = $legacyPdo->prepare("SELECT * FROM {$table} WHERE shop IN (:a, :b) LIMIT 1");
            $stmt->execute(['a' => $candidates[0], 'b' => $candidates[1]]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row !== false ? $row : null;
        };

        $binding = $fetch('hellocash_binding');
        $invoice = $fetch('hellofy_sync_settings_invoice');
        $stock = $fetch('hellofy_sync_settings_stock');
        if ($binding === null && $invoice === null && $stock === null) {
            return null;
        }

        // Abo der alten Welt (von index.php/get_current_plan() dort mitgeschrieben) -- nur zur
        // Anzeige im Plan, massgeblich ist beim Uebernehmen die Abfrage bei Shopify.
        $storeStmt = $legacyPdo->prepare(
            "SELECT charge_id, charge_name, charge_status, charge_amount, charge_test FROM oview_stores
             WHERE store_url = :url ORDER BY install_date DESC LIMIT 1"
        );
        $storeStmt->execute(['url' => $shop . '.myshopify.com']);
        $store = $storeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return $this->legacy = [
            'binding' => $binding,
            'invoice' => $invoice,
            'stock' => $stock,
            'store' => $store,
            'legacy_shop' => (string) ($binding['shop'] ?? $invoice['shop'] ?? $stock['shop'] ?? $shop),
        ];
    }

    /**
     * Gegenueberstellung alte Welt / Connect.
     *
     * Mit $factory wird zusaetzlich live geprueft, ob die Zahlungsart im helloCash-Konto existiert.
     *
     * @return list<array{area: string, label: string, legacy: string, connect: string, status: string, note: ?string}>
     */
    public function plan(string $shop, ?ConnectorFactory $factory = null): array
    {
        $legacy = $this->loadLegacy($shop);
        if ($legacy === null) {
            return [];
        }
        $connect = $this->connectShop($shop) ?? [];
        $values = $this->targetValues($shop, $legacy);
        $rows = [];

        foreach ($this->fieldDefinitions() as $column => [$area, $label, $format]) {
            if (!array_key_exists($column, $values)) {
                continue;
            }
            $new = $values[$column];
            $current = $connect[$column] ?? null;
            $same = self::normalize($new) === self::normalize($current);
            $rows[] = [
                'area' => $area,
                'label' => $label,
                'legacy' => $format($new),
                'connect' => $connect === [] ? '(Shop nicht in Connect)' : $format($current),
                'status' => $same ? self::STATUS_SAME : self::STATUS_CHANGE,
                'note' => null,
            ];
        }

        // HelloCash-Connection
        $hcConnection = $this->helloCashConnection($shop);
        $token = trim((string) ($legacy['binding']['token'] ?? ''));
        $rows[] = [
            'area' => 'Zielsystem',
            'label' => 'HelloCash-Connection',
            'legacy' => $token !== '' ? 'Token vorhanden (…' . substr($token, -4) . ')' : 'kein Token',
            'connect' => $hcConnection !== null ? '„' . $hcConnection['label'] . '“ (' . $hcConnection['status'] . ')' : 'keine',
            'status' => $hcConnection !== null ? self::STATUS_SAME : ($token !== '' ? self::STATUS_CHANGE : self::STATUS_MANUAL),
            'note' => $hcConnection !== null ? 'Bestehende Connection bleibt, der Token wird nicht überschrieben.' : ($token !== '' ? 'Wird mit dem Token der alten Welt angelegt und getestet.' : 'Ohne Token muss die Connection von Hand angelegt werden.'),
        ];

        // Zahlungsart im helloCash-Konto: API v1 nahm einen freien Text, v2 lehnt unbekannte Zahlungsarten
        // ab (payment_method_not_found). Beim Uebernehmen wird sie bei Bedarf angelegt (PaymentMethodEnsurer).
        if ($factory !== null && (int) ($values['invoice_creation_enabled'] ?? 0) === 1 && (int) ($values['invoice_suppress_and_adjust_stock'] ?? 0) === 0) {
            $rows[] = $this->paymentMethodRow((string) ($values['invoice_payment_method'] ?? ''), $shop, $legacy, $hcConnection, $factory);
        }

        // SMTP-Passwort
        $legacySmtpPassword = (string) ($legacy['invoice']['smtp_password'] ?? '');
        if ($legacySmtpPassword !== '') {
            $decrypted = $this->decryptLegacySmtpPassword($legacySmtpPassword);
            $rows[] = [
                'area' => 'Rechnung · Mail',
                'label' => 'SMTP-Passwort',
                'legacy' => 'gesetzt',
                'connect' => ($connect['invoice_mail_smtp_password'] ?? '') !== '' ? 'gesetzt' : 'leer',
                'status' => $decrypted !== null ? self::STATUS_CHANGE : self::STATUS_MANUAL,
                'note' => $decrypted !== null ? 'Wird entschlüsselt und neu verschlüsselt übernommen.' : 'Nicht lesbar ohne LEGACY_SMTP_ENCRYPTION_KEY in connect/.env (= SMTP_ENCRYPTION_KEY der alten Welt) – sonst in Connect neu eingeben.',
            ];
        }

        // Abo: haengt bei Shopify an der App-Installation (dieselbe App), bleibt also bestehen.
        // Connect muss es nur einmal abfragen, damit Intervall und Funktionen passen.
        $store = $legacy['store'] ?? null;
        $legacyPlanName = trim((string) ($store['charge_name'] ?? ''));
        $legacyActive = ($store['charge_status'] ?? '') === 'active';
        $planKey = $legacyActive ? \Connect\Billing\Plans::keyForName($legacyPlanName) : null;
        $rows[] = [
            'area' => 'Abo',
            'label' => 'Shopify-Abo',
            'legacy' => $legacyActive
                ? $legacyPlanName . ' · ' . ($store['charge_amount'] ?? '?') . ' USD' . (in_array((string) ($store['charge_test'] ?? ''), ['1', 'true'], true) ? ' · Test' : '')
                : 'kein aktives Abo',
            'connect' => $connect !== [] && ($connect['plan'] ?? null) !== null
                ? (\Connect\Billing\Plans::PLANS[$connect['plan']]['name'] ?? (string) $connect['plan']) . ' · ' . (string) ($connect['plan_status'] ?? '')
                : 'noch nicht abgefragt',
            'status' => !$legacyActive ? self::STATUS_MANUAL : ($planKey !== null ? self::STATUS_CHANGE : self::STATUS_MANUAL),
            'note' => !$legacyActive
                ? 'Kein aktives Abo bekannt – der Merchant muss in Connect unter „Abo“ eines wählen.'
                : ($planKey !== null
                    ? 'Bleibt bei Shopify bestehen (dieselbe App), kein neues Abo und kein neuer Testzeitraum. Beim Übernehmen fragt Connect es ab und stellt den Lagerabgleich auf alle '
                        . \Connect\Billing\Plans::PLANS[$planKey]['stock_minutes'] . ' Minuten.'
                    : 'Abo-Name unbekannt – bleibt bei Shopify bestehen, Connect nutzt aber die Standardwerte (Lagerabgleich alle 60 Min.). Bitte prüfen.'),
        ];

        // Funktionen der alten Welt ohne Gegenstueck in Connect
        $inv = $legacy['invoice'] ?? [];
        if (!$this->hasSignatureColumn() && ($inv['signature_mandatory'] ?? 'false') === 'true') {
            $rows[] = ['area' => 'Rechnung', 'label' => 'Signatur erforderlich', 'legacy' => 'an', 'connect' => '–', 'status' => self::STATUS_MANUAL, 'note' => 'Braucht Migration 0072 (php connect/bin/install.php), danach wird der Wert übernommen.'];
        }
        if (trim((string) ($inv['invoice_prefix'] ?? '')) !== '') {
            $rows[] = ['area' => 'Rechnung', 'label' => 'Präfix im Rechnungstext', 'legacy' => (string) $inv['invoice_prefix'], 'connect' => '–', 'status' => self::STATUS_MANUAL, 'note' => 'In Connect über den Rechnungstext der Vorlage abbilden.'];
        }
        if (($inv['allow_empty_customers'] ?? 'false') === 'true') {
            $rows[] = ['area' => 'Rechnung', 'label' => 'Rechnung ohne Kunde erlauben', 'legacy' => 'an', 'connect' => 'immer erlaubt', 'status' => self::STATUS_SAME, 'note' => 'Connect legt die Rechnung ohne Kunden-ID an, wenn keiner zugeordnet werden kann.'];
        }
        if (($legacy['stock']['back_enabled'] ?? 'inaktiv') === 'aktiv') {
            if (!self::legacyBackApplies($legacy['stock'])) {
                // Andere Richtung (Kasse -> Shopify): der alte Job bucht dort Shopify-Bestellungen im Kassensystem ab
                $rows[] = ['area' => 'Lagerbestand', 'label' => 'Rückbuchung (Richtung Kasse → Shopify)', 'legacy' => 'an', 'connect' => '–', 'status' => self::STATUS_UNSUPPORTED, 'note' => 'Dieser Teil von cron_sync_stock_back (Shopify-Bestellung → Kassenbestand) ist in Connect nicht gebaut – dieser Shop kann noch nicht umziehen.'];
            } elseif (!$this->hasColumn('stock_back_enabled')) {
                $rows[] = ['area' => 'Lagerbestand', 'label' => 'Rückbuchung bei Kassenverkauf', 'legacy' => 'an', 'connect' => '–', 'status' => self::STATUS_MANUAL, 'note' => 'Braucht Migration 0104 (php connect/bin/install.php), danach wird der Wert übernommen.'];
            } elseif (!$this->invoiceReadObjectExists()) {
                $rows[] = ['area' => 'Lagerbestand', 'label' => 'Kassenrechnungen lesen (Connector Builder)', 'legacy' => 'nötig', 'connect' => 'fehlt', 'status' => self::STATUS_MANUAL, 'note' => 'Im Connector Builder (helloCash) das Object „' . \Connect\Sync\StockBackRunner::INVOICE_OBJECT . '“ mit read_list und read_one für GET /invoices anlegen – sonst pausiert die Rückbuchung (siehe HANDOFF.md).'];
            }
        }
        return $rows;
    }

    /**
     * Uebernimmt alles aus plan() mit Status "change".
     *
     * @return list<string> Hinweise fuer die Rueckmeldung
     */
    public function apply(string $shop, ConnectorFactory $factory): array
    {
        $legacy = $this->loadLegacy($shop);
        if ($legacy === null) {
            throw new \RuntimeException("Shop \"{$shop}\" ist in der alten Welt nicht zu finden.");
        }
        if ($this->connectShop($shop) === null) {
            throw new \RuntimeException("Shop \"{$shop}\" ist noch nicht in Connect – zuerst unter Shops „Token übernehmen“.");
        }
        $notes = [];

        // 1. HelloCash-Connection (nur anlegen, nie eine bestehende ueberschreiben)
        $hcConnection = $this->helloCashConnection($shop);
        // Vom Merchant vorab hinterlegter, fuer API v2 gepruefter Token (public/prepare.php,
        // Migration 0084) hat Vorrang vor dem v1-Token der alten Welt.
        $accessCheck = new \Connect\Setup\TargetAccessCheck($this->pdo, $this->crypto, $factory);
        $preparedToken = $accessCheck->preparedToken($shop);
        $token = $preparedToken ?? trim((string) ($legacy['binding']['token'] ?? ''));
        if ($hcConnection === null && $token !== '') {
            $connectionId = $this->createHelloCashConnection($shop, $token);
            $notes[] = $preparedToken !== null ? 'HelloCash-Connection mit dem neuen Token des Merchants angelegt.' : 'HelloCash-Connection angelegt (Token der alten Welt).';
            if ($preparedToken !== null) {
                $accessCheck->markApplied($shop);
            }
            $access = $accessCheck->checkConnection($connectionId);
            if (!$access['ok']) {
                $notes[] = 'Achtung, dem Token fehlen Berechtigungen für: ' . implode(', ', $access['missing'] !== [] ? $access['missing'] : [(string) $access['error']]) . ' – Merchant braucht einen neuen helloCash-Token.';
            }
            try {
                $result = $factory->forConnection($connectionId)->testConnection();
                $this->pdo->prepare('UPDATE connect_connections SET status = :status, last_test_at = CURRENT_TIMESTAMP, last_test_result = :result WHERE id = :id')
                    ->execute(['status' => $result->success ? 'active' : 'error', 'result' => $result->message, 'id' => $connectionId]);
                $notes[] = $result->success ? 'Verbindungstest HelloCash erfolgreich.' : 'Verbindungstest HelloCash fehlgeschlagen: ' . $result->message;
                if ($result->success) {
                    \Connect\Sync\JobScheduler::afterSuccessfulTest($this->pdo, $connectionId, 'pending');
                    $label = \Connect\Setup\ConnectionAccount::refresh($this->pdo, $factory, $connectionId);
                    if ($label !== null) {
                        $notes[] = "Bezeichnung aus HelloCash übernommen: „{$label}“.";
                    }
                }
            } catch (\Throwable $e) {
                $notes[] = 'Verbindungstest HelloCash nicht möglich: ' . $e->getMessage();
            }
            $hcConnection = $this->helloCashConnection($shop);
        }

        // 2. Shop-Einstellungen
        $values = $this->targetValues($shop, $legacy);
        if ($hcConnection !== null && ($this->connectShop($shop)['target_connection_id'] ?? null) === null) {
            $values['target_connection_id'] = (int) $hcConnection['id'];
        }
        $smtpPassword = $this->decryptLegacySmtpPassword((string) ($legacy['invoice']['smtp_password'] ?? ''));
        if ($smtpPassword !== null && $smtpPassword !== '') {
            $values['invoice_mail_smtp_password'] = $this->crypto->encrypt($smtpPassword);
        }
        $sets = [];
        $params = ['shop' => $shop];
        foreach ($values as $column => $value) {
            if (!preg_match('/^[a-z_]+$/', $column)) {
                continue;
            }
            $sets[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }
        if ($sets !== []) {
            $this->pdo->prepare('UPDATE connect_shops SET ' . implode(', ', $sets) . ' WHERE shop = :shop')->execute($params);
            $notes[] = count($sets) . ' Einstellungen übernommen.';

            // Einrichtungs-Assistent: was aus der alten Welt kommt, war dort schon eingerichtet
            $confirmed = [];
            if ($legacy['binding'] !== null) {
                array_push($confirmed, 'comparison', 'variants');
            }
            if ($legacy['stock'] !== null) {
                array_push($confirmed, 'stock_direction', 'stock_sync');
            }
            if ($legacy['invoice'] !== null) {
                array_push($confirmed, 'invoice', 'invoice_mail');
            }
            (new \Connect\Setup\SetupAssistant($this->pdo))->confirm($shop, $confirmed);

            // In der alten Welt eingerichtet: keine Einrichtung fuer neue Merchants (Migration 0078)
            try {
                $this->pdo->prepare('UPDATE connect_shops SET setup_completed_at = COALESCE(setup_completed_at, CURRENT_TIMESTAMP) WHERE shop = :shop')
                    ->execute(['shop' => $shop]);
            } catch (\PDOException) {
                // Migration 0078 noch nicht eingespielt
            }
        }

        $shopRow = $this->connectShop($shop) ?? [];

        // 2b. Zahlungsart im Kassensystem anlegen, falls sie fehlt (API v2 lehnt unbekannte ab). Braucht die
        // eben gespeicherten Einstellungen und die Ziel-Connection; schlaegt es fehl, wird nur berichtet.
        if ((int) ($shopRow['invoice_creation_enabled'] ?? 0) === 1 && (int) ($shopRow['invoice_suppress_and_adjust_stock'] ?? 0) === 0 && ($shopRow['target_connection_id'] ?? null) !== null) {
            $ensured = (new \Connect\Sync\PaymentMethodEnsurer($this->pdo, $factory))->ensureForShop($shop);
            if ($ensured['status'] === 'created') {
                $notes[] = $ensured['message'];
            } elseif ($ensured['status'] === 'failed') {
                $notes[] = 'Achtung: ' . $ensured['message'];
            } elseif ($ensured['status'] === 'unavailable') {
                $notes[] = 'Zahlungsart nicht geprüft: ' . $ensured['message'];
            }
        }

        // 3. Aktivierungen (gleiche Zeilen, wie sie die Einstellungsseite anlegen wuerde)
        $shopify = $this->pdo->prepare(
            "SELECT c.id, o_order.id AS order_object_id, o_product.id AS product_object_id
             FROM connect_connections c
             JOIN connect_connector_types t ON t.id = c.connector_type_id AND t.type_key = 'shopify'
             LEFT JOIN connect_objects o_order ON o_order.connector_type_id = t.id AND o_order.object_key = 'order'
             LEFT JOIN connect_objects o_product ON o_product.connector_type_id = t.id AND o_product.object_key = 'product'
             WHERE c.shop = :shop LIMIT 1"
        );
        $shopify->execute(['shop' => $shop]);
        $shopifyRow = $shopify->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($shopifyRow !== null && (int) ($shopRow['invoice_creation_enabled'] ?? 0) === 1 && ($shopRow['target_connection_id'] ?? null) !== null) {
            $existing = $this->pdo->prepare("SELECT id FROM connect_sync_mappings WHERE shop = :shop AND mapping_kind = 'invoice'");
            $existing->execute(['shop' => $shop]);
            if ($existing->fetchColumn() === false) {
                // Gleiche Beleg-Vorlage wie die uebrigen Shops mit diesem Zielsystem, sonst die
                // vorhandene Beleg-Vorlage des Zielsystems (Connect\Setup\ShopActivations)
                $typeStmt = $this->pdo->prepare('SELECT connector_type_id FROM connect_connections WHERE id = :id');
                $typeStmt->execute(['id' => (int) $shopRow['target_connection_id']]);
                $templateId = \Connect\Setup\ShopActivations::defaultInvoiceTemplateId($this->pdo, (int) $typeStmt->fetchColumn()) ?? false;
                $this->pdo->prepare(
                    "INSERT INTO connect_sync_mappings
                        (shop, name, source_connection_id, source_object_id, target_connection_id, mapping_template_id, trigger_type, trigger_topic,
                         schedule_interval_minutes, settings, enabled, managed_by, mapping_kind)
                     VALUES (:shop, 'Rechnungserstellung (HelloCash)', :source, :object, :target, :template, 'webhook', 'orders/paid',
                         :interval, :settings, 1, 'system', 'invoice')"
                )->execute([
                    'shop' => $shop,
                    'source' => (int) $shopifyRow['id'],
                    'object' => (int) $shopifyRow['order_object_id'],
                    'target' => (int) $shopRow['target_connection_id'],
                    'template' => $templateId !== false ? (int) $templateId : null,
                    // Nachlauf stuendlich, nur fuer Bestellungen ab jetzt bzw. ab dem Umschalten --
                    // alles davor rechnet die alte Welt ab (SyncRunner::backfillSince())
                    'interval' => \Connect\Setup\ShopActivations::INVOICE_BACKFILL_MINUTES,
                    'settings' => json_encode(['backfill_since' => time()]),
                ]);
                $notes[] = 'Rechnungs-Aktivierung angelegt' . ($templateId !== false ? ' (mit derselben Beleg-Vorlage wie die übrigen Shops).' : ' (ohne Vorlage – bisheriger Weg).');
            }
        }
        if ($shopifyRow !== null && (int) ($shopRow['stock_sync_enabled'] ?? 0) === 1) {
            $existing = $this->pdo->prepare("SELECT id FROM connect_sync_mappings WHERE shop = :shop AND mapping_kind = 'stock_sync'");
            $existing->execute(['shop' => $shop]);
            if ($existing->fetchColumn() === false) {
                $this->pdo->prepare(
                    "INSERT INTO connect_sync_mappings
                        (shop, name, source_connection_id, source_object_id, mapping_kind, trigger_type, schedule_interval_minutes, enabled, managed_by)
                     VALUES (:shop, 'Lagerbestand-Abgleich', :source, :object, 'stock_sync', 'schedule', 60, 1, 'system')"
                )->execute(['shop' => $shop, 'source' => (int) $shopifyRow['id'], 'object' => (int) $shopifyRow['product_object_id']]);
                $notes[] = 'Lagerbestand-Abgleich angelegt.';
            }
        }
        if ((int) ($shopRow['stock_back_enabled'] ?? 0) === 1) {
            (new \Connect\Setup\ShopActivations($this->pdo, $this->crypto, $this->config))->syncStockBackMapping($shop, true);
            $notes[] = 'Rückbuchung bei Kassenverkauf aktiviert (ab jetzt, alle ' . \Connect\Setup\ShopActivations::STOCK_BACK_MINUTES . ' Minuten).';
        }

        // 4. Abo: bleibt bei Shopify bestehen (dieselbe App). Jetzt abfragen, damit Connect den
        // Plan kennt -- setzt das Lagerabgleich-Intervall (z.B. 30 Min. bei "+") und verhindert,
        // dass ein spaeterer Wechsel faelschlich als erstes Abo mit 14 Tagen Test gilt.
        try {
            $billing = (new \Connect\Billing\BillingService($this->pdo, $factory, $this->config))->refresh($shop);
            if ($billing['status'] === 'none') {
                $notes[] = 'Kein aktives Shopify-Abo gefunden – der Merchant muss in Connect unter „Abo“ eines wählen.';
            } elseif ($billing['plan'] === null) {
                $notes[] = 'Abo übernommen: „' . (string) $billing['name'] . '“ (Name unbekannt, Connect nutzt die Standardwerte).';
            } else {
                $notes[] = 'Abo übernommen: „' . (string) $billing['name'] . '“' . ($billing['test'] ? ' (Test)' : '')
                    . ', Lagerabgleich alle ' . \Connect\Billing\Plans::stockMinutes($billing['plan']) . ' Minuten.';
            }
        } catch (\Throwable $e) {
            $notes[] = 'Abo konnte nicht abgefragt werden (' . $e->getMessage() . ') – wird beim nächsten Öffnen der Übersicht nachgeholt.';
        }

        return $notes;
    }

    /**
     * Zeile "Zahlungsart in helloCash": existiert sie im Konto? Prueft live (nur lesend) mit der
     * bestehenden Connection, sonst mit dem vorab hinterlegten bzw. dem Token der alten Welt.
     * Ein v1-Token hat keine v2-Rechte -- dann nicht pruefbar, das steht in der Zeile.
     *
     * @param array<string, mixed> $legacy
     * @param array<string, mixed>|null $hcConnection
     * @return array{area: string, label: string, legacy: string, connect: string, status: string, note: ?string}
     */
    private function paymentMethodRow(string $name, string $shop, array $legacy, ?array $hcConnection, ConnectorFactory $factory): array
    {
        $row = ['area' => 'Rechnung', 'label' => 'Zahlungsart in helloCash', 'legacy' => $name !== '' ? $name : '–', 'connect' => 'nicht prüfbar', 'status' => self::STATUS_MANUAL, 'note' => null];
        if ($name === '') {
            $row['note'] = 'Keine Zahlungsart eingestellt – Rechnungen gehen ohne Zahlungsart raus.';
            $row['status'] = self::STATUS_SAME;
            $row['connect'] = '–';

            return $row;
        }

        try {
            $connector = null;
            if ($hcConnection !== null) {
                $connector = $factory->forConnection((int) $hcConnection['id']);
            } else {
                $accessCheck = new \Connect\Setup\TargetAccessCheck($this->pdo, $this->crypto, $factory);
                $token = $accessCheck->preparedToken($shop) ?? trim((string) ($legacy['binding']['token'] ?? ''));
                $typeId = \Connect\Setup\TargetAccessCheck::helloCashTypeId($this->pdo);
                if ($token === '' || $typeId === null) {
                    $row['note'] = 'Ohne helloCash-Token nicht prüfbar.';

                    return $row;
                }
                [$credentials] = \Connect\Setup\TargetAccessCheck::buildCredentials($this->pdo, $this->crypto, $typeId, $shop, $token);
                $connector = $factory->forCredentials($typeId, $credentials);
            }

            if (!$connector instanceof \Connect\Connector\Generic\GenericHttpConnector || !$connector->hasOperation('payment_method', 'read_list')) {
                $row['note'] = 'Im Connector Builder (helloCash) fehlt das Object „payment_method“ mit „Lesen (Liste)“ – dann kann Connect die Zahlungsarten nicht prüfen.';

                return $row;
            }

            $found = false;
            foreach ($connector->read('payment_method') as $record) {
                $status = strtolower((string) ($record['status'] ?? 'active'));
                if (strcasecmp(trim((string) ($record['name'] ?? '')), $name) === 0 && in_array($status, ['active', '1', 'true'], true)) {
                    $found = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $row['note'] = preg_match('/403|scope/i', $message) === 1
                ? 'Der Token hat noch keine v2-Berechtigung für Zahlungsarten (alter v1-Token?) – prüfbar, sobald der Händler einen v2-Token hinterlegt hat.'
                : 'Prüfung nicht möglich: ' . mb_substr($message, 0, 200);

            return $row;
        }

        if ($found) {
            $row['connect'] = 'in helloCash vorhanden';
            $row['status'] = self::STATUS_SAME;

            return $row;
        }

        $row['connect'] = 'fehlt in helloCash';
        $canCreate = $connector->hasOperation('payment_method', 'create');
        $row['status'] = $canCreate ? self::STATUS_CHANGE : self::STATUS_MANUAL;
        $row['note'] = $canCreate
            ? 'Wird beim Übernehmen automatisch in helloCash angelegt (API v2 lehnt unbekannte Zahlungsarten ab).'
            : 'Fehlt in helloCash und Connect kann sie nicht anlegen (im Connector Builder fehlt „Anlegen“ am Object „payment_method“) – von Hand in helloCash anlegen.';

        return $row;
    }

    // =========================================================================
    // Interna
    // =========================================================================

    /**
     * Zielwerte fuer connect_shops aus den Einstellungen der alten Welt.
     *
     * @param array<string, mixed> $legacy
     * @return array<string, mixed>
     */
    private function targetValues(string $shop, array $legacy): array
    {
        $values = [];
        $binding = $legacy['binding'];
        $inv = $legacy['invoice'];
        $stock = $legacy['stock'];

        if ($binding !== null) {
            $values['comparison_key'] = ['title', 'sku', 'barcode'][(int) ($binding['mapping'] ?? 1)] ?? 'sku';
            $variant = (int) ($binding['mapping_variant'] ?? 0);
            $values['variant_comparison_key'] = $variant === 1 ? 'sku' : ($variant === 2 ? 'barcode' : null);
        }
        if ($inv !== null) {
            $values['invoice_creation_enabled'] = ($inv['enabled'] ?? '') === 'aktiv' ? 1 : 0;
            $values['invoice_cashier_id'] = (int) ($inv['cashier_id'] ?? 0) > 0 ? (int) $inv['cashier_id'] : null;
            $values['invoice_payment_method'] = trim((string) ($inv['payment_method'] ?? '')) !== '' ? (string) $inv['payment_method'] : 'Webshop';
            $values['invoice_pos_only'] = ($inv['pos_only'] ?? 'false') === 'true' ? 1 : 0;
            $values['invoice_suppress_and_adjust_stock'] = ($inv['suppress_invoice'] ?? 'false') === 'true' ? 1 : 0;
            if ($this->hasSignatureColumn()) {
                $values['invoice_signature_mandatory'] = ($inv['signature_mandatory'] ?? 'true') === 'true' ? 1 : 0;
            }
            // Kundenzuordnung (Migration 0095): die alte Welt hatte "nur E-Mail" fest im Code fuer
            // einzelne Shops (get_hellocash_user_id(), $email_only_shops), sonst Name + Adresse
            if ($this->hasColumn('invoice_customer_match')) {
                $values['invoice_customer_match'] = in_array($shop, self::LEGACY_EMAIL_ONLY_SHOPS, true)
                    ? \Connect\Sync\InvoiceService::CUSTOMER_MATCH_EMAIL
                    : \Connect\Sync\InvoiceService::CUSTOMER_MATCH_EMAIL_NAME;
            }
            $values['invoice_error_notification_email'] = trim((string) ($inv['error_notification_email'] ?? '')) !== '' ? (string) $inv['error_notification_email'] : null;
            // Seit Migration 0079 liest Connect die Benachrichtigungs-Adresse aus notify_email
            if ($values['invoice_error_notification_email'] !== null && $this->hasColumn('notify_email')) {
                $values['notify_email'] = $values['invoice_error_notification_email'];
            }
            $values['invoice_mail_enabled'] = ($inv['send_mail'] ?? '') === 'on' ? 1 : 0;
            $values['invoice_mail_sender_name'] = self::nullIfEmpty($inv['mail_sender'] ?? null);
            $values['invoice_mail_sender_address'] = self::nullIfEmpty($inv['mail_sender_address'] ?? null);
            $values['invoice_mail_subject'] = self::nullIfEmpty($inv['mail_subject'] ?? null);
            $values['invoice_mail_message'] = self::nullIfEmpty($inv['mail_message'] ?? null);
            $values['invoice_mail_smtp_host'] = self::nullIfEmpty($inv['smtp_host'] ?? null);
            $values['invoice_mail_smtp_port'] = (int) ($inv['smtp_port'] ?? 0) > 0 && self::nullIfEmpty($inv['smtp_host'] ?? null) !== null ? (int) $inv['smtp_port'] : null;
            $values['invoice_mail_smtp_username'] = self::nullIfEmpty($inv['smtp_username'] ?? null);
            $values['invoice_mail_smtp_encryption'] = in_array($inv['smtp_encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? (string) $inv['smtp_encryption'] : 'tls';
        }
        if ($stock !== null) {
            $values['stock_sync_enabled'] = ($stock['enabled'] ?? '') === 'aktiv' ? 1 : 0;
            $values['stock_sync_direction'] = ($stock['direction'] ?? '') === 'shopify->helloCash' ? 'shopify_to_target' : 'target_to_shopify';
            // Wie die alte Welt (get_selected_location_id(), cron_sync_stock.php): ist im Lager-Setup kein
            // Standort gewaehlt (leer oder 0), gilt der Standort aus der Artikel-Bindung (hellocash_binding.id_location)
            $legacyLocation = trim((string) ($stock['primary_location'] ?? ''));
            if ($legacyLocation === '' || $legacyLocation === '0') {
                $legacyLocation = trim((string) ($binding['id_location'] ?? ''));
            }
            $values['stock_primary_location_id'] = $this->mapLocation($shop, $legacyLocation);

            // Rueckbuchung bei Kassenverkauf (Migration 0104): nur bei Richtung Shopify -> Kasse, dort ersetzt
            // sie den alten Job. Startzeitpunkt = jetzt, damit alte Rechnungen nicht nachtraeglich laufen.
            if ($this->hasColumn('stock_back_enabled') && self::legacyBackApplies($stock)) {
                $values['stock_back_enabled'] = 1;
                $values['stock_back_dummy_order'] = ($stock['dummy_order'] ?? '') === 'aktiv' ? 1 : 0;
                // nur beim ersten Uebernehmen setzen -- ein erneutes Uebernehmen darf den Start nicht nach hinten schieben
                if (($this->connectShop($shop)['stock_back_since'] ?? null) === null) {
                    $values['stock_back_since'] = date('Y-m-d H:i:s');
                }
            }
        }

        return $values;
    }

    /**
     * connect_shops-Spalte => [Bereich, Bezeichnung, Anzeige-Formatierer]
     *
     * @return array<string, array{0: string, 1: string, 2: callable}>
     */
    private function fieldDefinitions(): array
    {
        $onOff = static fn ($v): string => $v === null ? '–' : ((int) $v === 1 ? 'an' : 'aus');
        $text = static fn ($v): string => $v === null || $v === '' ? '–' : mb_strimwidth((string) $v, 0, 80, '…');
        $compare = static fn ($v): string => ['title' => 'Produktname', 'sku' => 'SKU', 'barcode' => 'Barcode'][(string) $v] ?? ($v === null ? '–' : (string) $v);
        $direction = static fn ($v): string => ['shopify_to_target' => 'Shopify führt', 'target_to_shopify' => 'HelloCash führt'][(string) $v] ?? '–';

        return [
            'comparison_key' => ['Artikel', 'Vergleichswert', $compare],
            'variant_comparison_key' => ['Artikel', 'Varianten-Vergleichswert', $compare],
            'invoice_creation_enabled' => ['Rechnung', 'Automatische Rechnungsanlage', $onOff],
            'invoice_cashier_id' => ['Rechnung', 'Kassierer (HelloCash-ID)', $text],
            'invoice_payment_method' => ['Rechnung', 'Zahlungsart', $text],
            'invoice_pos_only' => ['Rechnung', 'Nur Kassenbestellungen (POS)', $onOff],
            'invoice_suppress_and_adjust_stock' => ['Rechnung', 'Keine Rechnung, nur Lagerbestand', $onOff],
            'invoice_signature_mandatory' => ['Rechnung', 'Signatur erforderlich (RKSV)', $onOff],
            'invoice_customer_match' => ['Rechnung', 'Kunden zuordnen über', static fn (mixed $v): string => \Connect\Sync\InvoiceService::CUSTOMER_MATCH_LABELS[(string) $v] ?? (string) $v],
            'invoice_error_notification_email' => ['Rechnung', 'Fehler-Benachrichtigung an', $text],
            'invoice_mail_enabled' => ['Rechnung · Mail', 'PDF-Mail an Kunden', $onOff],
            'invoice_mail_sender_name' => ['Rechnung · Mail', 'Absendername', $text],
            'invoice_mail_sender_address' => ['Rechnung · Mail', 'Absenderadresse', $text],
            'invoice_mail_subject' => ['Rechnung · Mail', 'Betreff', $text],
            'invoice_mail_message' => ['Rechnung · Mail', 'Nachricht', $text],
            'invoice_mail_smtp_host' => ['Rechnung · Mail', 'SMTP-Host', $text],
            'invoice_mail_smtp_port' => ['Rechnung · Mail', 'SMTP-Port', $text],
            'invoice_mail_smtp_username' => ['Rechnung · Mail', 'SMTP-Benutzer', $text],
            'invoice_mail_smtp_encryption' => ['Rechnung · Mail', 'SMTP-Verschlüsselung', $text],
            'stock_sync_enabled' => ['Lagerbestand', 'Automatischer Abgleich', $onOff],
            'stock_sync_direction' => ['Lagerbestand', 'Richtung', $direction],
            'stock_primary_location_id' => ['Lagerbestand', 'Primärer Standort', $text],
            'stock_back_enabled' => ['Lagerbestand', 'Rückbuchung bei Kassenverkauf', $onOff],
            'stock_back_dummy_order' => ['Lagerbestand', 'Dummy-Bestellung in Shopify', $onOff],
        ];
    }

    /**
     * Standort der alten Welt (meist nur die Zahl) auf die Form in Connect bringen
     * (wie in der Standort-Auswahl der Einstellungsseite, ObjectCache "location").
     */
    private function mapLocation(string $shop, string $legacyLocation): ?string
    {
        $legacyLocation = trim($legacyLocation);
        if ($legacyLocation === '' || $legacyLocation === '0') {
            return null;
        }
        $numeric = preg_replace('/^.*\//', '', $legacyLocation) ?? $legacyLocation;
        $stmt = $this->pdo->prepare(
            "SELECT r.data FROM connect_object_records r
             JOIN connect_connections c ON c.id = r.connection_id
             JOIN connect_connector_types t ON t.id = c.connector_type_id AND t.type_key = 'shopify'
             WHERE c.shop = :shop AND r.object_key = 'location'"
        );
        $stmt->execute(['shop' => $shop]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $data = json_decode((string) $json, true);
            $id = is_array($data) ? (string) ($data['shopify_id'] ?? '') : '';
            if ($id !== '' && ($id === $legacyLocation || preg_replace('/^.*\//', '', $id) === $numeric)) {
                return $id;
            }
        }

        return ctype_digit($numeric) ? 'gid://shopify/Location/' . $numeric : $legacyLocation;
    }

    private function createHelloCashConnection(string $shop, string $token): int
    {
        $type = $this->pdo->query("SELECT id, auth_type, auth_field_schema, base_url_template FROM connect_connector_types WHERE type_key = 'hellocash'")->fetch(PDO::FETCH_ASSOC);
        if ($type === false) {
            throw new \RuntimeException('Connector Type "hellocash" fehlt in Connect.');
        }

        // Zugangsdaten nach dem Formular-Schema des Connector Types (siehe dort)
        [$credentials] = \Connect\Setup\TargetAccessCheck::buildCredentials($this->pdo, $this->crypto, (int) $type['id'], $shop, $token);

        $this->pdo->prepare(
            "INSERT INTO connect_connections (shop, connector_type_id, label, credentials, config, status, created_at)
             VALUES (:shop, :type, 'HelloCash (aus alter Welt übernommen)', :credentials, :config, 'pending', CURRENT_TIMESTAMP)"
        )->execute(['shop' => $shop, 'type' => (int) $type['id'], 'credentials' => $this->crypto->encryptArray($credentials), 'config' => json_encode([])]);
        $connectionId = (int) $this->pdo->lastInsertId();

        // Job-Vorlagen mit auto_activate (z.B. HelloCash "Mitarbeiter"), wie beim Anlegen im Admin
        $templates = $this->pdo->prepare('SELECT * FROM connect_sync_job_templates WHERE connector_type_id = :type AND auto_activate = 1');
        $templates->execute(['type' => (int) $type['id']]);
        foreach ($templates->fetchAll(PDO::FETCH_ASSOC) as $jobTemplate) {
            $this->pdo->prepare(
                "INSERT INTO connect_sync_mappings (shop, name, source_connection_id, source_object_id, sync_job_template_id, trigger_type, schedule_interval_minutes, enabled, managed_by)
                 VALUES (:shop, :name, :connection, :object, :template, 'schedule', :interval, 1, 'system')"
            )->execute([
                'shop' => $shop, 'name' => $jobTemplate['name'], 'connection' => $connectionId, 'object' => (int) $jobTemplate['object_id'],
                'template' => (int) $jobTemplate['id'], 'interval' => (int) $jobTemplate['default_interval_minutes'],
            ]);
        }

        return $connectionId;
    }

    /**
     * SMTP-Passwort der alten Welt (inc/crypto_helper.php: AES-256-CBC, base64(IV . Cipher))
     * entschluesseln -- nur mit LEGACY_SMTP_ENCRYPTION_KEY in connect/.env (= SMTP_ENCRYPTION_KEY
     * der alten Welt). null = nicht moeglich.
     */
    private function decryptLegacySmtpPassword(string $encoded): ?string
    {
        $key = $this->config->optional('LEGACY_SMTP_ENCRYPTION_KEY', '');
        if ($encoded === '' || $key === '') {
            return null;
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            return null;
        }
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $plain = openssl_decrypt(substr($raw, $ivLength), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($raw, 0, $ivLength));

        return $plain === false ? null : $plain;
    }

    /** @return array<string, mixed>|null */
    private function connectShop(string $shop): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM connect_shops WHERE shop = :shop');
        $stmt->execute(['shop' => $shop]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function helloCashConnection(string $shop): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.label, c.status FROM connect_connections c
             JOIN connect_connector_types t ON t.id = c.connector_type_id AND t.type_key = 'hellocash'
             WHERE c.shop = :shop ORDER BY c.id LIMIT 1"
        );
        $stmt->execute(['shop' => $shop]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    private ?bool $signatureColumn = null;

    /** Migration 0072 eingespielt? */
    private function hasSignatureColumn(): bool
    {
        if ($this->signatureColumn === null) {
            $this->signatureColumn = $this->pdo->query("SHOW COLUMNS FROM connect_shops LIKE 'invoice_signature_mandatory'")->fetch() !== false;
        }

        return $this->signatureColumn;
    }

    /** Spalte in connect_shops vorhanden? (neuere Migrationen, z.B. 0079 notify_email) */
    /** Gilt der Rueckbuchungs-Job der alten Welt fuer diesen Shop (Richtung Shopify -> Kasse)? */
    private static function legacyBackApplies(array $stock): bool
    {
        return strtolower((string) ($stock['direction'] ?? '')) === 'shopify->hellocash';
    }

    /** Gibt es im Connector Builder das Object zum Lesen der Kassenrechnungen? */
    private function invoiceReadObjectExists(): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM connect_objects o JOIN connect_connector_types t ON t.id = o.connector_type_id
             WHERE o.object_key = :key AND t.type_key <> 'shopify' LIMIT 1"
        );
        $stmt->execute(['key' => \Connect\Sync\StockBackRunner::INVOICE_OBJECT]);

        return $stmt->fetchColumn() !== false;
    }
    private function hasColumn(string $column): bool
    {
        if (!preg_match('/^[a-z_]+$/', $column)) {
            return false;
        }

        return $this->pdo->query("SHOW COLUMNS FROM connect_shops LIKE '{$column}'")->fetch() !== false;
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }

    private static function normalize(mixed $value): string
    {
        return $value === null ? '' : trim((string) $value);
    }
}
