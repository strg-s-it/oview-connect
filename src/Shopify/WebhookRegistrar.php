<?php

declare(strict_types=1);

namespace Connect\Shopify;

use Connect\Http\HttpClient;
use PDO;

/**
 * Registriert/entfernt Shopify-Webhook-Abos fuer eine Connection und haelt
 * connect_webhook_subscriptions synchron. Bewusst NICHT ueber
 * GenericHttpConnector::write() abgebildet -- Webhook-Verwaltung ist eine
 * einmalige Admin-Aktion, kein wiederkehrend synchronisiertes Object.
 *
 * Der Webhook-Adresse wird bewusst KEIN Shop-/Connection-Identifier
 * angehaengt: Shopify sendet bei jedem Webhook-Aufruf ohnehin den Header
 * X-Shopify-Shop-Domain mit, ueber den der Empfaenger (webhooks/*.php) die
 * passende Connection selbst nachschlagen kann (gleiches Prinzip wie in der
 * alten Welt, siehe webhooks/shop_data_erasure.php).
 */
final class WebhookRegistrar
{
    /**
     * Verfuegbare, bei Shopify abonnierbare Topics -> [Anzeigename, Empfaenger-Dateiname].
     * "shop/redact", "customers/redact", "customers/data_request" (GDPR-
     * Pflicht-Webhooks) sind bewusst NICHT hier gelistet -- die sind fix in
     * der Shopify-Partner-App-Konfiguration hinterlegt (siehe App-Dashboard-
     * Screenshot) und bleiben Aufgabe der alten Welt, gelten App-weit.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const AVAILABLE_TOPICS = [
        'orders/paid' => ['Bestellung bezahlt', 'order_paid.php'],
        'orders/create' => ['Bestellung erstellt (z.B. fuer Shops ohne Direktzahlung)', 'order_created.php'],
        'products/update' => ['Produkt aktualisiert', 'product_update.php'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly HttpClient $httpClient,
        private readonly string $appUrl,
        private readonly string $apiVersion
    ) {
    }

    /**
     * Abonniert ein Topic bei Shopify und speichert das Ergebnis.
     */
    public function subscribe(int $connectionId, string $topic, string $shopDomain, string $accessToken): void
    {
        if (!isset(self::AVAILABLE_TOPICS[$topic])) {
            throw new \InvalidArgumentException("Unbekanntes Webhook-Topic: {$topic}");
        }

        // Bereits abonniert (z.B. ein frueherer erfolgreicher Aufruf) -- ohne diese Pruefung
        // lehnt Shopify eine zweite Registrierung derselben Adresse+Thema mit HTTP 422
        // ("address ... has already been taken") ab, was bei jedem erneuten Speichern
        // derselben Einstellung (z.B. connect/public/app/_sync_mappings_body.php) denselben
        // (harmlosen, aber verwirrenden) Fehler gezeigt haette, obwohl das Abo laengst besteht.
        if (in_array($topic, $this->subscribedTopics($connectionId), true)) {
            return;
        }

        [, $receiverFile] = self::AVAILABLE_TOPICS[$topic];
        $address = rtrim($this->appUrl, '/') . '/webhooks/' . $receiverFile;

        $body = json_encode([
            'webhook' => ['topic' => $topic, 'address' => $address, 'format' => 'json'],
        ], JSON_THROW_ON_ERROR);

        $response = $this->httpClient->request(
            'POST',
            "https://{$shopDomain}/admin/api/{$this->apiVersion}/webhooks.json",
            ['X-Shopify-Access-Token' => $accessToken, 'Content-Type' => 'application/json'],
            $body
        );

        if (!$response->isSuccess()) {
            throw new \RuntimeException("Webhook-Registrierung fuer \"{$topic}\" fehlgeschlagen: HTTP {$response->statusCode} - {$response->body}");
        }

        $decoded = json_decode($response->body, true);
        $webhookId = (string) ($decoded['webhook']['id'] ?? '');

        if ($webhookId === '') {
            throw new \RuntimeException("Webhook-Registrierung fuer \"{$topic}\" lieferte keine Webhook-ID zurueck.");
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO connect_webhook_subscriptions (connection_id, topic, external_webhook_id)
             VALUES (:connection_id, :topic, :webhook_id)
             ON DUPLICATE KEY UPDATE external_webhook_id = :webhook_id2'
        );
        $stmt->execute([
            'connection_id' => $connectionId,
            'topic' => $topic,
            'webhook_id' => $webhookId,
            'webhook_id2' => $webhookId,
        ]);
    }

    /**
     * Entfernt ein Topic-Abo bei Shopify und aus der lokalen Tabelle.
     */
    public function unsubscribe(int $connectionId, string $topic, string $shopDomain, string $accessToken): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT external_webhook_id FROM connect_webhook_subscriptions WHERE connection_id = :connection_id AND topic = :topic'
        );
        $stmt->execute(['connection_id' => $connectionId, 'topic' => $topic]);
        $webhookId = $stmt->fetchColumn();

        if ($webhookId !== false) {
            $this->httpClient->request(
                'DELETE',
                "https://{$shopDomain}/admin/api/{$this->apiVersion}/webhooks/{$webhookId}.json",
                ['X-Shopify-Access-Token' => $accessToken]
            );
        }

        $delete = $this->pdo->prepare('DELETE FROM connect_webhook_subscriptions WHERE connection_id = :connection_id AND topic = :topic');
        $delete->execute(['connection_id' => $connectionId, 'topic' => $topic]);
    }

    /**
     * @return string[] Liste der aktuell fuer diese Connection abonnierten Topics
     */
    public function subscribedTopics(int $connectionId): array
    {
        $stmt = $this->pdo->prepare('SELECT topic FROM connect_webhook_subscriptions WHERE connection_id = :connection_id');
        $stmt->execute(['connection_id' => $connectionId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
