<?php

declare(strict_types=1);

namespace Connect\Billing;

use Connect\Config;
use Connect\Http\HttpClient;
use PDO;

/**
 * Abo-Stand je Shop aus der Shopify Partner API (Migration 0086) -- auch fuer Shops, die selbst
 * nicht mehr erreichbar sind (geschlossen, App deinstalliert). Grundlage sind die App-Ereignisse
 * (app.events) der Partner API: je Abo (charge.id) zaehlt das juengste Ereignis.
 *
 *   SUBSCRIPTION_CHARGE_ACTIVATED / _UNFROZEN   -> active
 *   SUBSCRIPTION_CHARGE_FROZEN                  -> frozen (Shop eingefroren, Abrechnung ruht)
 *   SUBSCRIPTION_CHARGE_CANCELED / _EXPIRED / _DECLINED -> ended
 *   RELATIONSHIP_UNINSTALLED                    -> alle Abos des Shops ended (Shopify kuendigt sie)
 * Shop-Stand: active, wenn ein Abo active ist, sonst frozen, sonst ended, ohne Ereignisse none.
 *
 * .env (Partner Dashboard -> Einstellungen -> Partner-API-Clients, Berechtigung "Apps verwalten"
 * bzw. "Finanzdaten anzeigen"):
 *   CONNECT_PARTNER_ORG_ID       Organisations-ID (Zahl aus der Partner-Dashboard-URL)
 *   CONNECT_PARTNER_API_TOKEN    Zugriffstoken des Partner-API-Clients (prtapi_...)
 *   CONNECT_PARTNER_APP_ID       App-ID (Zahl aus der URL der App im Partner Dashboard)
 *   CONNECT_PARTNER_API_VERSION  optional, Standard siehe DEFAULT_VERSION
 */
final class PartnerSubscriptions
{
    private const DEFAULT_VERSION = '2025-07';
    private const MAX_PAGES = 60;

