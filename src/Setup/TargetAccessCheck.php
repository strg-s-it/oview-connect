<?php

declare(strict_types=1);

namespace Connect\Setup;

use Connect\Connector\ConnectorFactory;
use Connect\Connector\Generic\GenericHttpConnector;
use Connect\Security\Crypto;
use PDO;

/**
 * Hat ein Token fuer die Kasse (helloCash) Zugriff auf ALLE Objects, die Connect braucht?
 *
 * Hintergrund: die alte Welt nutzt helloCash API v1, die keine Berechtigungen je Token kennt.
 * Connect nutzt v2 -- dort fehlen alten Tokens Berechtigungen ("Insufficient scope", HTTP 403),
 * z.B. fuer Artikel. Der normale Verbindungstest merkt das nicht (er hoert beim ersten
 * lesbaren Object auf), deshalb hier jedes Object einzeln, nur lesend.
 *
 * Genutzt von:
 *   public/prepare.php   Merchant traegt vor dem Umzug einen neuen Token ein (Migration 0084)
 *   admin/migrate.php    Schritt 1 "Zugriff pruefen", Schritt 4 gesperrt bei fehlendem Zugriff
 *   ShopMigrator         nimmt beim Umzug den vorab hinterlegten Token statt des alten
 *
 * Ergebnis einer Connection steht in connect_connections.config.target_access
 * ({checked_at, ok, missing[], optional_missing[]}).
 */
final class TargetAccessCheck
{
    /** Objects, deren Fehlen nicht blockiert (nur Anzeige, z.B. Firmenname) */
    public const OPTIONAL_OBJECTS = ['account_info'];

    /** Bremse fuer public/prepare.php: Versuche je Shop und Stunde */
    public const MAX_ATTEMPTS_PER_HOUR = 10;

