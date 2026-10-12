<?php

declare(strict_types=1);

namespace Connect\Sync\Document;

/**
 * Bereitet eine Shopify-Bestellung (Datensatz des Shopify-"order"-Objects, wie
 * von ConnectorInterface::read()/readOne() geliefert) systemneutral fuer
 * Rechnungen/Belege auf -- alle Shopify-Eigenheiten werden HIER EINMAL
 * aufgeloest, damit kein Zielsystem (HelloCash, MGM, ...) sie selbst kennen
 * muss:
 *   - Rabatte aus discountAllocations (Positions- UND Bestellrabatte, #1149)
 *   - "taxable": false mit nominellem Steuersatz (#1218)
 *   - Netto-Preise (connect_shops.shopify_prices_include_tax)
 *   - Versand als Position(en), bei mehreren Steuersaetzen aufgeteilt (#1299)
 *   - Versandrabatt (z.B. Gratisversand)
 *
 * Zwei Schritte:
 *   1. prepare(): neutrale Positionen ("lines") -- Artikel UND Versand, jeweils
 *      mit Originalpreis, Positionssumme nach Rabatt (immer brutto), Steuer.
 *   2. render(): Ausgabe-Preis/-Rabatt je Position nach dem Belegprofil des
 *      Zielsystems (siehe DocumentProfile) -- welche Rabattart es kennt, wie es
 *      rundet. Faellt auf den bereits rabattierten Preis zurueck (bei Cent-Rest
 *      auf zwei Positionen aufgeteilt), wenn der Rabatt nicht exakt darstellbar
 *      ist. Die Summe stimmt damit immer centgenau mit Shopify ueberein.
 *
 * Herausgeloest aus Connect\Sync\InvoiceService (2026-10-01, Plan "Rechnungen
 * ueber die Oberflaeche"), dort bisher fest auf das HelloCash-Format verdrahtet.
 */
final class OrderPreparer
{
    /**
     * Vergleichswert (connect_shops.comparison_key) -> Feldschluessel der
     * Shopify-Bestellposition fuer den Artikelabgleich im Zielsystem.
     */
    public const LINE_ITEM_MATCH_FIELDS = [
        'sku' => 'line_sku',
        'barcode' => 'line_barcode',
        'title' => 'line_title',
    ];

