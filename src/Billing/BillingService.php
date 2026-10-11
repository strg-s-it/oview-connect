<?php

declare(strict_types=1);

namespace Connect\Billing;

use Connect\Config;
use Connect\Connector\ConnectorFactory;
use Connect\Connector\Generic\GenericHttpConnector;
use PDO;

/**
 * Shopify-Abo eines Shops lesen und wechseln (Shopify Billing API, GraphQL).
 *
 * refresh(): currentAppInstallation.activeSubscriptions -> connect_shops.plan*, danach die
 * Intervalle des Abos anwenden (Plans::applyIntervals). Abos aus der alten Welt
 * (REST recurring_application_charges) erscheinen hier genauso, weil es dieselbe App ist.
 *
 * subscribe(): appSubscriptionCreate mit Ersetzen des bisherigen Abos; Shopify zeigt dem
 * Merchant die Bestaetigung und leitet danach in die App zurueck. Erstes Abo 14 Tage Test,
 * jeder Wechsel ohne. Testabrechnung fuer die Shops in CONNECT_BILLING_TEST_SHOPS
 * (Standard wie alte Welt: oview-demo, oview-demo2, strgs-s-it-demo).
 */
final class BillingService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectorFactory $factory,
        private readonly Config $config
    ) {
    }

    /**
     * @return array{plan: ?string, status: string, name: ?string, trial_ends_at: ?string, test: bool}
     */
    public function refresh(string $shop): array
    {
        $data = $this->graphQl($shop, '{ currentAppInstallation { activeSubscriptions { id name status test trialDays createdAt } } }');
        $subscriptions = $data['data']['currentAppInstallation']['activeSubscriptions'] ?? [];

        $active = null;
        foreach (is_array($subscriptions) ? $subscriptions : [] as $subscription) {
            if (($subscription['status'] ?? '') === 'ACTIVE') {
                $active = $subscription;
                break;
            }
        }

        $plan = $active !== null ? Plans::keyForName((string) ($active['name'] ?? '')) : null;
        $trialEnds = null;
        if ($active !== null && (int) ($active['trialDays'] ?? 0) > 0 && !empty($active['createdAt'])) {
            $end = (new \DateTimeImmutable((string) $active['createdAt']))->modify('+' . (int) $active['trialDays'] . ' days');
            $trialEnds = $end > new \DateTimeImmutable() ? $end->setTimezone(new \DateTimeZone('Europe/Vienna'))->format('Y-m-d H:i:s') : null;
        }

        $this->pdo->prepare(
            'UPDATE connect_shops SET plan = :plan, plan_status = :status, plan_subscription_id = COALESCE(:sid, plan_subscription_id),
                 plan_trial_ends_at = :trial, plan_checked_at = NOW() WHERE shop = :shop'
        )->execute([
            'plan' => $plan,
            'status' => $active !== null ? (string) $active['status'] : 'none',
            'sid' => $active !== null ? (string) $active['id'] : null,
            'trial' => $trialEnds,
            'shop' => $shop,
        ]);
        Plans::applyIntervals($this->pdo, $shop);

        return [
            'plan' => $plan,
            'status' => $active !== null ? (string) $active['status'] : 'none',
            'name' => $active['name'] ?? null,
            'trial_ends_at' => $trialEnds,
            'test' => (bool) ($active['test'] ?? false),
        ];
    }

    /** Abo hoechstens alle 6 Stunden bei Shopify abfragen (z.B. beim Oeffnen der Uebersicht). */
    public function refreshIfStale(string $shop): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT plan_checked_at IS NULL OR plan_checked_at < NOW() - INTERVAL 6 HOUR FROM connect_shops WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);
            if ((int) $stmt->fetchColumn() === 1) {
                $this->refresh($shop);
            }
        } catch (\Throwable) {
            // Abo-Abfrage ist Komfort -- die Seite darf daran nicht scheitern
        }
    }

    /**
     * Neues Abo anlegen (ersetzt das bisherige). Liefert die Shopify-Seite zur Bestaetigung.
     */
    public function subscribe(string $shop, string $planKey): string
    {
        if (!isset(Plans::PLANS[$planKey])) {
            throw new \InvalidArgumentException('Unbekanntes Abo.');
        }
        $plan = Plans::PLANS[$planKey];

        // Erstes Abo mit Test, danach nie wieder (auch Abos aus der alten Welt zaehlen). Vorher
        // frisch bei Shopify abfragen: ein umgezogener Shop, dessen Abo Connect noch nie gelesen
        // hat, haette sonst noch keine Abo-Daten und bekaeme faelschlich nochmal 14 Tage Test.
        try {
            $this->refresh($shop);
        } catch (\Throwable) {
            // Abfrage fehlgeschlagen -- dann gelten die gespeicherten Werte
        }
        $stmt = $this->pdo->prepare('SELECT plan_subscription_id, plan_status FROM connect_shops WHERE shop = :shop');
        $stmt->execute(['shop' => $shop]);
        $row = $stmt->fetch() ?: [];
        $hadSubscription = ($row['plan_subscription_id'] ?? null) !== null || (($row['plan_status'] ?? 'none') !== 'none' && ($row['plan_status'] ?? null) !== null);
        $trialDays = $hadSubscription ? 0 : Plans::TRIAL_DAYS;

        $testShops = array_filter(array_map('trim', explode(',', $this->config->optional('CONNECT_BILLING_TEST_SHOPS', 'oview-demo,oview-demo2,strgs-s-it-demo'))));
        $returnUrl = 'https://admin.shopify.com/store/' . rawurlencode($shop) . '/apps/' . rawurlencode($this->config->optional('CONNECT_SHOPIFY_API_KEY', ''));

        $mutation = 'mutation ($name: String!, $returnUrl: URL!, $test: Boolean, $trialDays: Int, $amount: Decimal!, $currency: CurrencyCode!) {
            appSubscriptionCreate(name: $name, returnUrl: $returnUrl, test: $test, trialDays: $trialDays, replacementBehavior: APPLY_IMMEDIATELY,
                lineItems: [{ plan: { appRecurringPricingDetails: { price: { amount: $amount, currencyCode: $currency }, interval: EVERY_30_DAYS } } }]) {
                confirmationUrl
                userErrors { field message }
            }
        }';
        $data = $this->graphQl($shop, $mutation, [
            'name' => $plan['name'],
            'returnUrl' => $returnUrl,
            'test' => in_array(strtolower($shop), array_map('strtolower', $testShops), true),
            'trialDays' => $trialDays,
            'amount' => $plan['price'],
            'currency' => Plans::CURRENCY,
        ]);

        $result = $data['data']['appSubscriptionCreate'] ?? [];
        $errors = $result['userErrors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            throw new \RuntimeException(implode(' ', array_map(static fn ($e): string => (string) ($e['message'] ?? ''), $errors)));
        }
        $url = (string) ($result['confirmationUrl'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('Shopify hat keine Bestätigungsseite geliefert.');
        }

        // Nach der Rueckkehr sofort neu abfragen
        $this->pdo->prepare('UPDATE connect_shops SET plan_checked_at = NULL WHERE shop = :shop')->execute(['shop' => $shop]);

        return $url;
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function graphQl(string $shop, string $query, array $variables = []): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.shop = :shop AND t.type_key = 'shopify'
             ORDER BY (c.status = 'active') DESC, c.id DESC LIMIT 1"
        );
        $stmt->execute(['shop' => $shop]);
        $connectionId = $stmt->fetchColumn();
        if ($connectionId === false) {
            throw new \RuntimeException('Keine Shopify-Verbindung für diesen Shop.');
        }
        $connector = $this->factory->forConnection((int) $connectionId);
        if (!$connector instanceof GenericHttpConnector) {
            throw new \RuntimeException('Shopify-Verbindung unterstützt keine direkten Abfragen.');
        }

        return $connector->graphQlQuery($query, $variables);
    }
}
