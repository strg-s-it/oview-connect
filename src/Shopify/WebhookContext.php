<?php

declare(strict_types=1);

namespace Connect\Shopify;

use PDO;

/**
 * Ergebnis von webhook_bootstrap.php: HMAC bereits geprueft, Shop und (falls
 * vorhanden) die passende Connection bereits aufgeloest.
 */
final class WebhookContext
{
    public function __construct(
        public readonly PDO $pdo,
        public readonly string $shop,
        public readonly ?int $connectionId,
        public readonly string $rawBody,
        public readonly string $topic
    ) {
    }
}
