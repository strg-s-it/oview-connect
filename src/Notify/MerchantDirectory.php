<?php

declare(strict_types=1);

namespace Connect\Notify;

use Connect\Billing\Plans;
use Connect\Config;
use Connect\Database;
use Connect\Legacy\LegacyBridge;
use Connect\Shopify\ScopeCheck;
use PDO;

/**
 * Alle Merchants der alten UND neuen Welt in einer Liste -- Grundlage fuer Nachrichten
 * (admin/messages.php). Connect-Shops aus connect_shops, Shops nur der alten Welt aus deren
 * oview_stores (ueber LEGACY_DB_*, nur lesend, wie beim Umzug). Ein Shop, der in beiden
 * steht, zaehlt als Connect-Shop.
 *
 * Je Merchant: shop, world (live = laeuft in Connect, pending = in Connect angelegt, noch nicht
 * umgestellt, legacy = nur alte Welt), name, email + email_source, plan, plan_label, system,
 * scopes_missing (null = unbekannt), setup (done/open/null), installed (Y-m-d|null),
 * stock_paused, optout, token (ok|missing|unknown: helloCash-Token mit Zugriff fuer API v2, siehe
 * Connect\Setup\TargetAccessCheck -- vorab hinterlegt oder an der Connection geprueft).
 */
final class MerchantDirectory
{
    public const WORLD_LABELS = ['live' => 'läuft in Connect', 'pending' => 'in Connect, noch nicht umgestellt', 'legacy' => 'nur alte Welt'];

    private ?string $legacyError = null;

    /** @var array<string, string> Shopify-Token der alten Welt je Shop (nur fuer checkLegacyScopes, nie in all()) */
    private array $legacyTokens = [];

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    /** Fehler beim Lesen der alten Welt (dann fehlen deren Shops in der Liste). */
    public function legacyError(): ?string
    {
        return $this->legacyError;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $required = (new ScopeCheck($this->pdo, new \Connect\Security\Crypto($this->config->require('CONNECT_ENCRYPTION_KEY')), $this->config))->required();
        $legacy = $this->legacyStores();
        $optouts = $this->optouts();
        $prepared = $this->preparedTokens();
        $liveChecks = $this->legacyScopeChecks();
        $notifier = new Notifier($this->pdo, $this->config);

        $merchants = [];
        foreach ($this->pdo->query('SELECT * FROM connect_shops ORDER BY shop')->fetchAll() as $s) {
            $shop = (string) $s['shop'];
            $settings = $notifier->settings($shop);
            $legacyRow = $legacy[$shop] ?? null;

            $email = $settings['effective'];
            $source = $settings['custom'] !== null ? 'Benachrichtigungen' : ($settings['shopify'] !== null ? 'Shopify' : null);
            if ($email === null && $legacyRow !== null && $legacyRow['email'] !== null) {
                [$email, $source] = [$legacyRow['email'], 'alte Welt'];
            }

            $shopify = $this->shopifyConnection($shop);
            // Beide Quellen zusammen: Connect prueft nur auf Knopfdruck (ScopeCheck), die alte Welt
            // speichert die Scopes bei jedem Bestaetigen (generate_token.php) und Oeffnen der App --
            // die Berechtigungen gehoeren zur Installation, nicht zum Token.
            $granted = $shopify['granted'];
            if ($legacyRow !== null && $legacyRow['granted'] !== null) {
                $granted = array_values(array_unique(array_merge($granted ?? [], $legacyRow['granted'])));
            }
            if (($liveChecks[$shop]['granted'] ?? null) !== null) {
                $granted = array_values(array_unique(array_merge($granted ?? [], $liveChecks[$shop]['granted'])));
            }

            $plan = isset($s['plan']) && isset(Plans::PLANS[(string) $s['plan']]) ? (string) $s['plan'] : null;
            if ($plan === null && $legacyRow !== null) {
                $plan = $legacyRow['plan'];
            }
            $guard = isset($s['stock_guard']) ? (json_decode((string) $s['stock_guard'], true) ?: []) : [];

            $merchants[] = [
                'shop' => $shop,
                'world' => (int) ($s['live_in_connect'] ?? 0) === 1 ? 'live' : 'pending',
                'name' => $shopify['name'] ?? ($legacyRow['name'] ?? null) ?? $shop,
                'company' => $this->companyName((int) ($s['target_connection_id'] ?? 0)),
                'email' => $email,
                'email_source' => $source,
                'plan' => $plan,
                'plan_label' => $plan !== null ? Plans::PLANS[$plan]['name'] : (($s['plan_status'] ?? null) === 'none' ? 'kein Abo' : 'unbekannt'),
                'system' => $this->systemName((int) ($s['target_connection_id'] ?? 0)),
                'scopes_missing' => $granted !== null ? ScopeCheck::missing($granted, $required) : null,
                'setup' => array_key_exists('setup_completed_at', $s) ? ($s['setup_completed_at'] !== null ? 'done' : 'open') : null,
                'installed' => isset($s['first_seen_at']) ? substr((string) $s['first_seen_at'], 0, 10) : ($legacyRow['installed'] ?? null),
                'stock_paused' => ($guard['state'] ?? null) === 'paused',
                'optout' => isset($optouts[$shop]),
                'token' => $this->tokenState((int) ($s['target_connection_id'] ?? 0), $prepared[$shop] ?? null),
                'scopes_checked_at' => $shopify['checked_at'] ?? null,
                'scopes_error' => null,
            ];
            unset($legacy[$shop]);
        }

        foreach ($legacy as $shop => $row) {
            $shop = (string) $shop; // PHP macht aus rein numerischen Array-Schluesseln (Shop "12345") ein int
            // Live-Pruefung (Umstieg -> "Berechtigungen jetzt pruefen") vor dem Stand in oview_stores
            $live = $liveChecks[$shop] ?? null;
            $granted = $live !== null && $live['granted'] !== null ? $live['granted'] : $row['granted'];
            $merchants[] = [
                'shop' => $shop,
                'world' => 'legacy',
                'name' => $row['name'] ?? $shop,
                'company' => null,
                'email' => $row['email'],
                'email_source' => $row['email'] !== null ? 'alte Welt' : null,
                'plan' => $row['plan'],
                'plan_label' => $row['plan'] !== null ? Plans::PLANS[$row['plan']]['name'] : ($row['charge_name'] !== '' ? $row['charge_name'] : 'kein Abo'),
                'system' => 'HelloCash',
                'scopes_missing' => $granted !== null ? ScopeCheck::missing($granted, $required) : null,
                'setup' => null,
                'installed' => $row['installed'],
                'stock_paused' => false,
                'optout' => isset($optouts[$shop]),
                'token' => $this->tokenState(0, $prepared[$shop] ?? null),
                'scopes_checked_at' => $live['checked_at'] ?? null,
                'scopes_error' => $live['error'] ?? null,
            ];
        }

        usort($merchants, static fn (array $a, array $b): int => strcmp((string) $a['shop'], (string) $b['shop']));

        return $merchants;
    }