    /**
     * @param array<string, mixed> $orderRow
     * @param bool $pricesIncludeTax connect_shops.shopify_prices_include_tax (NULL gilt als true)
     * @param ?callable(string): ?string $articleResolver Vergleichswert der Position -> ID
     *        des passenden Artikels im Zielsystem (oder null), siehe InvoiceService
     * @return array{lines: list<array<string, mixed>>, computed_total_tax: float, notes: list<string>}
     */
    public function prepare(array $orderRow, bool $pricesIncludeTax, ?string $comparisonKey = null, ?callable $articleResolver = null): array
    {
        $lines = [];
        $notes = [];
        $computedTotalTax = 0.0;
        $matchFieldKey = $comparisonKey !== null ? (self::LINE_ITEM_MATCH_FIELDS[$comparisonKey] ?? null) : null;

        foreach ((array) ($orderRow['line_items'] ?? []) as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }

            $quantity = (float) ($lineItem['line_quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            // line_name = Produkttitel MIT Variante ("Katze - Mittel", Migration
            // 0066), faellt fuer aeltere Cache-Datensaetze auf den reinen Titel zurueck.
            $name = trim((string) ($lineItem['line_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($lineItem['line_title'] ?? ''));
            }
            if ($name === '') {
                $name = trim((string) ($lineItem['line_sku'] ?? '')) ?: 'Artikel';
            }

            // Positionssumme NACH allen Rabatten (Migration 0064): Originalpreis x
            // Menge minus Shopifys Rabatt-Zuteilungen (discountAllocations, enthaelt
            // Positions- UND Bestellrabatte). NICHT discountedUnitPriceSet (Migration
            // 0063): enthaelt laut Shopify keine Bestell-/Code-Rabatte, live bei #1149
            // dadurch 500 statt 425 EUR verrechnet. Fallback fuer vor Migration 0064
            // gecachte Datensaetze: bisheriger Stueckpreis -- eine dadurch falsche
            // Summe faengt die Betrags-Kontrolle ab.
            $hasAllocations = array_key_exists('line_discount_1', $lineItem);
            if ($hasAllocations) {
                $lineDiscount = (float) ($lineItem['line_discount_1'] ?? 0)
                    + (float) ($lineItem['line_discount_2'] ?? 0)
                    + (float) ($lineItem['line_discount_3'] ?? 0);
                $lineTotal = round(((float) ($lineItem['line_price'] ?? 0)) * $quantity - $lineDiscount, 2);
            } else {
                $lineTotal = round(((float) ($lineItem['line_discounted_price'] ?? $lineItem['line_price'] ?? 0)) * $quantity, 2);
            }
            $unitPriceOriginal = (float) ($lineItem['line_price'] ?? 0);
            // Rabattbetrag VOR der Netto->Brutto-Umrechnung (beide Werte in Shopifys Preisbasis).
            $discountAmount = max(0.0, round($unitPriceOriginal * $quantity - $lineTotal, 2));
            $taxRateFraction = (float) ($lineItem['line_tax_rate'] ?? 0);
            $taxAmount = (float) ($lineItem['line_tax_amount'] ?? 0);

            // Live beobachtet (Migration 0062, Bestellung #1218/Shop 3bnyc1-n9):
            // Shopify liefert fuer eine "taxable": false-Position TROTZDEM einen
            // nominellen Steuersatz > 0, der tatsaechliche Betrag ist aber 0 --
            // massgeblich ist Shopifys SELBST berechneter Betrag, nicht der Satz.
            if (abs($taxAmount) < 0.005) {
                $taxRateFraction = 0.0;
            }

            // Netto -> Brutto (Migration 0063): Shopifys gemeldeten Steuerbetrag
            // (nicht zurueckgerechnet) zur Positionssumme addieren.
            if (!$pricesIncludeTax) {
                $lineTotal = round($lineTotal + $taxAmount, 2);
            }

            // Kontrollsumme: Shopifys eigenen Steuerbetrag je Position uebernehmen
            // statt ihn aus (Bruttopreis, Satz) zurueckzurechnen (#1218).
            $computedTotalTax += round($taxAmount, 2);

            $targetArticleId = null;
            if ($articleResolver !== null && $matchFieldKey !== null) {
                $matchValue = trim((string) ($lineItem[$matchFieldKey] ?? ''));
                if ($matchValue !== '') {
                    $targetArticleId = $articleResolver($matchValue);
                }
            }

            $lines[] = self::line([
                'name' => $name,
                'title' => trim((string) ($lineItem['line_title'] ?? '')),
                'sku' => trim((string) ($lineItem['line_sku'] ?? '')),
                'barcode' => trim((string) ($lineItem['line_barcode'] ?? '')),
                'is_shipping' => false,
                'quantity' => $quantity,
                'unit_price_original' => $unitPriceOriginal,
                'line_total' => $lineTotal,
                'tax_rate_percent' => $taxRateFraction * 100, // Shopify: Anteil (0.20) -> Prozent (20)
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'target_article_id' => $targetArticleId,
                // Rabatt als eigene Angabe nur bei Brutto-Preisen (bei Netto-Shops waere
                // der Originalpreis selbst erst umzurechnen) und nur mit Rabatt-Zuteilungen.
                'discount_displayable' => $pricesIncludeTax && $hasAllocations,
            ]);
        }

        foreach ($this->shippingLines($orderRow, $pricesIncludeTax, $computedTotalTax, $notes) as $shippingLine) {
            $lines[] = $shippingLine;
        }

        return ['lines' => $lines, 'computed_total_tax' => $computedTotalTax, 'notes' => $notes];
    }

    /**
     * Ausgabe je Position nach Belegprofil: ergaenzt out_price/out_quantity/
     * out_discount_unit/out_discount_value. Eine Position kann dabei in zwei
     * Ausgabe-Positionen zerfallen (Cent-Rest, siehe splitLineTotal()).
     *
     * @param list<array<string, mixed>> $lines aus prepare()
     * @return list<array<string, mixed>>
     */
    public function render(array $lines, DocumentProfile $profile): array
    {
        $rendered = [];
        foreach ($lines as $line) {
            // Versand-Artikel laut Belegprofil (z.B. Crystalp/MGM "9900"): als
            // target_article_id UND sku, damit es egal ist, welches der beiden
            // Felder die Vorlage auf die Artikelnummer des Zielsystems legt.
            if ($line['is_shipping'] && $profile->shippingArticleId !== null && $line['target_article_id'] === null) {
                $line['target_article_id'] = $profile->shippingArticleId;
                $line['sku'] = $profile->shippingArticleId;
            }

            $discount = $line['discount_displayable']
                ? self::discountFields($profile, (float) $line['unit_price_original'], (float) $line['quantity'], (float) $line['line_total'])
                : null;

            if ($discount !== null) {
                $rendered[] = array_merge($line, [
                    'out_quantity' => $line['quantity'],
                    'out_price' => (float) $line['unit_price_original'],
                    'out_discount_unit' => $discount['discount_unit'],
                    'out_discount_value' => $discount['discount_value'],
                ]);
                continue;
            }

            foreach (self::splitLineTotal((float) $line['quantity'], (float) $line['line_total']) as [$quantity, $price]) {
                $rendered[] = array_merge($line, [
                    'out_quantity' => $quantity,
                    'out_price' => $price,
                    'out_discount_unit' => null,
                    'out_discount_value' => null,
                ]);
            }
        }

        return $rendered;
    }

    /**
     * Kompletter Datensatz des internen Objects "Bestellung (aufbereitet)"
     * (connect_internal/order_prepared, Migration 0067) -- die Schluessel sind
     * genau die field_keys dieses Objects, damit die Mapping-Engine ihn wie
     * jeden anderen Quell-Datensatz uebersetzen kann.
     *
     * @param array<string, mixed> $orderRow Shopify-"order"-Datensatz
     * @param list<array<string, mixed>> $renderedLines aus render()
     * @param array<string, mixed> $extras target_customer_id, setting_cashier_id, setting_payment_method
     * @return array<string, mixed>
     */
    public function buildRecord(array $orderRow, array $renderedLines, DocumentProfile $profile, array $extras = []): array
    {
        $value = static fn (string $key): ?string => isset($orderRow[$key]) && $orderRow[$key] !== '' ? (string) $orderRow[$key] : null;
        $fullName = trim(($value('customer_first_name') ?? '') . ' ' . ($value('customer_last_name') ?? ''));
        // IDs im Zielsystem: rein numerische Werte als Zahl ausgeben (wie bisher an
        // HelloCash gesendet, z.B. "article_id": 21478899 statt "21478899").
        $id = static fn (mixed $raw): int|string|null => $raw === null || $raw === ''
            ? null
            : (ctype_digit((string) $raw) ? (int) $raw : (string) $raw);

        $lines = [];
        foreach ($renderedLines as $line) {
            unset($line['discount_displayable']);
            $line['target_article_id'] = $id($line['target_article_id']);
            $line['target_article_type'] = $line['target_article_id'] !== null ? 'article' : null;
            $line['out_line_total'] = self::renderedGrossTotal($line, $profile);
            $lines[] = $line;
        }

        return [
            'order_number' => $value('order_number'),
            'created_at' => $value('created_at'),
            'source_name' => $value('source_name'),
            'currency' => $value('currency'),
            'email' => $value('email'),
            'phone' => $value('phone'),
            'customer_first_name' => $value('customer_first_name'),
            'customer_last_name' => $value('customer_last_name'),
            'customer_full_name' => $fullName !== '' ? $fullName : null,
            'billing_address1' => $value('billing_address1'),
            'billing_zip' => $value('billing_zip'),
            'billing_city' => $value('billing_city'),
            'billing_country' => $value('billing_country'),
            'shipping_address1' => $value('shipping_address1'),
            'shipping_zip' => $value('shipping_zip'),
            'shipping_city' => $value('shipping_city'),
            'shipping_country' => $value('shipping_country'),
            'address1' => $value('billing_address1') ?? $value('shipping_address1'),
            'zip' => $value('billing_zip') ?? $value('shipping_zip'),
            'city' => $value('billing_city') ?? $value('shipping_city'),
            'country' => $value('billing_country') ?? $value('shipping_country'),
            'payment_transaction_id' => $value('payment_transaction_id'),
            'payment_authorization_code' => $value('payment_authorization_code'),
            'total_gross' => isset($orderRow['total_price']) && is_numeric($orderRow['total_price']) ? (float) $orderRow['total_price'] : null,
            'total_tax' => isset($orderRow['total_tax']) && is_numeric($orderRow['total_tax']) ? (float) $orderRow['total_tax'] : null,
            'target_customer_id' => $id($extras['target_customer_id'] ?? null),
            'setting_cashier_id' => $id($extras['setting_cashier_id'] ?? null),
            'setting_payment_method' => isset($extras['setting_payment_method']) && $extras['setting_payment_method'] !== '' ? (string) $extras['setting_payment_method'] : null,
            // true/false (JSON-Boolean, z.B. HelloCash signature_mandatory), null = Einstellung nicht vorhanden
            'setting_signature_mandatory' => isset($extras['setting_signature_mandatory']) && $extras['setting_signature_mandatory'] !== false
                ? (int) $extras['setting_signature_mandatory'] === 1 : null,
            'lines' => $lines,
        ];
    }

    /**
     * Rechnungsbetrag einer Ausgabe-Position NACH Rabatt, so wie das Zielsystem
     * ihn laut Belegprofil bildet -- fuer die Betrags-Kontrolle.
     *
     * @param array<string, mixed> $renderedLine aus render()
     */
    public static function renderedGrossTotal(array $renderedLine, DocumentProfile $profile): float
    {
        $quantity = (float) $renderedLine['out_quantity'];
        $total = (float) $renderedLine['out_price'] * $quantity;
        $value = (float) ($renderedLine['out_discount_value'] ?? 0);

        return match ($renderedLine['out_discount_unit'] ?? null) {
            'percent' => round($total * (1 - $value / 100), 2),
            'absolute' => round($total - ($profile->absoluteDiscountPerUnit ? $value * $quantity : $value), 2),
            default => round($total, 2),
        };
    }

    /**
     * Rabattangabe, die von (Stueckpreis x Menge) exakt auf $discountedTotal
     * fuehrt -- je nach Belegprofil:
     *   - Prozent, wenn ein Satz mit den erlaubten Nachkommastellen die
     *     rabattierte Summe centgenau ergibt, in beiden denkbaren Rundungs-
     *     Lesarten (pro Position / pro Stueck). HelloCash: nur ganze Zahlen
     *     (live #1152: 25.9 gesendet, als 26 % verrechnet).
     *   - sonst Betrag: pro Position (HelloCash, live getestet: 2 x 100 EUR,
     *     absolute 10 -> 190 EUR) oder pro Stueck (nur wenn centgenau teilbar).
     * Null = nicht darstellbar, Aufrufer nutzt den bereits rabattierten Preis.
     *
     * @return array{discount_unit: string, discount_value: float}|null
     */
    private static function discountFields(DocumentProfile $profile, float $unitPrice, float $quantity, float $discountedTotal): ?array
    {
        if ($profile->discountMode === DocumentProfile::DISCOUNT_NONE) {
            return null;
        }

        $originalTotal = round($unitPrice * $quantity, 2);
        $discount = round($originalTotal - $discountedTotal, 2);
        if ($unitPrice <= 0 || $discount < 0.005 || $discount > $originalTotal + 0.005) {
            return null;
        }

        if ($profile->discountMode !== DocumentProfile::DISCOUNT_ABSOLUTE) {
            $percent = round($discount / $originalTotal * 100, $profile->percentDecimals);
            $perLine = round($originalTotal * (1 - $percent / 100), 2);
            $perUnit = round(round($unitPrice * (1 - $percent / 100), 2) * $quantity, 2);
            if (abs($perLine - $discountedTotal) < 0.005 && abs($perUnit - $discountedTotal) < 0.005) {
                return ['discount_unit' => 'percent', 'discount_value' => $percent];
            }
        }

        if ($profile->discountMode === DocumentProfile::DISCOUNT_PERCENT) {
            return null;
        }

        if ($profile->absoluteDiscountPerUnit) {
            $perUnitDiscount = round($discount / $quantity, 2);
            if (abs($perUnitDiscount * $quantity - $discount) >= 0.005) {
                return null;
            }

            return ['discount_unit' => 'absolute', 'discount_value' => $perUnitDiscount];
        }

        return ['discount_unit' => 'absolute', 'discount_value' => $discount];
    }

    /**
     * Verteilt eine (rabattierte) Positionssumme auf Stueckpreise in Cent-
     * Genauigkeit. Geht die Summe nicht glatt durch die Menge (z.B. 10 EUR Rabatt
     * auf 3 Stueck), entstehen zwei Ausgabe-Positionen: Menge-1 Stueck zum
     * gerundeten Preis, 1 Stueck mit dem Rest.
     *
     * @return list<array{0: float, 1: float}> [Menge, Stueckpreis]
     */
    private static function splitLineTotal(float $quantity, float $lineTotal): array
    {
        $unitPrice = round($lineTotal / $quantity, 2);

        if (abs($unitPrice * $quantity - $lineTotal) < 0.005 || $quantity < 2 || floor($quantity) !== $quantity) {
            return [[$quantity, $unitPrice]];
        }

        return [
            [$quantity - 1, $unitPrice],
            [1.0, round($lineTotal - $unitPrice * ($quantity - 1), 2)],
        ];
    }

    /**
     * Versand als Position(en). Bei gemischten Steuersaetzen liefert Shopify fuer
     * den Versand MEHRERE Steuerzeilen -- dann je Satz eine Position, gewichtet
     * nach dem aus Shopifys Steuerbetrag zurueckgerechneten Bruttoanteil; der
     * letzte Anteil bekommt den Rest (portiert aus jobs/cron_sync_invoice.php,
     * Fix fuer #1299). Liefert Shopify GAR KEINE Steuerangabe zum Versand, wird er
     * mit 0 % angelegt (bewusst abweichend von der alten Welt, die den Satz der
     * zuletzt verarbeiteten Position uebernimmt).
     *
     * Versandrabatt (Migration 0065, z.B. Gratisversand): aus Originalpreis vs.
     * rabattiertem Preis -- als eigene Angabe nur bei Brutto-Preisen und EINEM
     * Steuersatz, sonst bleibt es beim rabattierten Preis.
     *
     * @param array<string, mixed> $orderRow
     * @param list<string> $notes
     * @return list<array<string, mixed>>
     */
    private function shippingLines(array $orderRow, bool $pricesIncludeTax, float &$computedTotalTax, array &$notes): array
    {
        $shippingPrice = (float) ($orderRow['shipping_discounted_price'] ?? $orderRow['shipping_price'] ?? 0);
        $shippingOriginal = (float) ($orderRow['shipping_original_price'] ?? 0);
        $hasDiscount = $pricesIncludeTax && $shippingOriginal > $shippingPrice;

        if ($shippingPrice <= 0 && !$hasDiscount) {
            return [];
        }

        $rateInfos = [];
        foreach ((array) ($orderRow['shipping_tax_lines'] ?? []) as $taxLine) {
            if (!is_array($taxLine)) {
                continue;
            }
            $ratePct = ((float) ($taxLine['shipping_tax_line_rate'] ?? 0)) * 100;
            $taxAmount = (float) ($taxLine['shipping_tax_line_amount'] ?? 0);
            // Bruttoanteil dieses Satzes aus Shopifys Steuerbetrag: brutto = steuer * (100 + satz) / satz.
            $grossShare = $ratePct > 0 ? ($taxAmount * (100 + $ratePct) / $ratePct) : 0.0;
            $rateInfos[] = ['rate' => $ratePct, 'gross_share' => $grossShare, 'tax_amount' => $taxAmount];
        }

        if ($rateInfos === []) {
            return [self::shippingLine('Versand', $hasDiscount ? $shippingOriginal : $shippingPrice, $shippingPrice, 0, 0.0, $hasDiscount)];
        }

        // Netto -> Brutto (Migration 0063), VOR der Aufteilung auf die Saetze.
        if (!$pricesIncludeTax) {
            $shippingPrice = round($shippingPrice + array_sum(array_column($rateInfos, 'tax_amount')), 2);
        }

        $shareSum = array_sum(array_column($rateInfos, 'gross_share'));
        $remaining = $shippingPrice;
        $count = count($rateInfos);
        $lines = [];

        foreach ($rateInfos as $index => $rateInfo) {
            $rate = $rateInfo['rate'];
            if ($index === $count - 1) {
                $portion = round($remaining, 2);
            } else {
                $weight = $shareSum > 0 ? ($rateInfo['gross_share'] / $shareSum) : 0.0;
                $portion = round($shippingPrice * $weight, 2);
            }
            $remaining -= $portion;

            // Kontrollsumme: Shopifys eigenen Steuerbetrag dieses Satzes uebernehmen.
            $computedTotalTax += round($rateInfo['tax_amount'], 2);

            $discountable = $hasDiscount && $count === 1;
            $lines[] = self::shippingLine(
                // round() nur fuer die Anzeige (Gleitkomma-Artefakte vermeiden).
                $count > 1 ? 'Versand (' . round($rate, 2) . '%)' : 'Versand',
                $discountable ? $shippingOriginal : $portion,
                $portion,
                $rate,
                $rateInfo['tax_amount'],
                $discountable
            );
        }

        if ($count > 1) {
            $rateLabels = implode(', ', array_map(static fn (array $r): string => round($r['rate'], 2) . '%', $rateInfos));
            $notes[] = "Versand auf {$count} Steuersätze aufgeteilt ({$rateLabels}).";
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private static function shippingLine(string $name, float $unitPriceOriginal, float $lineTotal, float|int $taxRatePercent, float $taxAmount, bool $discountDisplayable): array
    {
        return self::line([
            'name' => $name,
            'title' => $name,
            'sku' => '',
            'barcode' => '',
            'is_shipping' => true,
            'quantity' => 1.0,
            'unit_price_original' => $unitPriceOriginal,
            'line_total' => $lineTotal,
            'tax_rate_percent' => $taxRatePercent,
            'tax_amount' => $taxAmount,
            'target_article_id' => null,
            'discount_displayable' => $discountDisplayable,
        ]);
    }

    /**
     * Ergaenzt abgeleitete Felder einer neutralen Position.
     *
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private static function line(array $line): array
    {
        $line['tax_rate_fraction'] = $line['tax_rate_percent'] / 100;
        $line['line_total_net'] = round($line['line_total'] - $line['tax_amount'], 2);
        $line['discount_amount'] ??= max(0.0, round($line['unit_price_original'] * $line['quantity'] - $line['line_total'], 2));

        return $line;
    }
}
