<?php

declare(strict_types=1);

namespace Connect\Billing;

/**
 * Beendet alle aktiven App-Abos eines Shops bei Shopify (appSubscriptionCancel, ohne anteilige
 * Rueckerstattung). Nur eine Admin-Aktion (Umstieg -> "Abo beenden"), nie automatisch.
 *
 * Die App bleibt installiert und laeuft weiter: Connect sperrt Shops ohne Abo nicht. Das Abo endet
 * sofort bei Shopify, ein Merchant kann spaeter ein neues waehlen (dann ohne neuen Testzeitraum).
 *
 * Die GraphQL-Verbindung kommt von aussen (Connect-Connection mit Token-Erneuerung oder direkter
 * Aufruf mit dem Token der alten Welt), damit beide Wege dieselbe Logik nutzen.
 */
final class SubscriptionCanceller
{
    /**
     * @param callable(string, array<string, mixed>): array<string, mixed> $graphQl Abfrage + Variablen -> dekodierte Antwort
     * @return array{cancelled: list<string>, errors: list<string>} cancelled = Namen der beendeten Abos
     */
    public static function run(callable $graphQl): array
    {
        $data = $graphQl('{ currentAppInstallation { activeSubscriptions { id name status } } }', []);
        $subscriptions = $data['data']['currentAppInstallation']['activeSubscriptions'] ?? null;
        if (!is_array($subscriptions)) {
            throw new \RuntimeException('Shopify hat die Abos nicht geliefert.');
        }

        $cancelled = [];
        $errors = [];
        foreach ($subscriptions as $subscription) {
            if (!is_array($subscription) || strtoupper((string) ($subscription['status'] ?? '')) !== 'ACTIVE') {
                continue;
            }
            $name = (string) ($subscription['name'] ?? 'Abo');
            // Als "result" aliasiert, damit die userErrors-Auswertung greift
            $response = $graphQl(
                'mutation ($id: ID!) { result: appSubscriptionCancel(id: $id) { appSubscription { id status } userErrors { field message } } }',
                ['id' => (string) ($subscription['id'] ?? '')]
            );
            $userErrors = $response['data']['result']['userErrors'] ?? [];
            if (is_array($userErrors) && $userErrors !== []) {
                $errors[] = $name . ': ' . implode(' ', array_map(static fn ($e): string => (string) ($e['message'] ?? ''), $userErrors));
            } elseif (strtoupper((string) ($response['data']['result']['appSubscription']['status'] ?? '')) === 'CANCELLED') {
                $cancelled[] = $name;
            } else {
                $errors[] = $name . ': Shopify hat die Kündigung nicht bestätigt.';
            }
        }

        return ['cancelled' => $cancelled, 'errors' => $errors];
    }
}
