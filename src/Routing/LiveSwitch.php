<?php

declare(strict_types=1);

namespace Connect\Routing;

use Connect\Http\HttpClient;
use Connect\Security\Crypto;
use PDO;

/**
 * Bringt die Shopify-Webhook-Abos eines Shops in Einklang mit dem Routing-
 * Schalter connect_shops.live_in_connect (Migration 0050) -- an EINER Stelle,
 * egal ob der Schalter im Connect-Admin (admin/shops.php) oder in der alten
 * Welt (admin.php?show=connect, ruft dafuer public/internal/live_switch.php)
 * umgelegt wurde.
 *
 * Regel (Nutzer-Entscheidung 2026-10-04: "altes Abo loeschen"):
 *   live = 1: Connect hat eigene Abos fuer orders/paid und products/update
 *             (zuerst anlegen!), danach werden die Abos der alten Welt
 *             (Adresse //oview.at/webhooks/...) geloescht -- keine doppelte
 *             Zustellung, keine Abhaengigkeit von der Weiterleitung.
 *   live = 0: Connects eigene Abos (orders/paid, orders/create,
 *             products/update) werden geloescht, die der alten Welt wieder
 *             angelegt (gleiche Adressen wie add_order_paid_webhook() /
 *             add_single_product_update_webhook() in inc/functions.php).
 *
 * Die Reihenfolge "erst anlegen, dann loeschen" ist Absicht: schlaegt das
 * Anlegen fehl, bleibt das alte Abo bestehen und es geht kein Ereignis
 * verloren (lieber doppelt zugestellt -- das faengt die Dedup-Sperre ab --
 * als gar nicht).
 *
 * Lifecycle-/Datenschutz-Webhooks (app/uninstalled, *_redact, data_request)
 * werden nie angefasst: die bleiben bei der alten Welt, die sie selbst
 * verarbeitet UND an Connect weiterreicht.
 *
 * Best-effort: liefert menschlich lesbare Hinweise und wirft nicht, damit das
 * Umschalten selbst nie an Shopify scheitert.
 */
final class LiveSwitch
{
    /** Topics, die die alte Welt selbst per API verwaltet -> Empfaenger dort */
    private const LEGACY_TOPICS = [
        'orders/paid' => 'order_paid.php',
        'products/update' => 'single_product_update.php',
    ];

    /** Topics, die Connect fuer einen live geschalteten Shop immer abonniert */
    private const CONNECT_TOPICS = [
        'orders/paid' => 'order_paid.php',
        'products/update' => 'product_update.php',
    ];

    /** Alle Topics, deren Connect-Abos beim Zurueckschalten entfernt werden */
    private const CONNECT_REMOVABLE_TOPICS = ['orders/paid', 'orders/create', 'products/update'];