    private const QUERY = <<<'GRAPHQL'
query ($app: ID!, $after: String) {
  app(id: $app) {
    events(first: 100, after: $after, types: [SUBSCRIPTION_CHARGE_ACTIVATED, SUBSCRIPTION_CHARGE_UNFROZEN, SUBSCRIPTION_CHARGE_FROZEN, SUBSCRIPTION_CHARGE_CANCELED, SUBSCRIPTION_CHARGE_EXPIRED, SUBSCRIPTION_CHARGE_DECLINED, RELATIONSHIP_UNINSTALLED]) {
      pageInfo { hasNextPage }
      edges {
        cursor
        node {
          type
          occurredAt
          shop { myshopifyDomain }
          ... on SubscriptionChargeActivated { charge { id name } }
          ... on SubscriptionChargeUnfrozen { charge { id name } }
          ... on SubscriptionChargeFrozen { charge { id name } }
          ... on SubscriptionChargeCanceled { charge { id name } }
          ... on SubscriptionChargeExpired { charge { id name } }
          ... on SubscriptionChargeDeclined { charge { id name } }
        }
      }
    }
  }
}
GRAPHQL;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly HttpClient $http = new HttpClient(),
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->config->optional('CONNECT_PARTNER_ORG_ID', '') !== ''
            && $this->config->optional('CONNECT_PARTNER_API_TOKEN', '') !== ''
            && $this->config->optional('CONNECT_PARTNER_APP_ID', '') !== '';
    }

    /**
     * Alle Abo-Ereignisse der App laden und den Stand je Shop speichern.
     *
     * @return array{shops: int, events: int} Anzahl Shops mit Ereignissen, Anzahl Ereignisse
     * @throws \RuntimeException bei Fehlern der Partner API (Meldung fuer den Admin)
     */
    public function refresh(): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Partner API nicht eingerichtet (CONNECT_PARTNER_ORG_ID, _API_TOKEN, _APP_ID in connect/.env).');
        }
        $url = 'https://partners.shopify.com/' . rawurlencode($this->config->optional('CONNECT_PARTNER_ORG_ID', ''))
            . '/api/' . rawurlencode($this->config->optional('CONNECT_PARTNER_API_VERSION', self::DEFAULT_VERSION)) . '/graphql.json';
        $appId = $this->config->optional('CONNECT_PARTNER_APP_ID', '');
        $appGid = str_starts_with($appId, 'gid://') ? $appId : 'gid://partners/App/' . $appId;

        $events = [];
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->http->request('POST', $url, [
                'Content-Type' => 'application/json',
                'X-Shopify-Access-Token' => $this->config->optional('CONNECT_PARTNER_API_TOKEN', ''),
            ], (string) json_encode(['query' => self::QUERY, 'variables' => ['app' => $appGid, 'after' => $after]]));
            if (!$response->isSuccess()) {
                throw new \RuntimeException('Partner API antwortet mit HTTP ' . $response->statusCode . ': ' . mb_substr($response->body, 0, 300));
            }
            $data = json_decode($response->body, true);
            if (!empty($data['errors'])) {
                throw new \RuntimeException('Partner API: ' . mb_substr((string) json_encode($data['errors'], JSON_UNESCAPED_UNICODE), 0, 400));
            }
            $connection = $data['data']['app']['events'] ?? null;
            if (!is_array($connection)) {
                throw new \RuntimeException('Partner API: App nicht gefunden (CONNECT_PARTNER_APP_ID prüfen).');
            }
            foreach ((array) ($connection['edges'] ?? []) as $edge) {
                $events[] = $edge['node'] ?? [];
                $after = (string) ($edge['cursor'] ?? '');
            }
            if (empty($connection['pageInfo']['hasNextPage']) || $after === '') {
                break;
            }
        }

        // Aelteste zuerst, dann je Abo das juengste Ereignis
        usort($events, static fn (array $a, array $b): int => strcmp((string) ($a['occurredAt'] ?? ''), (string) ($b['occurredAt'] ?? '')));
        $shops = [];
        foreach ($events as $event) {
            $domain = (string) ($event['shop']['myshopifyDomain'] ?? '');
            if ($domain === '') {
                continue;
            }
            $shop = str_contains($domain, '.') ? substr($domain, 0, (int) strpos($domain, '.')) : $domain;
            $type = (string) ($event['type'] ?? '');
            $shops[$shop] ??= ['charges' => [], 'last_event' => null, 'last_event_at' => null];
            $shops[$shop]['last_event'] = $type;
            $shops[$shop]['last_event_at'] = (string) ($event['occurredAt'] ?? '');

            if ($type === 'RELATIONSHIP_UNINSTALLED') {
                foreach ($shops[$shop]['charges'] as $id => $charge) {
                    $shops[$shop]['charges'][$id]['state'] = 'ended';
                }
                continue;
            }
            $chargeId = (string) ($event['charge']['id'] ?? $type);
            $shops[$shop]['charges'][$chargeId] = [
                'name' => (string) ($event['charge']['name'] ?? ''),
                'state' => match ($type) {
                    'SUBSCRIPTION_CHARGE_ACTIVATED', 'SUBSCRIPTION_CHARGE_UNFROZEN' => 'active',
                    'SUBSCRIPTION_CHARGE_FROZEN' => 'frozen',
                    default => 'ended',
                },
            ];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO connect_partner_subscriptions (shop, state, charge_name, last_event, last_event_at, checked_at)
             VALUES (:shop, :state, :charge, :event, :at, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE state = VALUES(state), charge_name = VALUES(charge_name), last_event = VALUES(last_event),
                                     last_event_at = VALUES(last_event_at), checked_at = CURRENT_TIMESTAMP'
        );
        foreach ($shops as $shop => $info) {
            $state = 'ended';
            $name = null;
            foreach (['active', 'frozen'] as $wanted) {
                foreach ($info['charges'] as $charge) {
                    if ($charge['state'] === $wanted) {
                        $state = $wanted;
                        $name = $charge['name'] !== '' ? $charge['name'] : null;
                        break 2;
                    }
                }
            }
            $at = $info['last_event_at'] !== null && $info['last_event_at'] !== '' ? date('Y-m-d H:i:s', (int) strtotime($info['last_event_at'])) : null;
            $stmt->execute(['shop' => (string) $shop, 'state' => $state, 'charge' => $name, 'event' => $info['last_event'], 'at' => $at]);
        }
        // Zeitpunkt der letzten vollstaendigen Abfrage (Shops ohne Ereignisse = kein Abo)
        \Connect\Setup\Settings::set($this->pdo, 'partner_subscriptions_checked_at', date('Y-m-d H:i:s'));

        return ['shops' => count($shops), 'events' => count($events)];
    }

    /**
     * Stand je Shop. Shops ohne Eintrag: 'none', wenn eine vollstaendige Abfrage gelaufen ist.
     *
     * @return array{checked_at: ?string, shops: array<string, array{state: string, charge_name: ?string, last_event: ?string, last_event_at: ?string}>}
     */
    public function stored(): array
    {
        try {
            $shops = [];
            foreach ($this->pdo->query('SELECT shop, state, charge_name, last_event, last_event_at FROM connect_partner_subscriptions')->fetchAll() as $row) {
                $shops[(string) $row['shop']] = [
                    'state' => (string) $row['state'],
                    'charge_name' => $row['charge_name'] !== null ? (string) $row['charge_name'] : null,
                    'last_event' => $row['last_event'] !== null ? (string) $row['last_event'] : null,
                    'last_event_at' => $row['last_event_at'] !== null ? (string) $row['last_event_at'] : null,
                ];
            }

            return ['checked_at' => \Connect\Setup\Settings::get($this->pdo, 'partner_subscriptions_checked_at'), 'shops' => $shops];
        } catch (\PDOException) {
            return ['checked_at' => null, 'shops' => []]; // Migration 0086 fehlt
        }
    }
}
