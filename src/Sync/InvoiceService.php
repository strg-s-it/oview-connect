<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Config;
use Connect\Connector\ConnectorFactory;
use Connect\Connector\ConnectorInterface;
use Connect\Mail\InvoiceMailer;
use Connect\Mapping\MappingEngine;
use Connect\Security\Crypto;
use Connect\Sync\Document\DocumentProfile;
use Connect\Sync\Document\OrderPreparer;
use PDO;

/**
 * Legt bei einer bezahlten Shopify-Bestellung automatisch eine Rechnung im
 * Zielsystem (aktuell: HelloCash) an -- ausgeloest ueber connect_sync_mappings.
 * mapping_kind='invoice' (Migration 0054, gleiches Prinzip wie mapping_kind=
 * 'stock_sync' fuer StockSyncRunner, siehe dort und Connect\Sync\SyncRunner::
 * translateAndWrite()). Eigene Klasse statt der generischen MappingEngine, weil
 * "Kunde suchen/anlegen, DANACH erst die Rechnung schreiben" ein mehrstufiger,
 * abhaengiger Schreibvorgang ist, den die Mapping Engine (genau EIN Schreib-
 * zugriff pro Datensatz) nicht abbildet.
 *
 * Nutzer-Entscheidung (2026-09-25): Kundenzuordnung IST Teil von v1 (zuerst
 * per E-Mail suchen, sonst per Name, sonst neu anlegen) -- anders als zunaechst
 * vorgeschlagen.
 *
 * Aus der alten Welt (jobs/cron_sync_invoice.php) NACHGEZOGEN (Migration 0057,
 * Nutzer-Entscheidung 2026-09-27: "da Rechnungen das kritischste sind, bitte
 * alles notwendige einbauen"), siehe OrderPreparer (Versand) und die
 * Steuer-Kontrolle in createForOrder():
 *   - Steuer-Kontrollrechnung gegen Shopifys eigenes "total_tax" -- weicht die
 *     selbst aufsummierte Steuer deutlich ab, wird NICHT angelegt, sondern der
 *     Betreiber informiert.
 *   - Versandkosten-Aufteilung auf MEHRERE Steuersaetze bei gemischten
 *     Warenkoerben (statt bisher: ein einzelner Satz fuer den gesamten Versand).
 *
 * Ebenfalls nachgezogen (Migration 0058, Nutzer-Entscheidung 2026-09-27,
 * nachdem eine Pruefung ergab: mindestens fuer Shop "neherashop" war die
 * komplette Rechnungssynchronisation aus genau diesem Grund deaktiviert,
 * siehe jobs/debug_fetch_shopify_invoices_neherashop.php): die beiden
 * Sondermodi aus settings.php/jobs/cron_sync_invoice.php:
 *   - invoice_pos_only ("Modus": ONLINE & POS / POS ONLY): nur Kassen-
 *     bestellungen (Shopifys sourceName = "pos") bekommen automatisch eine
 *     Rechnung, siehe Pruefung direkt am Anfang von createForOrder().
 *   - invoice_suppress_and_adjust_stock ("Rechnungen nicht anlegen", in der
 *     alten UI mittlerweile versteckt): legt GAR KEINE Rechnung an, reduziert
 *     stattdessen nur den Lagerbestand je Position -- siehe
 *     adjustStockForOrder().
 *
 * Zwei weitere, bisher hartcodierte Annahmen behoben (Migration 0063,
 * Nutzer-Entscheidung 2026-09-28, gefunden bei der Frage "gibt's noch Punkte
 * die hardcoded sind, obwohl es dafuer eine Einstellung gibt?"):
 *   - Rabatte: Positions-/Versandpreise nutzen jetzt Shopifys eigene, bereits
 *     rabattierte Endpreise (LineItem.discountedUnitPriceSet/ShippingLine.
 *     discountedPriceSet) statt sie manuell nachzurechnen wie die alte Welt
 *     (dortiger Bug: ein fixer Rabattbetrag auf den Versand wird dort
 *     faelschlich wie ein Prozentsatz behandelt, discount_applications.value
 *     ist bei Shopify ein Prozent/Betrag-Union-Typ).
 *   - Netto/Brutto: connect_shops.shopify_prices_include_tax (Migration 0046,
 *     bisher NUR vom Produktimport genutzt) wurde hier komplett ignoriert --
 *     Preise galten immer als Brutto. Gilt jetzt auch hier: bei Netto-Preisen
 *     wird der von Shopify gemeldete Steuerbetrag (line_tax_amount/
 *     shipping_tax_line_amount, NICHT zurueckgerechnet) zum Preis addiert.
 *
 * Positions-Abgleich (welcher HelloCash-Artikel zu welcher Shopify-Position
 * gehoert) nutzt denselben, bereits gecachten Artikelbestand wie der
 * Lagerbestand-Abgleich (Connect\Sync\StockSyncRunner/ProductComparisonService)
 * und denselben shop-weiten Vergleichswert (connect_shops.comparison_key,
 * 'sku'|'barcode'|'title', siehe LINE_ITEM_MATCH_FIELDS) -- alle drei sind seit
 * Migration 0056 auf Bestellpositions-Ebene verfuegbar. Findet sich kein
 * passendes Ziel-Object/-Feld (z.B. noch nicht im Connector Builder markiert),
 * wird die Position ohne HelloCash-Artikelverknuepfung angelegt (funktioniert,
 * da article_id/type in der HelloCash-API optional sind).
 */
final class InvoiceService
{
    /** Von HelloCash fuer NEUE Kunden akzeptierte Laender (POST /customers, country_code-Enum). */
    private const HELLOCASH_COUNTRIES = ['at', 'de', 'cz', 'ch', 'sk', 'hu', 'bg', 'pl', 'ro', 'fr', 'es'];

    /** Kundenzuordnung (connect_shops.invoice_customer_match, Migration 0095) */
    public const CUSTOMER_MATCH_EMAIL_NAME = 'email_name';
    public const CUSTOMER_MATCH_EMAIL = 'email';
    public const CUSTOMER_MATCH_LABELS = [
        self::CUSTOMER_MATCH_EMAIL_NAME => 'E-Mail-Adresse, sonst Vor- und Nachname',
        self::CUSTOMER_MATCH_EMAIL => 'Nur E-Mail-Adresse',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectorFactory $connectorFactory
    ) {
    }