    /**
     * Filter anwenden. Leere Werte = kein Filter.
     *
     * @param list<array<string, mixed>> $merchants
     * @param array<string, mixed> $f world[], plan[], system, scopes (missing|ok|unknown), setup (done|open),
     *        installed_from, installed_to (Y-m-d), stock_paused (1), q (Suchtext), only[] (Shops)
     * @return list<array<string, mixed>>
     */
    public static function filter(array $merchants, array $f): array
    {
        $worlds = array_filter((array) ($f['world'] ?? []));
        $plans = array_filter((array) ($f['plan'] ?? []));
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));

        return array_values(array_filter($merchants, static function (array $m) use ($f, $worlds, $plans, $q): bool {
            if ($worlds !== [] && !in_array($m['world'], $worlds, true)) {
                return false;
            }
            if ($plans !== [] && !in_array($m['plan'] ?? 'none', $plans, true)) {
                return false;
            }
            if (($f['system'] ?? '') !== '' && strcasecmp((string) ($m['system'] ?? ''), (string) $f['system']) !== 0) {
                return false;
            }
            $scopes = (string) ($f['scopes'] ?? '');
            if ($scopes === 'missing' && ($m['scopes_missing'] === null || $m['scopes_missing'] === [])) {
                return false;
            }
            if ($scopes === 'ok' && $m['scopes_missing'] !== []) {
                return false;
            }
            if ($scopes === 'unknown' && $m['scopes_missing'] !== null) {
                return false;
            }
            $token = (string) ($f['token'] ?? '');
            if ($token === 'ok' && $m['token'] !== 'ok') {
                return false;
            }
            if ($token === 'open' && $m['token'] === 'ok') {
                return false;
            }
            if (($f['setup'] ?? '') !== '' && $m['setup'] !== $f['setup']) {
                return false;
            }
            if (($f['installed_from'] ?? '') !== '' && ($m['installed'] === null || $m['installed'] < $f['installed_from'])) {
                return false;
            }
            if (($f['installed_to'] ?? '') !== '' && ($m['installed'] === null || $m['installed'] > $f['installed_to'])) {
                return false;
            }
            if (!empty($f['stock_paused']) && !$m['stock_paused']) {
                return false;
            }
            if ($q !== '' && !str_contains(mb_strtolower($m['shop'] . ' ' . $m['name'] . ' ' . ($m['company'] ?? '') . ' ' . ($m['email'] ?? '')), $q)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Shops der alten Welt (oview_stores), je Shop die neueste Zeile.
     *
     * @return array<string, array{email: ?string, name: ?string, plan: ?string, charge_name: string, granted: ?list<string>, installed: ?string}>
     */
    private function legacyStores(): array
    {
        if (!LegacyBridge::isConfigured($this->config)) {
            $this->legacyError = 'LEGACY_DB_* fehlt in connect/.env – Shops der alten Welt können nicht gelesen werden.';

            return [];
        }
        try {
            $legacyPdo = Database::connectTo(
                $this->config->require('LEGACY_DB_HOST'),
                $this->config->optional('LEGACY_DB_PORT', '3306'),
                $this->config->require('LEGACY_DB_NAME'),
                $this->config->require('LEGACY_DB_USER'),
                $this->config->optional('LEGACY_DB_PASS')
            )->pdo();
            $rows = $legacyPdo->query(
                'SELECT store_url, access_token, email, shop_name, charge_name, charge_status, scopes, install_date FROM oview_stores ORDER BY install_date DESC'
            )->fetchAll();
        } catch (\Throwable $e) {
            $this->legacyError = 'Alte Welt nicht lesbar: ' . $e->getMessage();

            return [];
        }

        $stores = [];
        foreach ($rows as $row) {
            $storeUrl = (string) $row['store_url'];
            $shop = str_contains($storeUrl, '.') ? substr($storeUrl, 0, (int) strpos($storeUrl, '.')) : $storeUrl;
            if ($shop === '' || isset($stores[$shop])) {
                continue; // neueste Zeile je Shop
            }
            $granted = null;
            $scopesJson = json_decode((string) ($row['scopes'] ?? ''), true);
            if (is_array($scopesJson) && isset($scopesJson['access_scopes']) && is_array($scopesJson['access_scopes'])) {
                $granted = [];
                foreach ($scopesJson['access_scopes'] as $scope) {
                    if (is_array($scope) && ($scope['handle'] ?? '') !== '') {
                        $granted[] = (string) $scope['handle'];
                    }
                }
            }
            $email = trim((string) ($row['email'] ?? ''));
            $active = ($row['charge_status'] ?? '') === 'active';
            if (trim((string) ($row['access_token'] ?? '')) !== '') {
                $this->legacyTokens[$shop] = trim((string) $row['access_token']);
            }
            $stores[$shop] = [
                'email' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
                'name' => trim((string) ($row['shop_name'] ?? '')) !== '' ? (string) $row['shop_name'] : null,
                'plan' => $active ? Plans::keyForName((string) ($row['charge_name'] ?? '')) : null,
                'charge_name' => $active ? (string) ($row['charge_name'] ?? '') : '',
                'granted' => $granted,
                'installed' => !empty($row['install_date']) ? substr((string) $row['install_date'], 0, 10) : null,
            ];
        }

        return $stores;
    }

    /**
     * Shopify-Berechtigungen eines Shops der alten Welt live abfragen (Token aus oview_stores,
     * nur lesend) und in connect_legacy_scope_checks merken (Migration 0085). Vorher all() aufrufen.
     *
     * @return string|null Fehler, null = geprueft
     */
    public function checkLegacyScopes(string $shop, \Connect\Http\HttpClient $http): ?string
    {
        $token = $this->legacyTokens[$shop] ?? null;
        $granted = null;
        $error = null;
        if ($token === null) {
            $error = 'kein Shopify-Token in der alten Welt';
        } elseif (preg_match('/^[a-z0-9][a-z0-9\-]*$/i', $shop) !== 1) {
            $error = 'ungültiger Shop-Name';
        } else {
            try {
                $response = $http->request('GET', 'https://' . $shop . '.myshopify.com/admin/oauth/access_scopes.json', ['X-Shopify-Access-Token' => $token]);
                if ($response->isSuccess()) {
                    $granted = [];
                    foreach ((array) (json_decode($response->body, true)['access_scopes'] ?? []) as $scope) {
                        if (is_array($scope) && ($scope['handle'] ?? '') !== '') {
                            $granted[] = (string) $scope['handle'];
                        }
                    }
                } else {
                    $error = 'Shopify HTTP ' . $response->statusCode . ($response->statusCode === 401 ? ' (Token ungültig – App deinstalliert?)' : '');
                }
            } catch (\Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 250);
            }
        }
        $this->pdo->prepare(
            'INSERT INTO connect_legacy_scope_checks (shop, granted, error, checked_at) VALUES (:shop, :granted, :error, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE granted = VALUES(granted), error = VALUES(error), checked_at = CURRENT_TIMESTAMP'
        )->execute(['shop' => $shop, 'granted' => $granted !== null ? json_encode($granted) : null, 'error' => $error]);

        return $error;
    }

    /** @return array<string, array{granted: ?list<string>, error: ?string, checked_at: string}> */
    private function legacyScopeChecks(): array
    {
        try {
            $out = [];
            foreach ($this->pdo->query('SELECT shop, granted, error, checked_at FROM connect_legacy_scope_checks')->fetchAll() as $row) {
                $granted = json_decode((string) ($row['granted'] ?? ''), true);
                $out[(string) $row['shop']] = [
                    'granted' => is_array($granted) ? array_values(array_map('strval', $granted)) : null,
                    'error' => $row['error'] !== null ? (string) $row['error'] : null,
                    'checked_at' => (string) $row['checked_at'],
                ];
            }

            return $out;
        } catch (\PDOException) {
            return []; // Migration 0085 fehlt
        }
    }

    /** @return array{granted: ?list<string>, name: ?string, checked_at: ?string} */
    private function shopifyConnection(string $shop): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.config FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
        );
        $stmt->execute(['shop' => $shop]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['granted' => null, 'name' => null, 'checked_at' => null];
        }
        $config = json_decode((string) ($row['config'] ?? ''), true);
        $granted = is_array($config) && isset($config['shopify_scopes']['granted']) && is_array($config['shopify_scopes']['granted'])
            ? array_values(array_map('strval', $config['shopify_scopes']['granted'])) : null;

        $name = null;
        $cache = $this->pdo->prepare("SELECT data FROM connect_object_records WHERE connection_id = :id AND object_key = 'shop' ORDER BY fetched_at DESC LIMIT 1");
        $cache->execute(['id' => (int) $row['id']]);
        $data = json_decode((string) $cache->fetchColumn(), true);
        if (is_array($data) && trim((string) ($data['name'] ?? '')) !== '') {
            $name = (string) $data['name'];
        }

        return ['granted' => $granted, 'name' => $name, 'checked_at' => is_array($config) && isset($config['shopify_scopes']['checked_at']) ? (string) $config['shopify_scopes']['checked_at'] : null];
    }

    private function companyName(int $connectionId): ?string
    {
        if ($connectionId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT config FROM connect_connections WHERE id = :id');
        $stmt->execute(['id' => $connectionId]);

        return \Connect\Setup\ConnectionAccount::fromConfig((string) $stmt->fetchColumn())['name'];
    }

    private function systemName(int $connectionId): ?string
    {
        if ($connectionId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT t.name FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id WHERE c.id = :id');
        $stmt->execute(['id' => $connectionId]);
        $name = $stmt->fetchColumn();

        return $name !== false ? (string) $name : null;
    }

    /** @return array<string, ?bool> shop => access_ok (null = nie versucht) */
    private function preparedTokens(): array
    {
        try {
            $out = [];
            foreach ($this->pdo->query('SELECT shop, access_ok, access FROM connect_prepared_credentials')->fetchAll() as $row) {
                $out[(string) $row['shop']] = $row['access'] === null ? null : (int) $row['access_ok'] === 1;
            }

            return $out;
        } catch (\PDOException) {
            return []; // Migration 0084 fehlt
        }
    }

    /** ok = Token mit vollem Zugriff vorhanden, missing = nachweislich unvollstaendig, unknown = nie geprueft */
    private function tokenState(int $connectionId, ?bool $prepared): string
    {
        if ($prepared === true) {
            return 'ok';
        }
        if ($connectionId > 0) {
            $stmt = $this->pdo->prepare('SELECT config FROM connect_connections WHERE id = :id');
            $stmt->execute(['id' => $connectionId]);
            $access = \Connect\Setup\TargetAccessCheck::stored((string) $stmt->fetchColumn());
            if ($access !== null) {
                return !empty($access['ok']) ? 'ok' : 'missing';
            }
        }

        return $prepared === false ? 'missing' : 'unknown';
    }

    /** @return array<string, true> */
    private function optouts(): array
    {
        try {
            return array_fill_keys($this->pdo->query('SELECT shop FROM connect_message_optouts')->fetchAll(PDO::FETCH_COLUMN), true);
        } catch (\PDOException) {
            return [];
        }
    }
}