    private const LEGACY_HOST_MARKER = '//oview.at/webhooks/';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Crypto $crypto,
        private readonly HttpClient $httpClient,
        private readonly string $appUrl,
        private readonly string $apiVersion
    ) {
    }

    /**
     * Schalter umlegen und Abos abgleichen. Auf Connect nur mit aktiver Shopify-
     * Connection; zurueck zur alten Welt geht immer (Rueckweg nie blockieren).
     *
     * @return array{ok: bool, message: string}
     */
    public function setLive(string $shop, bool $live): array
    {
        if ($live) {
            $active = $this->pdo->prepare(
                "SELECT 1 FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id
                 WHERE c.shop = :shop AND t.type_key = 'shopify' AND c.status = 'active' LIMIT 1"
            );
            $active->execute(['shop' => $shop]);
            if ($active->fetchColumn() === false) {
                return ['ok' => false, 'message' => "Shop \"{$shop}\" hat keine aktive Shopify-Connection. Bitte zuerst unter Connections „Verbindung testen“."];
            }

            // Shopify-Berechtigungen (Connect\Shopify\ScopeCheck): fehlt nachweislich eine, nicht
            // umstellen -- Connect wuerde sonst beim ersten Schreiben scheitern. Kann die Pruefung
            // selbst nicht laufen (z.B. Shopify kurz nicht erreichbar), wird nicht blockiert.
            try {
                $scopes = (new \Connect\Shopify\ScopeCheck($this->pdo, $this->crypto, \Connect\Config::load(), $this->httpClient))->check($shop);
                if ($scopes['ok'] === false) {
                    return ['ok' => false, 'message' => "Shop \"{$shop}\" nicht umgestellt: Shopify-Berechtigungen fehlen (" . implode(', ', $scopes['missing'])
                        . '). Der Merchant muss die App neu autorisieren, danach unter Shops „Token übernehmen“ (siehe Umzug, Schritt 1).'];
                }
            } catch (\Throwable) {
                // Pruefung nicht moeglich -- Umstellen nicht daran scheitern lassen
            }

            // helloCash-Token (Connect\Setup\TargetAccessCheck): nur das zuletzt GESPEICHERTE
            // Ergebnis (Umzug Schritt 1 "Zugriff pruefen") -- nie geprueft blockiert nicht.
            $target = $this->pdo->prepare(
                "SELECT c.config FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id
                 WHERE c.shop = :shop AND t.type_key = 'hellocash' ORDER BY c.id LIMIT 1"
            );
            $target->execute(['shop' => $shop]);
            $access = \Connect\Setup\TargetAccessCheck::stored((string) $target->fetchColumn());
            if ($access !== null && empty($access['ok'])) {
                return ['ok' => false, 'message' => "Shop \"{$shop}\" nicht umgestellt: dem helloCash-Token fehlen Berechtigungen ("
                    . implode(', ', !empty($access['missing']) ? $access['missing'] : [(string) ($access['error'] ?? '?')])
                    . '). Der Merchant braucht einen neuen Token (siehe Umzug, Schritt 1).'];
            }
        }

        $update = $this->pdo->prepare('UPDATE connect_shops SET live_in_connect = :live, live_changed_at = CURRENT_TIMESTAMP WHERE shop = :shop');
        $update->execute(['live' => $live ? 1 : 0, 'shop' => $shop]);
        $message = $live ? "Shop \"{$shop}\" läuft jetzt in Connect." : "Shop \"{$shop}\" läuft wieder in der alten Welt.";

        $notes = $this->syncWebhooks($shop);
        if ($notes !== []) {
            $message .= ' Webhooks: ' . implode(' ', $notes);
        }

        return ['ok' => true, 'message' => $message];
    }

    /**
     * Zeitgesteuerte, schreibende Jobs des Shops (Lagerabgleich, Ausblenden, Produktimport,
     * Uebersetzungs-Nachlauf) auf "jetzt" setzen: wartender Job -> scheduled_for = jetzt,
     * sonst neuer wartender Job (Fortschritt cursor_state wird wie im Scheduler uebernommen).
     * Laufende Jobs bleiben unberuehrt. Der Scheduler holt sie beim naechsten Aufruf (jede Minute).
     *
     * @return int Anzahl eingeplanter Jobs
     */
    public function scheduleWritingJobsNow(string $shop): int
    {
        $mappings = $this->pdo->prepare(
            "SELECT id FROM connect_sync_mappings
             WHERE shop = :shop AND enabled = 1 AND schedule_interval_minutes IS NOT NULL
               AND (mapping_kind IN ('stock_sync', 'product_visibility', 'auto_import_products') OR target_connection_id IS NOT NULL)"
        );
        $mappings->execute(['shop' => $shop]);

        return \Connect\Sync\JobScheduler::now($this->pdo, array_map('intval', $mappings->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Gleicht die Abos fuer den AKTUELLEN Stand des Schalters ab (der Wert wird
     * immer aus der Datenbank gelesen, nie vom Aufrufer uebernommen).
     *
     * @return list<string> Hinweise fuer die Rueckmeldung
     */
    public function syncWebhooks(string $shop): array
    {
        $notes = [];

        $live = $this->pdo->prepare('SELECT live_in_connect FROM connect_shops WHERE shop = :shop');
        $live->execute(['shop' => $shop]);
        $liveValue = $live->fetchColumn();
        if ($liveValue === false) {
            return ["Shop \"{$shop}\" ist in Connect nicht bekannt -- Webhook-Abos unverändert."];
        }
        $isLive = (int) $liveValue === 1;

        // Schreibende Jobs sofort einplanen: solange der Shop nicht live war, wurden sie bei
        // jedem faelligen Lauf uebersprungen und als erledigt gezaehlt -- ohne das kaeme der
        // erste echte Lagerabgleich erst ein volles Intervall (bis 60 Min.) nach dem Umschalten,
        // in der Zwischenzeit glich keine der beiden Welten den Bestand ab.
        if ($isLive) {
            $scheduled = $this->scheduleWritingJobsNow($shop);
            if ($scheduled > 0) {
                $notes[] = "{$scheduled} schreibende Job(s) (z. B. Lagerabgleich) für sofort eingeplant.";
            }
            // Stammdaten (Mitarbeiter, Zahlungsarten, Standort ...) ebenfalls sofort -- sonst fehlen
            // die Kassen-Einstellungen bis zum naechsten (oft taeglichen) Lauf.
            $data = \Connect\Sync\JobScheduler::dataJobsNowQuietly($this->pdo, $shop);
            if ($data > 0) {
                $notes[] = "{$data} Daten-Job(s) (z. B. Mitarbeiter) für sofort eingeplant.";
            }
        }

        $connStmt = $this->pdo->prepare(
            "SELECT c.id, c.credentials FROM connect_connections c
             JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
        );
        $connStmt->execute(['shop' => $shop]);
        $connection = $connStmt->fetch(PDO::FETCH_ASSOC);
        if ($connection === false) {
            return ['Keine Shopify-Connection -- Webhook-Abos unverändert.'];
        }
        $connectionId = (int) $connection['id'];

        try {
            $credentials = $this->crypto->decryptArray((string) $connection['credentials']);
        } catch (\Throwable $e) {
            return ['Shopify-Zugangsdaten nicht lesbar -- Webhook-Abos unverändert (' . $e->getMessage() . ').'];
        }
        $shopDomain = (string) parse_url((string) ($credentials['base_url'] ?? ''), PHP_URL_HOST);
        $token = (string) ($credentials['header_value'] ?? '');
        if ($shopDomain === '' || $token === '') {
            return ['Shopify-Zugangsdaten unvollständig -- Webhook-Abos unverändert.'];
        }

        try {
            $existing = $this->listWebhooks($shopDomain, $token);
        } catch (\Throwable $e) {
            return ['Webhook-Liste von Shopify nicht abrufbar -- Abos unverändert (' . $e->getMessage() . ').'];
        }

        $connectHost = (string) parse_url($this->appUrl, PHP_URL_HOST);
        $isConnect = static fn (array $hook): bool => $connectHost !== '' && str_contains((string) ($hook['address'] ?? ''), '//' . $connectHost . '/webhooks/');
        $isLegacy = static fn (array $hook): bool => str_contains((string) ($hook['address'] ?? ''), self::LEGACY_HOST_MARKER);

        if ($isLive) {
            // 1. Connect-Abos sicherstellen (vor dem Loeschen der alten!)
            $connectReady = [];
            foreach (self::CONNECT_TOPICS as $topic => $receiver) {
                $found = null;
                foreach ($existing as $hook) {
                    if ($hook['topic'] === $topic && $isConnect($hook)) {
                        $found = $hook;
                        break;
                    }
                }
                try {
                    if ($found === null) {
                        $found = $this->createWebhook($shopDomain, $token, $topic, rtrim($this->appUrl, '/') . '/webhooks/' . $receiver);
                        $notes[] = "Connect-Abo \"{$topic}\" angelegt.";
                    }
                    $this->rememberSubscription($connectionId, $topic, (string) $found['id']);
                    $connectReady[$topic] = true;
                } catch (\Throwable $e) {
                    $notes[] = "Connect-Abo \"{$topic}\" konnte nicht angelegt werden ({$e->getMessage()}) -- altes Abo bleibt deshalb bestehen.";
                }
            }

            // 2. Abos der alten Welt entfernen -- nur, wo Connect jetzt sicher zustellt
            foreach ($existing as $hook) {
                if (!$isLegacy($hook) || !isset(self::LEGACY_TOPICS[$hook['topic']]) || !isset($connectReady[$hook['topic']])) {
                    continue;
                }
                try {
                    $this->deleteWebhook($shopDomain, $token, (string) $hook['id']);
                    $notes[] = "Altes Abo \"{$hook['topic']}\" (alte Welt) entfernt.";
                } catch (\Throwable $e) {
                    $notes[] = "Altes Abo \"{$hook['topic']}\" konnte nicht entfernt werden ({$e->getMessage()}).";
                }
            }

            return $notes;
        }

        // ---- live = 0: zurueck zur alten Welt ------------------------------------
        // 1. Abos der alten Welt sicherstellen
        foreach (self::LEGACY_TOPICS as $topic => $receiver) {
            $found = false;
            foreach ($existing as $hook) {
                if ($hook['topic'] === $topic && $isLegacy($hook)) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                continue;
            }
            try {
                $this->createWebhook($shopDomain, $token, $topic, 'https://oview.at/webhooks/' . $receiver . '?shop=' . $shopDomain);
                $notes[] = "Abo \"{$topic}\" für die alte Welt wieder angelegt.";
            } catch (\Throwable $e) {
                $notes[] = "Abo \"{$topic}\" für die alte Welt konnte nicht angelegt werden ({$e->getMessage()}).";
            }
        }

        // 2. Connect-Abos entfernen (sonst wuerden beide Welten verarbeiten)
        foreach ($existing as $hook) {
            if (!$isConnect($hook) || !in_array($hook['topic'], self::CONNECT_REMOVABLE_TOPICS, true)) {
                continue;
            }
            try {
                $this->deleteWebhook($shopDomain, $token, (string) $hook['id']);
                $notes[] = "Connect-Abo \"{$hook['topic']}\" entfernt.";
            } catch (\Throwable $e) {
                $notes[] = "Connect-Abo \"{$hook['topic']}\" konnte nicht entfernt werden ({$e->getMessage()}).";
            }
        }
        $this->pdo->prepare('DELETE FROM connect_webhook_subscriptions WHERE connection_id = :id')->execute(['id' => $connectionId]);

        return $notes;
    }

    /**
     * Nur lesen: alle Webhook-Abos, die Shopify fuer diesen Shop gerade kennt, mit
     * Zuordnung "connect" / "legacy" / "other" (fuer admin/shopify_activity.php).
     *
     * @return list<array{id: int|string, topic: string, address: string, owner: string}>
     */
    public function inspectWebhooks(string $shop): array
    {
        $connStmt = $this->pdo->prepare(
            "SELECT c.credentials FROM connect_connections c
             JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
        );
        $connStmt->execute(['shop' => $shop]);
        $encrypted = $connStmt->fetchColumn();
        if ($encrypted === false) {
            throw new \RuntimeException('Keine Shopify-Connection.');
        }
        $credentials = $this->crypto->decryptArray((string) $encrypted);
        $shopDomain = (string) parse_url((string) ($credentials['base_url'] ?? ''), PHP_URL_HOST);
        $token = (string) ($credentials['header_value'] ?? '');
        if ($shopDomain === '' || $token === '') {
            throw new \RuntimeException('Shopify-Zugangsdaten unvollständig.');
        }

        $connectHost = (string) parse_url($this->appUrl, PHP_URL_HOST);
        $result = [];
        foreach ($this->listWebhooks($shopDomain, $token) as $hook) {
            $owner = 'other';
            if ($connectHost !== '' && str_contains($hook['address'], '//' . $connectHost . '/webhooks/')) {
                $owner = 'connect';
            } elseif (str_contains($hook['address'], self::LEGACY_HOST_MARKER)) {
                $owner = 'legacy';
            }
            $result[] = $hook + ['owner' => $owner];
        }

        return $result;
    }

    /**
     * @return list<array{id: int|string, topic: string, address: string}>
     */
    private function listWebhooks(string $shopDomain, string $token): array
    {
        $response = $this->httpClient->request(
            'GET',
            "https://{$shopDomain}/admin/api/{$this->apiVersion}/webhooks.json?limit=250",
            ['X-Shopify-Access-Token' => $token]
        );
        if (!$response->isSuccess()) {
            throw new \RuntimeException("HTTP {$response->statusCode}");
        }
        $decoded = json_decode($response->body, true);
        $hooks = [];
        foreach ((array) ($decoded['webhooks'] ?? []) as $hook) {
            if (is_array($hook) && isset($hook['id'], $hook['topic'])) {
                $hooks[] = ['id' => $hook['id'], 'topic' => (string) $hook['topic'], 'address' => (string) ($hook['address'] ?? '')];
            }
        }

        return $hooks;
    }

    /**
     * @return array{id: int|string, topic: string, address: string}
     */
    private function createWebhook(string $shopDomain, string $token, string $topic, string $address): array
    {
        $response = $this->httpClient->request(
            'POST',
            "https://{$shopDomain}/admin/api/{$this->apiVersion}/webhooks.json",
            ['X-Shopify-Access-Token' => $token, 'Content-Type' => 'application/json'],
            json_encode(['webhook' => ['topic' => $topic, 'address' => $address, 'format' => 'json']], JSON_THROW_ON_ERROR)
        );
        if (!$response->isSuccess()) {
            throw new \RuntimeException("HTTP {$response->statusCode}: " . mb_substr($response->body, 0, 200));
        }
        $decoded = json_decode($response->body, true);
        $id = $decoded['webhook']['id'] ?? null;
        if ($id === null) {
            throw new \RuntimeException('keine Webhook-ID in der Antwort');
        }

        return ['id' => $id, 'topic' => $topic, 'address' => $address];
    }

    private function deleteWebhook(string $shopDomain, string $token, string $id): void
    {
        $response = $this->httpClient->request(
            'DELETE',
            "https://{$shopDomain}/admin/api/{$this->apiVersion}/webhooks/{$id}.json",
            ['X-Shopify-Access-Token' => $token]
        );
        // 404 = schon weg, gilt als erledigt
        if (!$response->isSuccess() && $response->statusCode !== 404) {
            throw new \RuntimeException("HTTP {$response->statusCode}");
        }
    }

    /**
     * Haelt connect_webhook_subscriptions synchron (Anzeige in der Ablauf-
     * Ansicht, WebhookRegistrar::subscribedTopics()).
     */
    private function rememberSubscription(int $connectionId, string $topic, string $webhookId): void
    {
        $this->pdo->prepare(
            'INSERT INTO connect_webhook_subscriptions (connection_id, topic, external_webhook_id)
             VALUES (:connection_id, :topic, :webhook_id)
             ON DUPLICATE KEY UPDATE external_webhook_id = :webhook_id2'
        )->execute(['connection_id' => $connectionId, 'topic' => $topic, 'webhook_id' => $webhookId, 'webhook_id2' => $webhookId]);
    }
}
