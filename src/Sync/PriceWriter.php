<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\Generic\GenericHttpConnector;
use PDO;

/**
 * Preise schreiben -- gemeinsam fuer Preissynchronisation und Sammelaenderungen (Migration 0088).
 *
 *   Shopify:     productVariantsBulkUpdate je Produkt (bis 250 Varianten in EINEM Aufruf), Preis
 *                und optional Vergleichspreis ("compareAtPrice", Sale-Anzeige).
 *   Zielsystem:  Operation "update_price" am Object des Zielsystems (Connector Builder, z.B.
 *                helloCash PUT articles/{{id}}). Body = Kennung + Preisfeld unter dem Namen aus
 *                dessen field_path (letztes Segment, z.B. gross_selling_price). Fehlt die
 *                Operation, kann ins Zielsystem nicht geschrieben werden (klare Meldung).
 *
 * Nach jedem erfolgreichen Schreiben wird der Zwischenspeicher sofort nachgezogen, damit der
 * naechste Vergleich nicht mit dem alten Preis rechnet.
 */
final class PriceWriter
{
    private const MUTATION = <<<'GRAPHQL'
mutation ($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  result: productVariantsBulkUpdate(productId: $productId, variants: $variants) {
    productVariants { id price compareAtPrice }
    userErrors { field message }
  }
}
GRAPHQL;

    /**
     * @param bool $pricesGross Preis-Basis des Shops (ShopStockRules::forShop()['prices_gross']) -- bestimmt,
     *        welches Preisfeld des Zielsystems geschrieben wird (Rolle "Preis" bzw. "Preis netto")
     */
    public function __construct(private readonly PDO $pdo, private readonly ProductMatchContext $context, private readonly bool $pricesGross = true)
    {
    }

    public function canWriteTarget(): bool
    {
        return $this->context->targetConnector instanceof GenericHttpConnector
            && $this->context->targetConnector->hasOperation($this->context->targetObject->key, 'update_price')
            && ShopStockRules::targetPriceField($this->context->targetObject, $this->pricesGross) !== null;
    }

    /**
     * Varianten EINES Shopify-Produkts schreiben.
     *
     * @param list<array{id: int, external_id: string, new_price: ?string, new_compare_at: ?string, set_compare_at: int}> $items
     * @return array<int, ?string> item id => Fehler (null = geschrieben)
     */
    public function writeShopifyProduct(string $productId, array $items): array
    {
        $connector = $this->context->shopifyConnector;
        if (!$connector instanceof GenericHttpConnector) {
            return array_fill_keys(array_column($items, 'id'), 'Shopify-Connector unbekannt');
        }

        $variants = [];
        foreach ($items as $item) {
            $variant = ['id' => $item['external_id']];
            if ($item['new_price'] !== null) {
                $variant['price'] = self::money((float) $item['new_price']);
            }
            if ((int) $item['set_compare_at'] === 1) {
                $variant['compareAtPrice'] = $item['new_compare_at'] !== null ? self::money((float) $item['new_compare_at']) : null;
            }
            $variants[] = $variant;
        }

        try {
            $response = self::withThrottleRetry(static fn (): array => $connector->graphQlQuery(self::MUTATION, ['productId' => $productId, 'variants' => $variants]));
        } catch (\Throwable $e) {
            return array_fill_keys(array_column($items, 'id'), mb_substr($e->getMessage(), 0, 400));
        }

        $userErrors = $response['data']['result']['userErrors'] ?? [];
        if (is_array($userErrors) && $userErrors !== []) {
            $message = implode('; ', array_map(static fn ($e): string => (string) ($e['message'] ?? 'Fehler'), $userErrors));

            return array_fill_keys(array_column($items, 'id'), mb_substr($message, 0, 400));
        }

        // Zwischenspeicher nachziehen
        $cache = new ObjectCache($this->pdo);
        $priceField = ProductMatchContext::fieldWithComparisonRole($this->context->shopifyObject, 'price');
        $results = [];
        foreach ($items as $item) {
            if ($priceField !== null && $item['new_price'] !== null) {
                $cache->updateCachedFields($this->context->shopifyConnectionId, $this->context->shopifyObject->key, $item['external_id'], [$priceField->key => self::money((float) $item['new_price'])]);
            }
            $results[$item['id']] = null;
        }

        return $results;
    }

    /**
     * Einen Preis im Zielsystem schreiben.
     *
     * @return ?string Fehler, null = geschrieben
     */
    public function writeTarget(string $externalId, float $price): ?string
    {
        if (!$this->canWriteTarget()) {
            return 'Im Connector Builder fehlt am Object "' . $this->context->targetObject->key . '" die Operation "Preis aktualisieren" (update_price) oder ein Feld mit der Rolle "' . ($this->pricesGross ? 'Preis (brutto)' : 'Preis netto') . '".';
        }
        $priceField = ShopStockRules::targetPriceField($this->context->targetObject, $this->pricesGross);
        $identifierKey = ObjectCache::requireIdentifierField($this->context->targetObject)->key;
        $path = (string) ($priceField->path ?? '');
        $bodyKey = $path !== '' ? (string) substr($path, (int) strrpos('.' . $path, '.')) : $priceField->key;
        $bodyKey = ltrim($bodyKey, '.');

        $result = $this->context->targetConnector->write($this->context->targetObject->key, 'update_price', [
            $identifierKey => $externalId,
            'id' => $externalId,
            $bodyKey => round($price, 2),
        ]);
        if (!$result->success) {
            return mb_substr($result->message, 0, 400);
        }
        (new ObjectCache($this->pdo))->updateCachedFields($this->context->targetConnectionId, $this->context->targetObject->key, $externalId, [$priceField->key => round($price, 2)]);

        return null;
    }

    public static function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    /**
     * Shopify drosselt GraphQL ("Throttled") -- kurz warten und erneut versuchen.
     *
     * @param callable(): array<string, mixed> $call
     * @return array<string, mixed>
     */
    private static function withThrottleRetry(callable $call): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $call();
            } catch (\RuntimeException $e) {
                if ($attempt < 5 && stripos($e->getMessage(), 'throttl') !== false) {
                    sleep(2 * $attempt);
                    continue;
                }
                throw $e;
            }
            $code = $response['errors'][0]['extensions']['code'] ?? null;
            if ($code === 'THROTTLED' && $attempt < 5) {
                sleep(2 * $attempt);
                continue;
            }

            return $response;
        }
    }
}