    /**
     * @param array<string, mixed> $orderRow Shopify "order"-Datensatz, wie von ConnectorInterface::read()/readOne() geliefert
     * @param ?int $syncRunId Sync-Lauf, der diesen Aufruf ausgeloest hat (Webhook-Einzeldatensatz
     *        ODER Scheduler-"Uebersetzungs-Nachlauf"-Sicherheitsnetz, siehe SyncRunner::
     *        translateAndWrite()) -- nur zur Nachvollziehbarkeit im Protokoll (admin/
     *        job_log.php-Link, siehe app/_invoices_body.php), fuer die Rechnungserstellung
     *        selbst ohne Bedeutung. Vorher gar nicht durchgereicht -- ein Rechnungs-
     *        Protokolleintrag liess sich bisher NICHT einem Lauf zuordnen.
     * @return array{success: bool, skipped: bool, targetExternalId: ?string, message: string}
     */
    public function createForOrder(string $shop, array $orderRow, ?int $syncRunId = null): array
    {
        // Bestellnummer JEDEM Protokoll-Eintrag voranstellen (Erfolg wie Fehlschlag) --
        // ohne eigene Spalte in connect_activity_log (siehe app/_invoices_body.php,
        // "Rechnungen"-Uebersicht in Admin/Merchant-Ansicht: filtert/zeigt genau
        // diese Tabelle, braucht die Bestellnummer aber lesbar in der Nachricht,
        // nicht erst aus dem rohen Request/Response herausgesucht).
        $orderNumber = (string) ($orderRow['order_number'] ?? '?');

        $shopRow = $this->loadShop($shop);
        if ($shopRow === null || $shopRow['target_connection_id'] === null) {
            $message = 'Kein Zielsystem verbunden (connect_shops.target_connection_id).';
            ActivityLog::log($this->pdo, $shop, null, 'invoice', 'create', false, "Bestellung {$orderNumber}: {$message}", null, null, null, $syncRunId);

            return self::result(false, true, null, $message);
        }

        // Sondermodus "Nur Kassenbestellungen" (connect_shops.invoice_pos_only,
        // Migration 0058, portiert aus der alten Welt: settings.php "Modus"/
        // jobs/cron_sync_invoice.php $pos_only) -- so frueh wie moeglich geprueft
        // (VOR Connector-Aufbau/Kundenzuordnung), da eine Online-Bestellung in
        // diesem Modus komplett folgenlos bleiben soll, kein Fehlerfall.
        if ((int) ($shopRow['invoice_pos_only'] ?? 0) === 1) {
            $sourceName = strtolower(trim((string) ($orderRow['source_name'] ?? '')));
            if ($sourceName !== 'pos') {
                $message = 'Übersprungen (Modus "Nur Kassenbestellungen" aktiv, Bestellquelle "'
                    . ($sourceName !== '' ? $sourceName : 'unbekannt') . '" ist keine Kassenbestellung).';
                ActivityLog::log($this->pdo, $shop, null, 'invoice', 'create', true, "Bestellung {$orderNumber}: {$message}", null, null, null, $syncRunId);

                return self::result(true, true, null, $message);
            }
        }

        try {
            $targetConnector = $this->connectorFactory->forConnection((int) $shopRow['target_connection_id']);
        } catch (\Throwable $e) {
            $message = 'Ziel-Connector konnte nicht aufgebaut werden: ' . $e->getMessage();
            $this->notifyError($shop, $shopRow, $message, $orderRow);
            ActivityLog::log($this->pdo, $shop, null, 'invoice', 'create', false, "Bestellung {$orderNumber}: {$message}", null, null, null, $syncRunId);

            return self::result(false, false, null, $message);
        }

        // Sondermodus "Nur Lagerbestand anpassen" (connect_shops.
        // invoice_suppress_and_adjust_stock, Migration 0058, portiert aus der
        // alten Welt: settings.php "Rechnungen nicht anlegen" (in der dortigen
        // UI mittlerweile versteckt)/jobs/cron_sync_invoice.php $suppress_invoice)
        // -- eigener, viel einfacherer Ablauf: keine Kundenzuordnung, keine
        // Rechnung, kein Mailversand, siehe adjustStockForOrder().
        if ((int) ($shopRow['invoice_suppress_and_adjust_stock'] ?? 0) === 1) {
            return $this->adjustStockForOrder($shop, $shopRow, $orderRow, $targetConnector, $orderNumber, $syncRunId);
        }

        // Hinweise, die NICHT zum Abbruch fuehren (z.B. "ohne Kundenzuordnung"), aber in
        // Protokoll und Rueckmeldung sichtbar bleiben sollen.
        $notes = [];

        $customerId = null;
        try {
            $customerId = $this->resolveCustomer($targetConnector, $orderRow, $notes, ($shopRow['invoice_customer_match'] ?? '') === self::CUSTOMER_MATCH_EMAIL);
        } catch (\Throwable $e) {
            $notes[] = 'Kunde: ' . $e->getMessage();
        }

        $computedTotalTax = 0.0;
        // NULL (Einstellung noch nicht festgelegt, siehe Migration 0046) gilt als
        // Brutto -- bisheriges, unveraendertes Verhalten, bis der Shop das explizit
        // auf Netto stellt.
        $pricesIncludeTax = $shopRow['shopify_prices_include_tax'] === null || (int) $shopRow['shopify_prices_include_tax'] === 1;
        $items = $this->buildItems((int) $shopRow['target_connection_id'], $targetConnector, $orderRow, (string) $shopRow['comparison_key'], $notes, $computedTotalTax, $pricesIncludeTax);
        if ($items === []) {
            $message = 'Bestellung hat keine verwertbaren Positionen (Menge <= 0 oder keine Positionen vorhanden), Rechnung nicht angelegt.';
            $this->notifyError($shop, $shopRow, $message, $orderRow);
            ActivityLog::log($this->pdo, $shop, null, 'invoice', 'create', false, "Bestellung {$orderNumber}: {$message}", null, null, null, $syncRunId);

            return self::result(false, false, null, $message);
        }

        // Steuer-Kontrollrechnung (Migration 0057, portiert aus jobs/cron_sync_invoice.php):
        // die selbst aufsummierte Steuer aus allen Positionen + Versand gegen Shopifys
        // eigenes "total_tax" pruefen. Weicht das deutlich ab (z.B. weil ein falscher
        // Steuersatz zugeordnet wurde), wird die Rechnung NICHT angelegt, sondern der
        // Betreiber informiert -- lieber eine fehlende als eine falsche Rechnung.
        // "total_tax" fehlt nur, wenn diese Migration noch nicht installiert ist ODER
        // ein Datensatz vor der Installation gecacht wurde -- dann wird die Kontrolle
        // uebersprungen statt jede Rechnung zu blockieren.
        if (array_key_exists('total_tax', $orderRow)) {
            $shopifyTotalTax = (float) $orderRow['total_tax'];
            $taxDiff = abs($computedTotalTax - $shopifyTotalTax);
            if ($taxDiff > 0.10) {
                $message = sprintf(
                    'Steuer-Kontrolle fehlgeschlagen: berechnete Steuersumme %.2f EUR weicht von Shopifys Steuersumme %.2f EUR ab (Differenz %.2f EUR) -- Rechnung zur Sicherheit nicht angelegt.',
                    $computedTotalTax,
                    $shopifyTotalTax,
                    $taxDiff
                );
                $this->notifyError($shop, $shopRow, $message, $orderRow);
                ActivityLog::log($this->pdo, $shop, null, 'invoice', 'create', false, "Bestellung {$orderNumber}: {$message}", null, null, null, $syncRunId);

                return self::result(false, false, null, $message);
            }
        } else {
            $notes[] = 'Steuer-Kontrolle übersprungen (Feld "total_tax" nicht verfügbar).';
        }

        // Brutto-Kontrolle (Migration 0064): Summe aller Rechnungspositionen gegen
        // Shopifys Gesamtbetrag (currentTotalPriceSet). Die Steuer-Kontrolle oben
        // summiert Shopifys EIGENE Steuerbetraege und erkennt deshalb keine
        // falschen Preise -- live bei #1149: Bestellrabatt nicht abgezogen, 500
        // statt 425 EUR, Steuer-Kontrolle trotzdem bestanden. Trinkgeld/Zusatz-
        // gebuehren sind keine Rechnungspositionen und fuehren hier ebenfalls zum
        // Abbruch -- lieber eine fehlende als eine falsche Rechnung.
        if (isset($orderRow['total_price']) && is_numeric($orderRow['total_price'])) {
            $computedGross = 0.0;
            foreach ($items as $invoiceItem) {
                $computedGross += self::itemGrossTotal($invoiceItem);
            }
            $shopifyGross = (float) $orderRow['total_price'];
            $grossDiff = abs($computedGross - $shopifyGross);
            if ($grossDiff > 0.05) {
                $message = sprintf(
                    'Betrags-Kontrolle fehlgeschlagen: Summe der Rechnungspositionen %.2f EUR weicht von Shopifys Gesamtbetrag %.2f EUR ab (Differenz %.2f EUR, z.B. nicht erfasster Rabatt, Trinkgeld oder Zusatzgebühr) -- Rechnung zur Sicherheit nicht angelegt.',
                    $computedGross,
                    $shopifyGross,
                    $grossDiff
                );
                $this->notifyError($shop, $shopRow, $message, $orderRow);
                ActivityLog::log($this->pdo, $shop, null, 'invoice', 'create', false, "Bestellung {$orderNumber}: {$message}", null, null, null, $syncRunId);

                return self::result(false, false, null, $message);
            }
        } else {
            $notes[] = 'Betrags-Kontrolle übersprungen (Feld "total_price" nicht verfügbar).';
        }

        $invoiceData = array_filter([
            'items' => $items,
            'text' => $this->resolveHeaderText($shop, (int) $shopRow['target_connection_id'], $orderRow, 'Shopify ' . (string) ($orderRow['order_number'] ?? '')),
            'output_format' => 'pdf', // liefert die fertige Rechnung direkt in der Antwort mit, siehe Klassenkommentar
            'payment_method' => ((string) $shopRow['invoice_payment_method']) !== '' ? (string) $shopRow['invoice_payment_method'] : null,
            'cashier_id' => $shopRow['invoice_cashier_id'] !== null ? (int) $shopRow['invoice_cashier_id'] : null,
            'customer_id' => $customerId,
            // HelloCash v2: signature_mandatory (Standard false) -- die alte Welt schickte es immer mit
            'signature_mandatory' => ($shopRow['invoice_signature_mandatory'] ?? null) !== null && $shopRow['invoice_signature_mandatory'] !== false
                ? (int) $shopRow['invoice_signature_mandatory'] === 1 : null,
        ], static fn ($value) => $value !== null);

        try {
            // requireOperation() innerhalb von write() wirft (anders als ein fehlgeschlagener
            // HTTP-Aufruf, den write() selbst als WriteResult::failure() abfaengt), wenn das
            // Object/die Operation "invoice"/"create" im Connector Builder noch nicht angelegt
            // ist -- z.B. direkt nach dem Aktivieren, bevor die Einrichtung abgeschlossen ist.
            // Ohne dieses try/catch wuerde das bis in den Webhook-Handler durchschlagen und
            // dort auch andere, unabhaengige Mappings desselben Shops abbrechen.
            $writeResult = $targetConnector->write('invoice', 'create', $invoiceData);
        } catch (\Throwable $e) {
            $message = 'Rechnung konnte nicht angelegt werden: ' . $e->getMessage();
            $this->notifyError($shop, $shopRow, $message, $orderRow);
            ActivityLog::log($this->pdo, $shop, null, 'invoice', 'create', false, "Bestellung {$orderNumber}: {$message}", null, null, null, $syncRunId);

            return self::result(false, false, null, $message);
        }

        $invoiceNumber = $writeResult->responseData['data']['invoice_number'] ?? null;

        ActivityLog::log(
            $this->pdo,
            $shop,
            null,
            'invoice',
            'create',
            $writeResult->success,
            "Bestellung {$orderNumber}: " . $writeResult->message . ($notes !== [] ? ' | Hinweise: ' . implode(' ', $notes) : ''),
            $invoiceNumber !== null ? (string) $invoiceNumber : null,
            $writeResult->requestBody,
            $writeResult->responseBody,
            $syncRunId
        );

        if (!$writeResult->success) {
            $this->notifyError($shop, $shopRow, $writeResult->message, $orderRow);

            return self::result(false, false, null, $writeResult->message);
        }

        // Nach-Kontrolle: HelloCash rechnet Rabatte (discount_unit/discount_value,
        // siehe OrderPreparer::render()) selbst -- dessen tatsaechliche Rechnungssumme
        // gegen Shopifys Gesamtbetrag pruefen. Die Rechnung existiert dann bereits
        // (nicht rueckgaengig zu machen), deshalb nur Betreiber-Mail + eigener
        // Fehler-Eintrag im Protokoll (erscheint in admin/tax_monitoring.php).
        $helloCashGross = $writeResult->responseData['data']['gross_total'] ?? null;
        if (is_numeric($helloCashGross) && isset($orderRow['total_price']) && is_numeric($orderRow['total_price'])) {
            $postDiff = abs((float) $helloCashGross - (float) $orderRow['total_price']);
            if ($postDiff > 0.05) {
                $postMessage = sprintf(
                    'Nach-Kontrolle fehlgeschlagen: Rechnung%s wurde angelegt, Summe laut Zielsystem %.2f EUR weicht aber von Shopifys Gesamtbetrag %.2f EUR ab (Differenz %.2f EUR) -- bitte Rechnung prüfen und ggf. stornieren.',
                    $invoiceNumber !== null ? " Nr. {$invoiceNumber}" : '',
                    (float) $helloCashGross,
                    (float) $orderRow['total_price'],
                    $postDiff
                );
                $this->notifyError($shop, $shopRow, $postMessage, $orderRow);
                ActivityLog::log($this->pdo, $shop, null, 'invoice', 'create', false, "Bestellung {$orderNumber}: {$postMessage}", $invoiceNumber !== null ? (string) $invoiceNumber : null, null, null, $syncRunId);
                $notes[] = $postMessage;
            }
        }

        // Sets und Bundles (Connect\Sync\BundleService, Migration 0091): Einzelteile verkaufter Sets
        // im Kassensystem abbuchen -- die Rechnung fuehrt das Set als eigene Position ohne Artikel.
        foreach ((new BundleService($this->pdo))->deductComponentsForOrder($shop, $shopRow, $orderRow, $targetConnector, fn (ConnectorInterface $c, string $k): ?array => $this->findArticleMatchFields($c, $k), $orderNumber, $syncRunId) as $bundleNote) {
            $notes[] = $bundleNote;
        }

        $pdfBase64 = $writeResult->responseData['output']['pdf'] ?? null;
        if ((int) $shopRow['invoice_mail_enabled'] === 1) {
            if (is_string($pdfBase64) && $pdfBase64 !== '') {
                $this->sendInvoiceMail($shop, $shopRow, $orderRow, $invoiceNumber !== null ? (string) $invoiceNumber : '', (string) base64_decode($pdfBase64, true));
            } else {
                $notes[] = 'Mailversand übersprungen (keine PDF in der Antwort von HelloCash enthalten).';
            }
        }

        $message = 'Rechnung erstellt' . ($invoiceNumber !== null ? " (Nr. {$invoiceNumber})" : '') . ($customerId === null ? ', ohne Kundenzuordnung' : '') . '.';
        if ($notes !== []) {
            $message .= ' Hinweise: ' . implode(' ', $notes);
        }

        return self::result(true, false, $invoiceNumber !== null ? (string) $invoiceNumber : null, $message);
    }