    /** Lese-Aufruf der alten Welt (helloCash API v1), um zu pruefen, ob sie mit dem Token weiterlaeuft */
    private const LEGACY_V1_PROBE_URL = 'https://api.hellocash.business/api/v1/articles?limit=1&offset=0';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Crypto $crypto,
        private readonly ConnectorFactory $factory,
        private readonly ?\Connect\Config $config = null,
        private readonly ?\Connect\Http\HttpClient $http = null,
    ) {
    }

    public static function helloCashTypeId(PDO $pdo): ?int
    {
        $id = $pdo->query("SELECT id FROM connect_connector_types WHERE type_key = 'hellocash' LIMIT 1")->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string} [Zugangsdaten, Schluessel des Tokens]
     */
    public function credentialsFromToken(int $connectorTypeId, string $shop, string $token): array
    {
        return self::buildCredentials($this->pdo, $this->crypto, $connectorTypeId, $shop, $token);
    }

    /**
     * Zugangsdaten nach dem Formular-Schema des Connector Types: feste (hidden) Werte, das erste
     * Passwortfeld bekommt den Token. Nicht-geheime Werte, die das Schema offen laesst (z.B.
     * Basis-Adresse), von einer bestehenden Connection desselben Typs eines anderen Shops --
     * NUR Felder, die das Schema als nicht geheim deklariert (Whitelist), plus base_url. Frueher
     * wurde alles uebernommen ausser einer festen Liste geheimer Namen; ein weiteres geheimes Feld
     * (z.B. client_secret) waere so aus dem fremden Shop in diesen gewandert.
     * Auch genutzt von ShopMigrator (Umzug).
     *
     * @return array{0: array<string, mixed>, 1: string} [Zugangsdaten, Schluessel des Tokens]
     */
    public static function buildCredentials(PDO $pdo, Crypto $crypto, int $connectorTypeId, string $shop, string $token): array
    {
        $typeStmt = $pdo->prepare('SELECT auth_type, auth_field_schema FROM connect_connector_types WHERE id = :id');
        $typeStmt->execute(['id' => $connectorTypeId]);
        $type = $typeStmt->fetch(PDO::FETCH_ASSOC);
        if ($type === false) {
            throw new \RuntimeException("Connector Type {$connectorTypeId} fehlt.");
        }

        $credentials = [];
        $secretKey = null;
        $copyable = ['base_url' => true];
        foreach (json_decode((string) $type['auth_field_schema'], true) ?: [] as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $fieldType = (string) ($field['type'] ?? 'text');
            if ($fieldType === 'hidden') {
                $credentials[$key] = (string) ($field['value'] ?? '');
            } elseif ($fieldType === 'password') {
                $secretKey ??= $key;
            } else {
                $copyable[$key] = true;
            }
        }
        $secretKey ??= ['bearer' => 'token', 'api_key' => 'header_value'][(string) $type['auth_type']] ?? 'token';
        $credentials[$secretKey] = $token;
        // Geheime Felder nie uebernehmen, auch wenn das Schema sie als "text" fuehrt (helloCash: token)
        foreach ([$secretKey, 'token', 'password', 'header_value', 'refresh_token', 'client_secret', 'api_key', 'secret'] as $secret) {
            unset($copyable[$secret]);
        }

        $template = $pdo->prepare("SELECT credentials FROM connect_connections WHERE connector_type_id = :type AND shop <> :shop ORDER BY status = 'active' DESC, id LIMIT 1");
        $template->execute(['type' => $connectorTypeId, 'shop' => $shop]);
        $templateEncrypted = $template->fetchColumn();
        if ($templateEncrypted !== false) {
            foreach ($crypto->decryptArray((string) $templateEncrypted) as $key => $value) {
                if (isset($copyable[$key]) && !array_key_exists($key, $credentials)) {
                    $credentials[$key] = $value;
                }
            }
        }

        return [$credentials, $secretKey];
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array{ok: bool, missing: list<string>, optional_missing: list<string>, objects: array<string, array{label: string, ok: bool, status: ?int, error: ?string}>, error: ?string}
     */
    public function checkCredentials(int $connectorTypeId, array $credentials): array
    {
        try {
            $connector = $this->factory->forCredentials($connectorTypeId, $credentials);
        } catch (\Throwable $e) {
            return self::summarize([], $e->getMessage());
        }

        return self::summarize($connector instanceof GenericHttpConnector ? $connector->checkReadAccess() : [], null);
    }

    /**
     * Bestehende Connection pruefen und das Ergebnis in config.target_access ablegen.
     *
     * @return array{ok: bool, missing: list<string>, optional_missing: list<string>, objects: array<string, array{label: string, ok: bool, status: ?int, error: ?string}>, error: ?string}
     */
    public function checkConnection(int $connectionId): array
    {
        try {
            $connector = $this->factory->forConnection($connectionId);
            $result = self::summarize($connector instanceof GenericHttpConnector ? $connector->checkReadAccess() : [], null);
        } catch (\Throwable $e) {
            $result = self::summarize([], $e->getMessage());
        }
        $this->storeOnConnection($connectionId, $result);

        return $result;
    }

    /** Gespeichertes Ergebnis einer Connection (null = nie geprueft). @return array<string, mixed>|null */
    public static function stored(?string $configJson): ?array
    {
        $config = json_decode((string) $configJson, true);

        return is_array($config) && isset($config['target_access']) && is_array($config['target_access']) ? $config['target_access'] : null;
    }

    /**
     * Token aus public/prepare.php. helloCash kennt nur EINEN Token je Konto: "Neu generieren"
     * macht den bisherigen sofort ungueltig. Deshalb wird ein gueltiger Token (liest in v1 ODER
     * in mindestens einem v2-Object) immer sofort ueberall eingesetzt -- auch wenn ihm noch
     * v2-Berechtigungen fehlen, der alte funktioniert ja nicht mehr:
     *   - verschluesselt in connect_prepared_credentials (fuer den spaeteren Umzug)
     *   - in der alten Welt (hellocash_binding.token, LegacyBridge::updateHelloCashToken)
     *   - in einer bestehenden helloCash-Connection in Connect
     * "ok" heisst weiterhin: v2-Zugriff vollstaendig (Voraussetzung fuers Umstellen).
     *
     * @return array{ok: bool, missing: list<string>, optional_missing: list<string>, objects: array<string, mixed>, error: ?string, valid: bool, v1_ok: ?bool, legacy: string, applied: bool, throttled: bool}
     *         legacy: updated | not_found | failed: <Grund> | skipped
     */
    public function prepare(string $shop, string $token): array
    {
        $none = ['valid' => false, 'v1_ok' => null, 'legacy' => 'skipped', 'applied' => false, 'throttled' => false];
        $typeId = self::helloCashTypeId($this->pdo);
        if ($typeId === null) {
            return self::summarize([], 'helloCash ist in Connect nicht eingerichtet.') + $none;
        }
        if ($this->throttled($shop, $typeId)) {
            return self::summarize([], 'Zu viele Versuche. Bitte in einer Stunde noch einmal.') + ['throttled' => true] + $none;
        }

        [$credentials, $secretKey] = $this->credentialsFromToken($typeId, $shop, $token);
        $result = $this->checkCredentials($typeId, $credentials);
        $v1Ok = $this->probeLegacyV1($token);
        $anyV2 = count(array_filter($result['objects'], static fn (array $o): bool => $o['ok'])) > 0;
        $valid = $v1Ok === true || $anyV2;

        $this->pdo->prepare(
            'UPDATE connect_prepared_credentials SET access = :access, access_ok = :ok,
                    token_encrypted = IF(:valid = 1, :token, token_encrypted), submitted_at = IF(:valid2 = 1, CURRENT_TIMESTAMP, submitted_at)
             WHERE shop = :shop'
        )->execute([
            'access' => json_encode($result['objects'], JSON_UNESCAPED_UNICODE),
            'ok' => $result['ok'] ? 1 : 0,
            'valid' => $valid ? 1 : 0,
            'token' => $this->crypto->encrypt($token),
            'valid2' => $valid ? 1 : 0,
            'shop' => $shop,
        ]);

        $legacy = 'skipped';
        $applied = false;
        if ($valid) {
            $legacy = $this->updateLegacy($shop, $token);
            $applied = $this->applyToConnection($shop, $typeId, $token, $secretKey, $result);
        } elseif ($result['error'] === null) {
            $result['error'] = 'Dieser Token wird von helloCash nicht angenommen. Bitte prüfen, ob er vollständig kopiert ist.';
        }

        return $result + ['valid' => $valid, 'v1_ok' => $v1Ok, 'legacy' => $legacy, 'applied' => $applied, 'throttled' => false];
    }

    /** Liest die alte Welt (API v1) mit diesem Token? null = nicht pruefbar. */
    private function probeLegacyV1(string $token): ?bool
    {
        try {
            $response = ($this->http ?? new \Connect\Http\HttpClient())->request('GET', self::LEGACY_V1_PROBE_URL, ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']);

            return $response->isSuccess();
        } catch (\Throwable) {
            return null;
        }
    }

    private function updateLegacy(string $shop, string $token): string
    {
        if ($this->config === null || !\Connect\Legacy\LegacyBridge::isConfigured($this->config)) {
            return 'failed: LEGACY_DB_* fehlt in connect/.env';
        }
        try {
            return \Connect\Legacy\LegacyBridge::updateHelloCashToken($this->config, $shop, $token) > 0 ? 'updated' : 'not_found';
        } catch (\Throwable $e) {
            error_log('[TargetAccessCheck] alte Welt, Token fuer ' . $shop . ': ' . $e->getMessage());

            return 'failed: ' . $e->getMessage();
        }
    }

    /**
     * Vorab hinterlegter, gueltiger Token eines Shops (oder null) -- auch wenn ihm noch
     * v2-Berechtigungen fehlen: nach "Neu generieren" ist es der einzige, der noch funktioniert.
     */
    public function preparedToken(string $shop): ?string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT token_encrypted FROM connect_prepared_credentials WHERE shop = :shop AND token_encrypted IS NOT NULL');
            $stmt->execute(['shop' => $shop]);
            $encrypted = $stmt->fetchColumn();

            return $encrypted !== false ? $this->crypto->decrypt((string) $encrypted) : null;
        } catch (\Throwable) {
            return null; // Migration 0084 fehlt
        }
    }

    /** @return array<string, mixed>|null Zeile ohne Token */
    public static function preparedRow(PDO $pdo, string $shop): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT shop, access, access_ok, submitted_at, applied_at, updated_at FROM connect_prepared_credentials WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row !== false ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Vorab-Token als in eine Connection uebernommen markieren (ShopMigrator). */
    public function markApplied(string $shop): void
    {
        try {
            $this->pdo->prepare('UPDATE connect_prepared_credentials SET applied_at = CURRENT_TIMESTAMP WHERE shop = :shop')->execute(['shop' => $shop]);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, array{label: string, ok: bool, status: ?int, error: ?string}> $objects
     * @return array{ok: bool, missing: list<string>, optional_missing: list<string>, objects: array<string, array{label: string, ok: bool, status: ?int, error: ?string}>, error: ?string}
     */
    private static function summarize(array $objects, ?string $error): array
    {
        $missing = [];
        $optionalMissing = [];
        foreach ($objects as $key => $object) {
            if (!$object['ok']) {
                if (in_array($key, self::OPTIONAL_OBJECTS, true)) {
                    $optionalMissing[] = $object['label'];
                } else {
                    $missing[] = $object['label'];
                }
            }
        }

        return [
            'ok' => $error === null && $objects !== [] && $missing === [],
            'missing' => $missing,
            'optional_missing' => $optionalMissing,
            'objects' => $objects,
            'error' => $error ?? ($objects === [] ? 'Keine lesbaren Objects definiert.' : null),
        ];
    }

    /** Zaehlt den Versuch und sagt, ob die Grenze erreicht ist. */
    private function throttled(string $shop, int $typeId): bool
    {
        $this->pdo->prepare(
            'INSERT INTO connect_prepared_credentials (shop, connector_type_id, attempts, attempts_since) VALUES (:shop, :type, 0, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE connector_type_id = VALUES(connector_type_id)'
        )->execute(['shop' => $shop, 'type' => $typeId]);
        $this->pdo->prepare(
            'UPDATE connect_prepared_credentials
             SET attempts = IF(attempts_since IS NULL OR attempts_since < NOW() - INTERVAL 1 HOUR, 1, attempts + 1),
                 attempts_since = IF(attempts_since IS NULL OR attempts_since < NOW() - INTERVAL 1 HOUR, CURRENT_TIMESTAMP, attempts_since)
             WHERE shop = :shop'
        )->execute(['shop' => $shop]);
        $stmt = $this->pdo->prepare('SELECT attempts FROM connect_prepared_credentials WHERE shop = :shop');
        $stmt->execute(['shop' => $shop]);

        return (int) $stmt->fetchColumn() > self::MAX_ATTEMPTS_PER_HOUR;
    }

    /**
     * Token in die bestehende helloCash-Connection des Shops schreiben (nur den Token-Wert,
     * Rest bleibt). Keine Connection = spaeter beim Umzug (ShopMigrator). Auch bei
     * unvollstaendigem Zugriff -- der bisherige Token ist nach "Neu generieren" ungueltig.
     *
     * @param array<string, mixed> $result
     */
    private function applyToConnection(string $shop, int $typeId, string $token, string $secretKey, array $result): bool
    {
        $stmt = $this->pdo->prepare('SELECT id, credentials FROM connect_connections WHERE shop = :shop AND connector_type_id = :type ORDER BY id LIMIT 1');
        $stmt->execute(['shop' => $shop, 'type' => $typeId]);
        $connection = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($connection === false) {
            return false;
        }
        $credentials = $this->crypto->decryptArray((string) $connection['credentials']);
        $credentials[$secretKey] = $token;
        $this->pdo->prepare(
            'UPDATE connect_connections SET credentials = :credentials, status = :status, last_test_at = CURRENT_TIMESTAMP, last_test_result = :message WHERE id = :id'
        )->execute([
            'credentials' => $this->crypto->encryptArray($credentials),
            'status' => $result['ok'] ? 'active' : 'error',
            'message' => $result['ok']
                ? 'Neuer Token vom Merchant hinterlegt, Zugriff auf alle Objects geprüft.'
                : 'Neuer Token vom Merchant hinterlegt, es fehlen Berechtigungen für: ' . implode(', ', $result['missing']),
            'id' => (int) $connection['id'],
        ]);
        $this->storeOnConnection((int) $connection['id'], $result);
        $this->markApplied($shop);
        // Mitarbeiter, Artikel usw. mit dem neuen Token sofort neu laden (nicht erst im naechsten Intervall)
        \Connect\Sync\JobScheduler::dataJobsNowQuietly($this->pdo, $shop, (int) $connection['id']);

        return true;
    }

    /** @param array<string, mixed> $result */
    private function storeOnConnection(int $connectionId, array $result): void
    {
        $stmt = $this->pdo->prepare('SELECT config FROM connect_connections WHERE id = :id');
        $stmt->execute(['id' => $connectionId]);
        $config = json_decode((string) $stmt->fetchColumn(), true) ?: [];
        $config['target_access'] = [
            'checked_at' => date('Y-m-d H:i:s'),
            'ok' => $result['ok'],
            'missing' => $result['missing'],
            'optional_missing' => $result['optional_missing'],
            'error' => $result['error'],
        ];
        $this->pdo->prepare('UPDATE connect_connections SET config = :config WHERE id = :id')
            ->execute(['config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'id' => $connectionId]);
    }
}
