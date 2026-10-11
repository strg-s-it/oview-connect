<?php

declare(strict_types=1);

namespace Connect\Shopify;

/**
 * HMAC-Verifikation fuer eingehende Shopify-Webhooks. Gleiches Verfahren
 * wie inc/webhook_helper.php in der alten Welt (HMAC-SHA256 ueber den rohen
 * Body, base64-kodiert, verglichen mit dem X-Shopify-Hmac-Sha256-Header) --
 * aber eigenstaendig implementiert mit eigenem Secret aus connect/.env
 * (CONNECT_SHOPIFY_API_SECRET), keine Code-Abhaengigkeit zur alten Welt.
 */
final class WebhookVerifier
{
    public function __construct(private readonly string $apiSecret)
    {
    }

    public function verify(string $rawBody, string $hmacHeader): bool
    {
        if ($hmacHeader === '') {
            return false;
        }

        $calculated = base64_encode(hash_hmac('sha256', $rawBody, $this->apiSecret, true));

        if (!hash_equals($calculated, $hmacHeader)) {
            return false;
        }

        // Shopify-Webhooks sind immer ein JSON-Objekt. Dasselbe App-Secret signiert auch
        // Dinge, die ein Merchant im Browser sieht (hmac= in der Admin-URL, sig= im
        // Handoff aus connect_beta.php) -- deren signierter Text ist nie JSON. Ohne diese
        // Pruefung liesse sich so eine Signatur als gueltiger Webhook fuer einen fremden
        // Shop wiederverwenden (die Shop-Domain kommt ungeprueft aus dem Header).
        return is_array(json_decode($rawBody, true));
    }

    /**
     * Shop-Domain aus dem signierten Body (shop_domain bei customers/* und shop/redact,
     * myshopify_domain bei app/uninstalled) -- '' wenn der Body keine enthaelt.
     */
    public static function bodyShopDomain(string $rawBody): string
    {
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return '';
        }
        foreach (['shop_domain', 'myshopify_domain'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key])) {
                return strtolower($decoded[$key]);
            }
        }

        return '';
    }

    /**
     * Bricht die Ausfuehrung mit HTTP 401 ab, wenn die Verifikation
     * fehlschlaegt -- fuer den direkten Einsatz in webhooks/*.php.
     */
    public function verifyOrDie(string $rawBody, string $hmacHeader): void
    {
        if (!$this->verify($rawBody, $hmacHeader)) {
            http_response_code(401);
            exit('Unauthorized');
        }
    }
}
