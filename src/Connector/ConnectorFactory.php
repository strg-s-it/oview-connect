<?php

declare(strict_types=1);

namespace Connect\Connector;

use Connect\Connector\Generic\GenericHttpConnector;
use Connect\Http\HttpClient;
use Connect\Http\RateLimitConfig;
use Connect\Http\RateLimiter;
use Connect\Security\Crypto;
use Connect\Shopify\TokenRefresher;
use PDO;

/**
 * Baut aus einer gespeicherten Connection (connect_connections) einen
 * einsatzbereiten Connector auf: laedt den zugehoerigen Connector Type samt
 * Objekten/Feldern/Operationen aus der DB, entschluesselt die Zugangsdaten
 * und liefert eine fertige ConnectorInterface-Instanz.
 *
 * Zentrale Stelle fuer diese Zusammenbau-Logik, damit sie nicht in jeder
 * Seite (Connections-UI, spaeter SyncRunner) dupliziert wird. Erneuert dabei
 * auch bei Bedarf ablaufende Shopify-Access-Tokens (siehe TokenRefresher) --
 * jeder Aufrufer (Live-Seite oder spaeter ein Cronjob) bekommt so garantiert
 * einen gueltigen Connector, ohne sich selbst um Tokens kuemmern zu muessen.
 */