    /**
     * Sondermodus "Keine Rechnung erstellen, nur Lagerbestand anpassen" (siehe
     * Aufrufstelle in createForOrder()) -- fuer Haendler, die anderswo bereits
     * eine Rechnung/einen Beleg erzeugen (z.B. eigenes Kassensystem) und oView
     * nur zur Lagerbestandsfuehrung nutzen wollen. Legt KEINE Rechnung an,
     * reduziert stattdessen je verkaufter Position den Lagerbestand im
     * Zielsystem -- ueber dieselbe Artikel-Ermittlung wie der normale
     * Positions-Abgleich (siehe findArticleMatchFields()), aber eine ANDERE
     * Operation als "invoice"/"create".
     *
     * Einrichtung im Connector Builder noetig (Beispiel HelloCash):
     *   - Object: dasselbe wie fuer den Positions-Abgleich (z.B. "Artikel",
     *     mit einer Vergleichswert-Rolle sku/barcode/title UND einem ID-Feld)
     *   - Neue Operation mit dem Schluessel "adjust_stock": HTTP-Methode POST,
     *     URL-Vorlage "articles/{{id}}/change-stock", KEIN body_template --
     *     die uebergebenen Felder (id/stock_change/...) werden dann direkt als
     *     JSON-Body gesendet (siehe GenericHttpConnector::buildWriteBody()).
     *     "id" landet dabei redundant auch im Body (von HelloCash ignoriert) --
     *     bewusst kein Sonderfall dafuer, siehe GenericHttpConnector::write().
     *
     * Ohne eingerichtete Operation: klarer Fehler statt stillem Nichtstun
     * (wie beim fehlenden "invoice"/"create" im normalen Modus).
     *
     * @param array<string, mixed> $shopRow
     * @param array<string, mixed> $orderRow
     * @return array{success: bool, skipped: bool, targetExternalId: ?string, message: string}
     */
    private function adjustStockForOrder(string $shop, array $shopRow, array $orderRow, ConnectorInterface $targetConnector, string $orderNumber, ?int $syncRunId): array
    {
        $comparisonKey = (string) $shopRow['comparison_key'];
        $lineMatchFieldKey = OrderPreparer::LINE_ITEM_MATCH_FIELDS[$comparisonKey] ?? null;
        $articleMatch = $lineMatchFieldKey !== null ? $this->findArticleMatchFields($targetConnector, $comparisonKey) : null;

        if ($articleMatch === null) {
            $message = "Lagerbestand konnte nicht angepasst werden: kein Ziel-Object mit der Vergleichswert-Rolle \"{$comparisonKey}\" und einem ID-Feld gefunden.";
            $this->notifyError($shop, $shopRow, $message, $orderRow);
            ActivityLog::log($this->pdo, $shop, null, 'invoice', 'adjust_stock', false, "Bestellung {$orderNumber}: {$message}", null, null, null, $syncRunId);

            return self::result(false, false, null, $message);
        }

        [$targetObject, $matchField, $identifierField] = $articleMatch;
        $objectCache = new ObjectCache($this->pdo);
        $bundleService = new BundleService($this->pdo);
        $bundles = $bundleService->activeFor($shop, (string) ($shopRow['stock_sync_direction'] ?? '')) ? $bundleService->definitions($shop) : [];

        $notes = [];
        $adjusted = 0;
        $failed = 0;
        $lastRequestBody = null;
        $lastResponseBody = null;

        foreach ((array) ($orderRow['line_items'] ?? []) as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }

            $quantity = (float) ($lineItem['line_quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $matchValue = trim((string) ($lineItem[$lineMatchFieldKey] ?? ''));
            if ($matchValue === '') {
                $notes[] = "Position ohne \"{$comparisonKey}\" übersprungen.";
                continue;
            }
            if (isset($bundles[$matchValue])) {
                continue; // Set: Einzelteile werden unten abgebucht
            }

            $cachedArticle = $objectCache->findOneByFieldValue((int) $shopRow['target_connection_id'], $targetObject->key, $matchField->key, $matchValue);
            if ($cachedArticle === null || !isset($cachedArticle[$identifierField->key])) {
                $notes[] = "Kein Artikel für \"{$matchValue}\" gefunden -- Lagerbestand nicht angepasst.";
                continue;
            }

            try {
                $writeResult = $targetConnector->write($targetObject->key, 'adjust_stock', [
                    'id' => (int) $cachedArticle[$identifierField->key],
                    'stock_change' => -$quantity,
                    'stock_invoice_number' => 0,
                    'stock_delivery_note_number' => 0,
                    'stock_description' => "Shopify Bestellung {$orderNumber}",
                ]);
            } catch (\Throwable $e) {
                $failed++;
                $notes[] = "Lagerbestand für \"{$matchValue}\" fehlgeschlagen: " . $e->getMessage();
                continue;
            }

            $lastRequestBody = $writeResult->requestBody;
            $lastResponseBody = $writeResult->responseBody;

            if (!$writeResult->success) {
                $failed++;
                $notes[] = "Lagerbestand für \"{$matchValue}\" fehlgeschlagen: " . $writeResult->message;
                continue;
            }

            $adjusted++;
        }

        foreach ($bundleService->deductComponentsForOrder($shop, $shopRow, $orderRow, $targetConnector, fn (ConnectorInterface $c, string $k): ?array => $this->findArticleMatchFields($c, $k), $orderNumber, $syncRunId) as $bundleNote) {
            $notes[] = $bundleNote;
        }

        $success = $failed === 0;
        $message = "{$adjusted} Position(en) im Lagerbestand angepasst, keine Rechnung erstellt (Modus \"Nur Lagerbestand anpassen\")."
            . ($notes !== [] ? ' Hinweise: ' . implode(' ', $notes) : '');

        ActivityLog::log($this->pdo, $shop, null, 'invoice', 'adjust_stock', $success, "Bestellung {$orderNumber}: {$message}", null, $lastRequestBody, $lastResponseBody, $syncRunId);

        if (!$success) {
            $this->notifyError($shop, $shopRow, $message, $orderRow);
        }

        return self::result($success, false, null, $message);
    }

    /**
     * Rechnungskopf-Feld "Text" (Referenz/Betreff der Rechnung): optional ueber eine
     * admin-gepflegte "Funktion" konfigurierbar (connect_functions, function_kind=
     * 'invoice_create', admin/functions.php), genau nach demselben Muster wie
     * function_kind='import_missing' in Connect\Sync\ProductImportService --
     * eine ganz normale, GLOBALE Mapping-Vorlage (Quelle Shopify "Bestellung" ->
     * Ziel HelloCash "Rechnung"), ohne Aktivierungsschritt je Shop.
     *
     * Nutzer-Entscheidung (2026-09-26): NUR der Rechnungskopf wird so abgebildet.
     * Positionen (Menge/Bezeichnung/Preis/Artikelabgleich) und Kundenzuordnung
     * bleiben bewusst fest im Code (siehe buildItems()/resolveCustomer() und
     * Klassenkommentar) -- das sind mehrstufige/array-basierte Vorgaenge, keine
     * einfachen Feld-zu-Feld-Zuordnungen.
     *
     * Ohne konfigurierte Funktion, ohne Vorlage, ohne Feld-Zuordnung auf das
     * Zielfeld "text", oder bei leerem Ergebnis: $fallback (bisheriges, fest
     * verdrahtetes Verhalten) -- diese Konfiguration ist also rein optional,
     * die Rechnungserstellung funktioniert unveraendert auch ohne sie.
     */
    private function resolveHeaderText(string $shop, int $targetConnectionId, array $orderRow, string $fallback): string
    {
        $shopifyConnectionId = $this->resolveShopifyConnectionId($shop);
        if ($shopifyConnectionId === null) {
            return $fallback;
        }

        $sourceObjectId = $this->resolveObjectId($shopifyConnectionId, 'order');
        $targetObjectId = $this->resolveObjectId($targetConnectionId, 'invoice');
        if ($sourceObjectId === null || $targetObjectId === null) {
            return $fallback;
        }

        $templateId = $this->resolveInvoiceMappingTemplateId($sourceObjectId, $targetObjectId);
        if ($templateId === null) {
            return $fallback;
        }

        $fieldMappingsStmt = $this->pdo->prepare('SELECT * FROM connect_field_mappings WHERE mapping_template_id = :mapping_template_id ORDER BY sort_order, id');
        $fieldMappingsStmt->execute(['mapping_template_id' => $templateId]);
        $fieldMappingRows = $fieldMappingsStmt->fetchAll();
        if (count($fieldMappingRows) === 0) {
            return $fallback;
        }

        $sourceFieldsById = $this->connectorFactory->fieldsByIdForObject($sourceObjectId);
        $targetFieldsById = $this->connectorFactory->fieldsByIdForObject($targetObjectId);

        $translated = (new MappingEngine($this->pdo))->translate($orderRow, $fieldMappingRows, $sourceFieldsById, $targetFieldsById);
        $text = trim((string) ($translated['text'] ?? ''));

        return $text !== '' ? $text : $fallback;
    }

    private function resolveShopifyConnectionId(string $shop): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id FROM connect_connections c
             JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
        );
        $stmt->execute(['shop' => $shop]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private function resolveObjectId(int $connectionId, string $objectKey): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id FROM connect_objects o
             JOIN connect_connections c ON c.connector_type_id = o.connector_type_id
             WHERE c.id = :connection_id AND o.object_key = :object_key'
        );
        $stmt->execute(['connection_id' => $connectionId, 'object_key' => $objectKey]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * Siehe Connect\Sync\ProductImportService::resolveMappingTemplateId() --
     * gleiches Prinzip, andere function_kind ('invoice_create' statt
     * 'import_missing'), bewusst nicht geteilt (kleine, in sich geschlossene
     * Methode, keine gemeinsame Basisklasse fuer zwei Aufrufstellen noetig).
     */
    private function resolveInvoiceMappingTemplateId(int $sourceObjectId, int $targetObjectId): ?int
    {
        $fnStmt = $this->pdo->prepare(
            "SELECT f.mapping_template_id FROM connect_functions f
             JOIN connect_mapping_templates t ON t.id = f.mapping_template_id
             WHERE f.function_kind = 'invoice_create'
               AND t.source_object_id = :source_object_id AND t.target_object_id = :target_object_id
             LIMIT 1"
        );
        $fnStmt->execute(['source_object_id' => $sourceObjectId, 'target_object_id' => $targetObjectId]);
        $pinned = $fnStmt->fetchColumn();

        return $pinned !== false ? (int) $pinned : null;
    }

    /**
     * Sucht zuerst per E-Mail, dann per Vor-+Nachname nach einem bestehenden Kunden
     * (GET /customers?search=..., live -- bewusst nicht gecacht, siehe Klassenkommentar:
     * die Suche muss den aktuellen Stand sehen, nicht einen evtl. veralteten Cache).
     * Kein Treffer -> neuer Kunde, aber nur wenn HelloCash das Land akzeptiert (dort fuer
     * NEUE Kunden Pflichtfeld mit fester Werteliste) -- sonst wird die Rechnung spaeter
     * ohne Kundenzuordnung angelegt (customer_id ist bei HelloCash optional).
     *
     * $emailOnly (Einstellung "Nur E-Mail-Adresse"): keine Suche per Name -- gleichnamige Kunden
     * werden sonst verwechselt. Bestellung ohne E-Mail = kein Kunde (keine Neuanlage nur mit
     * Namen, sonst entstuende bei jeder solchen Bestellung ein weiterer Kunde).
     *
     * @param array<string, mixed> $orderRow
     * @param list<string> $notes
     */
    private function resolveCustomer(ConnectorInterface $targetConnector, array $orderRow, array &$notes, bool $emailOnly = false): ?int
    {
        $email = trim((string) ($orderRow['email'] ?? ''));
        $firstName = trim((string) ($orderRow['customer_first_name'] ?? ''));
        $lastName = trim((string) ($orderRow['customer_last_name'] ?? ''));

        if ($emailOnly && $email === '') {
            $notes[] = 'Kein Kunde zugeordnet (Bestellung ohne E-Mail-Adresse, Zuordnung nur per E-Mail eingestellt).';

            return null;
        }

        if ($email !== '') {
            foreach ($targetConnector->read('customer', ['search' => rawurlencode($email)]) as $row) {
                if (isset($row['id'], $row['email']) && strcasecmp((string) $row['email'], $email) === 0) {
                    return (int) $row['id'];
                }
            }
        }

        if (!$emailOnly && $firstName !== '' && $lastName !== '') {
            foreach ($targetConnector->read('customer', ['search' => rawurlencode($firstName . ' ' . $lastName)]) as $row) {
                if (isset($row['id'])
                    && strcasecmp(trim((string) ($row['first_name'] ?? '')), $firstName) === 0
                    && strcasecmp(trim((string) ($row['last_name'] ?? '')), $lastName) === 0
                ) {
                    return (int) $row['id'];
                }
            }
        }

        if ($email === '' && $firstName === '' && $lastName === '') {
            $notes[] = 'Kein Kunde zugeordnet (Bestellung ohne Name und E-Mail).';

            return null;
        }

        $countryCode = self::mapCountryCode((string) ($orderRow['billing_country'] ?? $orderRow['shipping_country'] ?? ''));
        if ($countryCode === null) {
            $notes[] = 'Kein Kunde angelegt (Land "' . ($orderRow['billing_country'] ?? $orderRow['shipping_country'] ?? '?') . '" wird von HelloCash für neue Kunden nicht unterstützt).';

            return null;
        }

        $created = $targetConnector->write('customer', 'create', array_filter([
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'email' => $email !== '' ? $email : null,
            'phone_number' => trim((string) ($orderRow['phone'] ?? '')) ?: null,
            'street' => trim((string) ($orderRow['billing_address1'] ?? $orderRow['shipping_address1'] ?? '')) ?: null,
            'postal_code' => trim((string) ($orderRow['billing_zip'] ?? $orderRow['shipping_zip'] ?? '')) ?: null,
            'city' => trim((string) ($orderRow['billing_city'] ?? $orderRow['shipping_city'] ?? '')) ?: null,
            'country_code' => $countryCode,
        ], static fn ($value) => $value !== null));

        if (!$created->success) {
            $notes[] = 'Kunde konnte nicht angelegt werden: ' . $created->message;

            return null;
        }

        return isset($created->responseData['id']) ? (int) $created->responseData['id'] : null;
    }

    private static function mapCountryCode(string $isoAlpha2): ?string
    {
        $lower = strtolower(trim($isoAlpha2));

        return in_array($lower, self::HELLOCASH_COUNTRIES, true) ? $lower : null;
    }

    /**
     * Positionen fuer die HelloCash-Rechnung: die Shopify-Aufbereitung (Rabatte,
     * Steuern, Versand) steckt in Connect\Sync\Document\OrderPreparer, hier wird
     * nur noch ins HelloCash-Format (items[]) uebertragen -- mit dem bisher fest
     * verdrahteten HelloCash-Belegprofil (DocumentProfile::helloCashDefaults()).
     *
     * @param array<string, mixed> $orderRow
     * @param list<string> $notes
     * @param float $computedTotalTax Akkumulator fuer die Steuer-Kontrollrechnung
     *        (siehe createForOrder()) -- Summe von Shopifys eigenen Steuerbetraegen
     *        je Position und Versand.
     * @param bool $pricesIncludeTax connect_shops.shopify_prices_include_tax (NULL gilt als true)
     * @return list<array<string, mixed>>
     */
    private function buildItems(int $targetConnectionId, ConnectorInterface $targetConnector, array $orderRow, string $comparisonKey, array &$notes, float &$computedTotalTax, bool $pricesIncludeTax): array
    {
        $articleResolver = null;
        if (isset(OrderPreparer::LINE_ITEM_MATCH_FIELDS[$comparisonKey])) {
            $articleMatch = $this->findArticleMatchFields($targetConnector, $comparisonKey);
            if ($articleMatch === null) {
                $notes[] = "Positions-Abgleich übersprungen (kein Ziel-Object mit der Vergleichswert-Rolle \"{$comparisonKey}\" und einem ID-Feld gefunden).";
            } else {
                [$targetObject, $matchField, $identifierField] = $articleMatch;
                $objectCache = new ObjectCache($this->pdo);
                $articleResolver = static function (string $matchValue) use ($objectCache, $targetConnectionId, $targetObject, $matchField, $identifierField): ?string {
                    $cachedArticle = $objectCache->findOneByFieldValue($targetConnectionId, $targetObject->key, $matchField->key, $matchValue);

                    return $cachedArticle !== null && isset($cachedArticle[$identifierField->key]) ? (string) $cachedArticle[$identifierField->key] : null;
                };
            }
        } else {
            $notes[] = "Positions-Abgleich übersprungen (unbekannter Vergleichswert \"{$comparisonKey}\").";
        }

        $preparer = new OrderPreparer();
        $prepared = $preparer->prepare($orderRow, $pricesIncludeTax, $comparisonKey, $articleResolver);
        $computedTotalTax += $prepared['computed_total_tax'];
        foreach ($prepared['notes'] as $note) {
            $notes[] = $note;
        }

        $items = [];
        foreach ($preparer->render($prepared['lines'], DocumentProfile::helloCashDefaults()) as $line) {
            $item = [
                'name' => mb_substr((string) $line['name'], 0, 100),
                'quantity' => $line['out_quantity'],
                'price' => $line['out_price'],
                'tax_rate' => $line['tax_rate_percent'],
            ];
            if ($line['target_article_id'] !== null) {
                $item['article_id'] = (int) $line['target_article_id'];
                $item['type'] = 'article';
            }
            if ($line['out_discount_unit'] !== null) {
                $item['discount_unit'] = $line['out_discount_unit'];
                $item['discount_value'] = $line['out_discount_value'];
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Rechnungsbetrag einer HelloCash-Position NACH Rabatt, so wie HelloCash ihn
     * bildet (Betragsrabatt gilt fuer die ganze Position) -- fuer die Betrags-
     * Kontrolle in createForOrder().
     *
     * @param array<string, mixed> $item
     */
    private static function itemGrossTotal(array $item): float
    {
        $total = (float) $item['price'] * (float) $item['quantity'];

        return match ($item['discount_unit'] ?? null) {
            'percent' => round($total * (1 - ((float) $item['discount_value']) / 100), 2),
            'absolute' => round($total - (float) $item['discount_value'], 2),
            default => round($total, 2),
        };
    }

    /**
     * Sucht unter den Objects der Ziel-Connection eines mit sowohl einem Feld der
     * jeweiligen Vergleichswert-Rolle (sku/barcode/title) als auch einem ID-Feld
     * (is_identifier) -- beides im Connector Builder gesetzt, wie schon fuer den
     * Lagerbestand-Abgleich (Migration 0027).
     *
     * @return array{0: \Connect\Connector\ObjectDefinition, 1: \Connect\Connector\FieldDefinition, 2: \Connect\Connector\FieldDefinition}|null
     */
    public function findArticleMatchFields(ConnectorInterface $targetConnector, string $comparisonKey): ?array
    {
        foreach ($targetConnector->getObjects() as $object) {
            $matchField = ProductMatchContext::fieldWithComparisonRole($object, $comparisonKey);
            $identifierField = null;
            foreach ($object->fields as $field) {
                if ($field->isIdentifier) {
                    $identifierField = $field;
                    break;
                }
            }
            if ($matchField !== null && $identifierField !== null) {
                return [$object, $matchField, $identifierField];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $shopRow
     * @param array<string, mixed> $orderRow
     */
    public function sendInvoiceMail(string $shop, array $shopRow, array $orderRow, string $invoiceNumber, string $pdfContent): void
    {
        if ($pdfContent === '') {
            return;
        }

        $recipient = trim((string) ($orderRow['email'] ?? ''));
        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $config = Config::load();

        // %DATUM% = Bestelldatum (TT.MM.JJJJ) -- in der alten Welt nie ersetzt, obwohl Shops es nutzen
        $orderDate = '';
        if (!empty($orderRow['created_at'])) {
            try {
                $orderDate = (new \DateTimeImmutable((string) $orderRow['created_at']))->setTimezone(new \DateTimeZone('Europe/Vienna'))->format('d.m.Y');
            } catch (\Throwable) {
                $orderDate = '';
            }
        }
        $placeholders = ['%VORNAME%', '%NACHNAME%', '%BESTELLUNG%', '%DATUM%'];
        $values = [
            (string) ($orderRow['customer_first_name'] ?? ''),
            (string) ($orderRow['customer_last_name'] ?? ''),
            (string) ($orderRow['order_number'] ?? ''),
            $orderDate,
        ];

        $subject = str_replace($placeholders, $values, ((string) ($shopRow['invoice_mail_subject'] ?? '')) ?: 'Ihre Rechnung zu Bestellung %BESTELLUNG%');
        $message = str_replace($placeholders, $values, ((string) ($shopRow['invoice_mail_message'] ?? '')) ?: 'Vielen Dank für Ihre Bestellung. Die Rechnung finden Sie im Anhang.');

        $smtpPasswordEncrypted = (string) ($shopRow['invoice_mail_smtp_password'] ?? '');
        $smtpPassword = null;
        if ($smtpPasswordEncrypted !== '') {
            try {
                $smtpPassword = (new Crypto($config->require('CONNECT_ENCRYPTION_KEY')))->decrypt($smtpPasswordEncrypted);
            } catch (\Throwable $e) {
                $smtpPassword = null; // z.B. Schluessel gewechselt -- dann faellt InvoiceMailer auf den System-Versand zurueck
            }
        }

        // Eigenes SMTP nur mit Abo + oder ++ (wie alte Welt), sonst System-Postfach
        if (!\Connect\Billing\Plans::allowsOwnSmtp(\Connect\Billing\Plans::forShop($this->pdo, $shop))) {
            $shopRow['invoice_mail_smtp_host'] = null;
            $smtpPassword = null;
        }

        $result = (new InvoiceMailer())->send(
            [
                'smtp_host' => $shopRow['invoice_mail_smtp_host'],
                'smtp_port' => $shopRow['invoice_mail_smtp_port'] !== null ? (int) $shopRow['invoice_mail_smtp_port'] : null,
                'smtp_username' => $shopRow['invoice_mail_smtp_username'],
                'smtp_password' => $smtpPassword,
                'smtp_encryption' => $shopRow['invoice_mail_smtp_encryption'],
            ],
            self::systemMailFrom($config),
            ((string) ($shopRow['invoice_mail_sender_name'] ?? '')) ?: 'oView Connect',
            (string) ($shopRow['invoice_mail_sender_address'] ?? ''),
            $recipient,
            $subject,
            $message,
            $pdfContent,
            'Rechnung_' . ($invoiceNumber !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceNumber) : 'oView') . '.pdf',
            // Weitere Anhaenge des Merchants (z.B. Widerrufsbelehrung), je Abo freigegeben -- Migration 0089
            (new \Connect\Mail\ShopAttachments($this->pdo))->forMail($shop)
        );

        ActivityLog::log($this->pdo, $shop, null, 'invoice', 'send_mail', $result['sent'], $result['note'], $invoiceNumber !== '' ? $invoiceNumber : null);
    }

    /**
     * @param array<string, mixed> $shopRow
     * @param array<string, mixed> $orderRow
     */
    public function notifyError(string $shop, array $shopRow, string $reason, array $orderRow): void
    {
        // Empfaenger aus "Benachrichtigungen" (Merchant bzw. Shopify-E-Mail), Kopie an den Admin
        $notifier = new \Connect\Notify\Notifier($this->pdo, Config::load());
        $orderNumber = (string) ($orderRow['order_number'] ?? '?');
        $subject = "oView: Rechnung für Bestellung {$orderNumber} wurde nicht erstellt";
        $body = "Hallo,\n\n"
            . "für die Bestellung {$orderNumber} in deinem Shop {$shop} konnte oView keine Rechnung anlegen.\n\n"
            . "Grund: {$reason}\n\n"
            . "Bitte prüfe die Bestellung und lege den Beleg bei Bedarf selbst an. Details siehst du in oView unter „Rechnungen“:\n"
            . $notifier->appUrl($shop) . "\n\n"
            . "Diese Meldungen kannst du in oView unter Einstellungen → Benachrichtigungen anpassen.\n";

        $notifier->sendProblem($shop, $subject, $body);
    }

    private static function systemMailFrom(Config $config): string
    {
        $configured = $config->optional('CONNECT_SYSTEM_MAIL_FROM', '');
        if ($configured !== '') {
            return $configured;
        }

        $host = parse_url($config->optional('CONNECT_APP_URL', 'https://connect.oview.at'), PHP_URL_HOST);

        return 'no-reply@' . (is_string($host) && $host !== '' ? $host : 'connect.oview.at');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadShop(string $shop): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT target_connection_id, comparison_key, stock_sync_direction, invoice_cashier_id, invoice_payment_method,
                    invoice_pos_only, invoice_suppress_and_adjust_stock, shopify_prices_include_tax,
                    invoice_mail_enabled, invoice_mail_sender_name, invoice_mail_sender_address,
                    invoice_mail_subject, invoice_mail_message, invoice_mail_smtp_host, invoice_mail_smtp_port,
                    invoice_mail_smtp_username, invoice_mail_smtp_password, invoice_mail_smtp_encryption,
                    invoice_error_notification_email
             FROM connect_shops WHERE shop = :shop'
        );
        $stmt->execute(['shop' => $shop]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        // Signaturpflicht (Migration 0072) getrennt und abgesichert gelesen: fehlt die Spalte
        // noch, bleibt es beim bisherigen Verhalten (Feld wird nicht gesendet).
        try {
            $sig = $this->pdo->prepare('SELECT invoice_signature_mandatory FROM connect_shops WHERE shop = :shop');
            $sig->execute(['shop' => $shop]);
            $row['invoice_signature_mandatory'] = $sig->fetchColumn();
        } catch (\Throwable) {
            $row['invoice_signature_mandatory'] = null;
        }

        // Kundenzuordnung (Migration 0095) ebenso -- ohne Spalte bisheriges Verhalten
        try {
            $match = $this->pdo->prepare('SELECT invoice_customer_match FROM connect_shops WHERE shop = :shop');
            $match->execute(['shop' => $shop]);
            $row['invoice_customer_match'] = (string) $match->fetchColumn() === self::CUSTOMER_MATCH_EMAIL ? self::CUSTOMER_MATCH_EMAIL : self::CUSTOMER_MATCH_EMAIL_NAME;
        } catch (\Throwable) {
            $row['invoice_customer_match'] = self::CUSTOMER_MATCH_EMAIL_NAME;
        }

        return $row;
    }

    /**
     * @return array{success: bool, skipped: bool, targetExternalId: ?string, message: string}
     */
    private static function result(bool $success, bool $skipped, ?string $targetExternalId, string $message): array
    {
        return ['success' => $success, 'skipped' => $skipped, 'targetExternalId' => $targetExternalId, 'message' => $message];
    }
}
