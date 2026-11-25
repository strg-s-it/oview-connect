<?php

declare(strict_types=1);

namespace Connect\Billing;

/**
 * Letzte und naechste Abbuchung eines Shopify-Abos.
 *
 * Shopify liefert ueber die Shop-eigene API keine Liste der Abbuchungen (die gibt es nur in der
 * Partner-API), aber je Abo das Ende der laufenden Abrechnungsperiode (currentPeriodEnd). Die
 * Periode beginnt mit der Abbuchung: letzte Abbuchung = Periodenende minus Intervall.
 * Das ist abgeleitet, nicht aus einer Buchung gelesen. Waehrend eines Testzeitraums und bei
 * Testabos gab es keine echte Abbuchung.
 */
final class ChargeInfo
{
    public const ZONE = 'Europe/Vienna';

    /** GraphQL-Teil, den beide Abfragen (Connect und alte Welt) an activeSubscriptions anhaengen. */
    public const GRAPHQL_FIELDS = 'currentPeriodEnd trialDays createdAt test lineItems { plan { pricingDetails { __typename ... on AppRecurringPricing { interval } } } }';

    /**
     * @param array<string, mixed> $subscription ein Eintrag aus activeSubscriptions
     * @return array{last: ?string, next: ?string} Zeitpunkte Y-m-d H:i:s (Wiener Zeit) oder null
     */
    public static function fromSubscription(array $subscription, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $zone = new \DateTimeZone(self::ZONE);
        $periodEnd = trim((string) ($subscription['currentPeriodEnd'] ?? ''));
        if ($periodEnd === '') {
            return ['last' => null, 'next' => null];
        }
        try {
            $end = new \DateTimeImmutable($periodEnd);
            $created = !empty($subscription['createdAt']) ? new \DateTimeImmutable((string) $subscription['createdAt']) : null;
        } catch (\Exception) {
            return ['last' => null, 'next' => null];
        }
        $next = $end->setTimezone($zone)->format('Y-m-d H:i:s');

        // Keine echte Abbuchung: Testabo oder Testzeitraum laeuft noch
        $trialDays = (int) ($subscription['trialDays'] ?? 0);
        $inTrial = $created !== null && $trialDays > 0 && $created->modify('+' . $trialDays . ' days') > $now;
        if (!empty($subscription['test']) || $inTrial) {
            return ['last' => null, 'next' => $next];
        }

        $interval = 'EVERY_30_DAYS';
        foreach ((array) ($subscription['lineItems'] ?? []) as $item) {
            $found = is_array($item) ? ($item['plan']['pricingDetails']['interval'] ?? null) : null;
            if (is_string($found) && $found !== '') {
                $interval = $found;
                break;
            }
        }
        $last = $end->modify($interval === 'ANNUAL' ? '-1 year' : '-30 days');
        // Abo erst nach dem berechneten Beginn angelegt (z.B. Periode kuerzer als Intervall): nie vor dem Anlegen
        if ($created !== null && $last < $created) {
            $last = $created;
        }

        return ['last' => $last->setTimezone($zone)->format('Y-m-d H:i:s'), 'next' => $next];
    }

    /**
     * Text und Farbe fuer die Anzeige.
     *
     * @param string $state active (Abo aktiv), none (Shopify meldet kein aktives Abo), unknown (nie abgefragt/nicht pruefbar)
     * @return array{text: string, class: string} class: Tabler-Farbname fuer das Badge
     */
    public static function describe(?string $last, ?string $next, string $state, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        if ($state === 'none') {
            return ['text' => 'kein aktives Abo', 'class' => 'red'];
        }
        if ($state !== 'active') {
            return ['text' => 'unbekannt', 'class' => 'secondary'];
        }
        if ($last !== null) {
            $days = (int) floor(($now->getTimestamp() - (int) strtotime($last)) / 86400);

            return ['text' => substr($last, 0, 10) . ($days >= 0 ? ' (vor ' . $days . ' Tg.)' : ''), 'class' => 'green'];
        }
        if ($next !== null) {
            return ['text' => 'noch keine (Test), nächste ' . substr($next, 0, 10), 'class' => 'yellow'];
        }

        return ['text' => 'Datum noch nicht abgefragt', 'class' => 'secondary'];
    }
}