final class ConnectorFactory
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Crypto $crypto,
        private readonly HttpClient $httpClient = new HttpClient(),
        private readonly ?TokenRefresher $tokenRefresher = null
    ) {
    }

    public function forConnection(int $connectionId): ConnectorInterface
    {
        $connectionStmt = $this->pdo->prepare('SELECT * FROM connect_connections WHERE id = :id');
        $connectionStmt->execute(['id' => $connectionId]);
        $connection = $connectionStmt->fetch();

        if ($connection === false) {
            throw new \RuntimeException("Connection nicht gefunden: {$connectionId}");
        }

        $typeStmt = $this->pdo->prepare('SELECT * FROM connect_connector_types WHERE id = :id');
        $typeStmt->execute(['id' => $connection['connector_type_id']]);
        $connectorType = $typeStmt->fetch();

        if ($connectorType === false) {
            throw new \RuntimeException("Connector Type nicht gefunden: {$connection['connector_type_id']}");
        }

        $credentials = $this->crypto->decryptArray((string) $connection['credentials']);

        // Shopify-Connections duerfen NUR mit dem eigenen <shop>.myshopify.com sprechen. Das gilt vor allem
        // fuer den Token-Refresh unten: der schickt das App-Secret (client_secret) an die Adresse aus
        // base_url -- eine manipulierte Adresse wuerde es preisgeben. Geprueft VOR jedem Aufruf.
        if (($connectorType['type_key'] ?? '') === 'shopify') {
            self::assertShopifyHost((string) ($credentials['base_url'] ?? ''), (string) $connection['shop'], $connectionId);
        }

        // Erneuert bei Bedarf ein ablaufendes Shopify-Access-Token (siehe
        // TokenRefresher-Kommentar) -- fuer Connections ohne refresh_token
        // (z.B. MGM) ein No-Op.
        if ($this->tokenRefresher !== null) {
            $credentials = $this->tokenRefresher->ensureFresh($this->pdo, $this->crypto, $connectionId, $credentials);
        }

        $config = json_decode((string) $connection['config'], true) ?: [];

        return $this->build($connectorType, $credentials, $config, $connectionId);
    }

    /**
     * Connector mit Zugangsdaten, die (noch) in keiner Connection stehen -- z.B. ein Token, den
     * ein Merchant fuer den Umzug vorab hinterlegt (Connect\Setup\TargetAccessCheck). Nicht fuer
     * Shopify (dort gilt die Host-Pruefung aus forConnection()).
     *
     * @param array<string, mixed> $credentials
     */
    public function forCredentials(int $connectorTypeId, array $credentials): ConnectorInterface
    {
        $typeStmt = $this->pdo->prepare('SELECT * FROM connect_connector_types WHERE id = :id');
        $typeStmt->execute(['id' => $connectorTypeId]);
        $connectorType = $typeStmt->fetch();
        if ($connectorType === false) {
            throw new \RuntimeException("Connector Type nicht gefunden: {$connectorTypeId}");
        }
        if (($connectorType['type_key'] ?? '') === 'shopify') {
            throw new \RuntimeException('forCredentials() ist nicht fuer Shopify gedacht.');
        }

        return $this->build($connectorType, $credentials, [], null);
    }

    /**
     * @param array<string, mixed> $connectorType
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $config
     */
    private function build(array $connectorType, array $credentials, array $config, ?int $connectionId): ConnectorInterface
    {
        // Basis-URL: Connection-eigener Wert (z.B. MGM: kundenspezifische
        // IP:Port aus den Zugangsdaten) hat Vorrang vor einem evtl. beim
        // Connector Type hinterlegten Template.
        $baseUrl = $credentials['base_url'] ?? $config['base_url'] ?? $connectorType['base_url_template'] ?? '';
        if ($baseUrl === '') {
            throw new \RuntimeException('Keine Basis-URL fuer Connection ' . ($connectionId ?? '(neu)') . ' ermittelbar.');
        }

        // TLS-Zertifikatspruefung ist per Default AN -- nur wenn eine
        // Connection sie explizit deaktiviert hat (config.verify_ssl=false,
        // siehe _connections_body.php), wird ein eigener HttpClient nur fuer
        // DIESE eine Connection ohne Pruefung verwendet. Fuer interne Server
        // mit selbstsigniertem Zertifikat (z.B. MGM/AGILA per IP:Port), bei
        // denen eine echte Zertifikatskette nicht verfuegbar ist.
        $verifySsl = ($config['verify_ssl'] ?? true) !== false;
        $httpClient = $verifySsl ? $this->httpClient : $this->httpClient->withVerifySsl(false);

        // Feste Absender-IP des Systems (Migration 0081): z.B. MGM laesst beim Kunden nur die
        // IP von main durch -- dann gehen alle Anfragen von dort hinaus, egal welcher Worker den
        // Job ausfuehrt. Leer bzw. Spalte fehlt = IP des Workers (CONNECT_OUTBOUND_IP).
        $fixedOutboundIp = trim((string) ($connectorType['outbound_ip'] ?? ''));
        if ($fixedOutboundIp !== '') {
            $httpClient = $httpClient->withOutboundIp($fixedOutboundIp);
        }

        [$objects, $operations] = $this->loadSchema((int) $connectorType['id']);

        // Anfrage-Limit (siehe Http\RateLimiter): pro Connector Type konfigurierbar
        // (Migration 0053), Standard NULL = kein Limit. Der RateLimiter selbst kommt
        // aus $this->pdo -- dieselbe Verbindung, mit der die Connection oben geladen wurde.
        $rateLimitConfig = new RateLimitConfig(
            (int) $connectorType['id'],
            isset($connectorType['rate_limit_max_requests']) ? (int) $connectorType['rate_limit_max_requests'] : null,
            isset($connectorType['rate_limit_window_seconds']) ? (int) $connectorType['rate_limit_window_seconds'] : null
        );

        return new GenericHttpConnector(
            $baseUrl,
            (string) $connectorType['auth_type'],
            $credentials,
            (string) $connectorType['request_format'],
            $objects,
            $operations,
            $httpClient,
            new RateLimiter($this->pdo),
            $rateLimitConfig,
            // Verbrauchsstatistik je Connection (Migration 0068, admin/jobs.php "Shopify")
            new \Connect\Http\ApiUsageRecorder($this->pdo, (string) $connectorType['type_key']),
            $connectionId
        );
    }

    /**
     * Die Basis-URL einer Shopify-Connection muss genau https://<shop>.myshopify.com sein.
     *
     * @throws \RuntimeException
     */
    private static function assertShopifyHost(string $baseUrl, string $shop, int $connectionId): void
    {
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $expected = strtolower($shop) . '.myshopify.com';

        if ($host !== $expected) {
            throw new \RuntimeException(
                "Connection {$connectionId}: die Shopify-Basis-URL muss https://{$expected} sein (gesperrt, um Zugangsdaten und App-Secret zu schuetzen)."
            );
        }
    }

    /**
     * Laedt ein einzelnes Object samt Feldern anhand seiner ID -- fuer
     * Aufrufer (z.B. SyncRunner), die nur das eine Object brauchen und nicht
     * den kompletten Connector Type wie forConnection().
     */
    public function objectDefinitionById(int $objectId): ObjectDefinition
    {
        $objectStmt = $this->pdo->prepare('SELECT * FROM connect_objects WHERE id = :id');
        $objectStmt->execute(['id' => $objectId]);
        $objectRow = $objectStmt->fetch();

        if ($objectRow === false) {
            throw new \RuntimeException("Object nicht gefunden: {$objectId}");
        }

        $fieldRows = $this->pdo->prepare('SELECT * FROM connect_object_fields WHERE object_id = :id ORDER BY id');
        $fieldRows->execute(['id' => $objectId]);

        return new ObjectDefinition(
            (string) $objectRow['object_key'],
            (string) $objectRow['label'],
            self::buildFields($fieldRows->fetchAll()),
            (bool) $objectRow['hydrate_details_after_list'],
            $objectRow['hydrate_batch_size'] !== null ? (int) $objectRow['hydrate_batch_size'] : null
        );
    }

    /**
     * Baut FieldDefinitions fuer EIN Object aus seinen rohen DB-Zeilen und
     * loest dabei parent_field_id (die numerische ID des Eltern-Feldes) auf
     * den field_key des Eltern-Feldes auf -- FieldDefinition::$parentKey
     * dokumentiert sich selbst als "key" (siehe MGM-Beispiel "Positionen.zl"
     * im Klassenkommentar), nicht als rohe DB-ID. Vorher wurde hier
     * faelschlich die ID selbst (als String) durchgereicht -- folgenlos,
     * solange wiederholende Gruppen beim Lesen ohnehin uebersprungen wurden,
     * aber die Mapping Engine muss Kind-Felder ueber den field_key ihrer
     * Gruppe zuordnen koennen.
     *
     * @param array<int, array<string, mixed>> $fieldRows
     * @return FieldDefinition[]
     */
    private static function buildFields(array $fieldRows): array
    {
        $keyById = [];
        foreach ($fieldRows as $fieldRow) {
            $keyById[(int) $fieldRow['id']] = (string) $fieldRow['field_key'];
        }

        $fields = [];
        foreach ($fieldRows as $fieldRow) {
            $parentFieldId = $fieldRow['parent_field_id'] !== null ? (int) $fieldRow['parent_field_id'] : null;

            $fields[] = new FieldDefinition(
                (string) $fieldRow['field_key'],
                (string) $fieldRow['label'],
                (string) $fieldRow['data_type'],
                $fieldRow['field_path'] !== null ? (string) $fieldRow['field_path'] : null,
                (bool) $fieldRow['is_repeating_group'],
                $parentFieldId !== null ? ($keyById[$parentFieldId] ?? null) : null,
                (bool) ($fieldRow['is_identifier'] ?? false),
                $fieldRow['comparison_role'] !== null && $fieldRow['comparison_role'] !== '' ? (string) $fieldRow['comparison_role'] : null,
                (bool) ($fieldRow['is_stock_quantity'] ?? false),
                (bool) ($fieldRow['is_variant_option'] ?? false),
                isset($fieldRow['max_length']) && (int) $fieldRow['max_length'] > 0 ? (int) $fieldRow['max_length'] : null
            );
        }

        return $fields;
    }

    /**
     * Wie objectDefinitionById(), aber zusaetzlich mit der DB-ID jedes Feldes
     * als Schluessel -- gebraucht von der Mapping Engine (Connect\Mapping\MappingEngine),
     * die Felder ueber connect_field_mappings.source_field_id/target_field_id
     * (DB-IDs) referenziert, nicht ueber den field_key wie der Connector-
     * Laufzeit-Code (GenericHttpConnector kennt nur Keys, keine IDs).
     *
     * @return array<int, FieldDefinition>
     */
    public function fieldsByIdForObject(int $objectId): array
    {
        $fieldRows = $this->pdo->prepare('SELECT * FROM connect_object_fields WHERE object_id = :id ORDER BY id');
        $fieldRows->execute(['id' => $objectId]);
        $rows = $fieldRows->fetchAll();

        $fields = self::buildFields($rows);

        $byId = [];
        foreach ($rows as $index => $row) {
            $byId[(int) $row['id']] = $fields[$index];
        }

        return $byId;
    }

    /**
     * @return array{0: ObjectDefinition[], 1: OperationDefinition[]}
     */
    private function loadSchema(int $connectorTypeId): array
    {
        $objectRows = $this->pdo->prepare('SELECT * FROM connect_objects WHERE connector_type_id = :id');
        $objectRows->execute(['id' => $connectorTypeId]);
        $objectRows = $objectRows->fetchAll();

        $objects = [];
        $operations = [];

        foreach ($objectRows as $objectRow) {
            $fieldRows = $this->pdo->prepare('SELECT * FROM connect_object_fields WHERE object_id = :id ORDER BY id');
            $fieldRows->execute(['id' => $objectRow['id']]);

            $objects[] = new ObjectDefinition(
                (string) $objectRow['object_key'],
                (string) $objectRow['label'],
                self::buildFields($fieldRows->fetchAll()),
                (bool) $objectRow['hydrate_details_after_list'],
                $objectRow['hydrate_batch_size'] !== null ? (int) $objectRow['hydrate_batch_size'] : null
            );

            $operationRows = $this->pdo->prepare('SELECT * FROM connect_object_operations WHERE object_id = :id');
            $operationRows->execute(['id' => $objectRow['id']]);

            foreach ($operationRows->fetchAll() as $operationRow) {
                $operations[] = new OperationDefinition(
                    (string) $objectRow['object_key'],
                    (string) $operationRow['operation_type'],
                    (string) $operationRow['http_method'],
                    (string) $operationRow['path_template'],
                    $operationRow['body_template'] !== null ? (string) $operationRow['body_template'] : null,
                    $operationRow['response_records_path'] !== null ? (string) $operationRow['response_records_path'] : null,
                    $operationRow['static_params'] !== null ? (json_decode((string) $operationRow['static_params'], true) ?: []) : []
                );
            }
        }

        return [$objects, $operations];
    }
}
