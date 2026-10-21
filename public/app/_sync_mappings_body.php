<?php

declare(strict_types=1);

/**
 * connect/public/app/_sync_mappings_body.php
 *
 * Admin ($isAdmin = true): unveraendert die volle technische Sync-Mapping-
 * Verwaltung (siehe Connect\Sync\SyncRunner, connect/bin/scheduler.php) zum
 * Debuggen -- Mappings anlegen/aktivieren/loeschen/manuell ausfuehren.
 *
 * Merchant ($isAdmin = false): KEINE Job-Verwaltung mehr (zu technisch,
 * siehe Diskussion) -- stattdessen nur eine Status-Anzeige der system-
 * managed Shopify-Synchronisierung (automatisch angelegt bei der
 * Migrationsbruecke, admin/shops.php) plus drei einfache Einstellungs-
 * Formulare (Vergleichswert, Lagerbestand-Richtung/-Standort, Rechnungen/
 * Aufträge-Toggle), die in connect_shops gespeichert werden. Diese Seite
 * SPEICHERT die Einstellungen nur -- die tatsaechliche Ausfuehrung (Lager-
 * bestand ins Zielsystem schreiben, Auftraege automatisch anlegen) ist
 * bewusst noch nicht Teil dieses Bausteins (Mapping Engine, Phase 3).
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Shopify\TokenRefresher;
use Connect\Sync\SyncRunner;

$isAdmin = $isAdmin ?? false;

$pdo = $app->pdo;
$shop = $app->shop;

$flashMessage = null;
$flashType = 'info';

$buildFactory = function () use ($pdo, $app): ConnectorFactory {
    $tokenRefresher = TokenRefresher::fromConfig($app->config);

    return new ConnectorFactory($pdo, $app->crypto, new HttpClient(), $tokenRefresher);
};

// -----------------------------------------------------------------------
// POST: neues Sync Mapping anlegen -- nur Admin. Merchants legen keine
// eigenen Sync Mappings mehr an (siehe Merchant-Zweig weiter unten), die
// feste Shopify-Synchronisierung entsteht automatisch bei der
// Migrationsbruecke (admin/shops.php).
// -----------------------------------------------------------------------
// Job-Vorlagen werden zentral unter admin/sync_job_templates.php gepflegt
// (welche Objects ueberhaupt regelmaessig synchronisiert werden koennen,
// mit welchem Standard-Intervall) -- diese Seite legt nur noch die
// "Aktivierung" fuer DIESEN Shop an: welche Vorlage, mit welcher Connection,
// welches (ggf. abweichende) Intervall. Gleiches Muster wie bei den
// Mapping-Vorlagen (siehe admin/mappings.php).
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_mapping') {
    $syncJobTemplateId = (int) ($_POST['sync_job_template_id'] ?? 0);
    $sourceConnectionId = (int) ($_POST['source_connection_id'] ?? 0);
    $intervalMinutes = (int) ($_POST['schedule_interval_minutes'] ?? 0);
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    $templateStmt = $pdo->prepare('SELECT * FROM connect_sync_job_templates WHERE id = :id');
    $templateStmt->execute(['id' => $syncJobTemplateId]);
    $template = $templateStmt->fetch();

    if ($template === false) {
        $flashMessage = 'Bitte eine Job-Vorlage auswählen.';
        $flashType = 'danger';
    } elseif ($sourceConnectionId <= 0) {
        $flashMessage = 'Bitte eine Connection auswählen.';
        $flashType = 'danger';
    } elseif ($intervalMinutes <= 0) {
        $flashMessage = 'Bitte ein Intervall in Minuten (größer 0) angeben, z. B. 60 für stündlich.';
        $flashType = 'danger';
    } else {
        // Nur Connections des eigenen Shops, die zum in der Vorlage
        // erwarteten Connector Type passen.
        $ownershipCheck = $pdo->prepare(
            'SELECT 1 FROM connect_connections WHERE id = :id AND shop = :shop AND connector_type_id = :type_id'
        );
        $ownershipCheck->execute(['id' => $sourceConnectionId, 'shop' => $shop, 'type_id' => $template['connector_type_id']]);

        if ($ownershipCheck->fetchColumn() === false) {
            $flashMessage = 'Connection nicht gefunden oder passt nicht zum in der Vorlage erwarteten System.';
            $flashType = 'danger';
        } else {
            // managed_by = 'system': jeder Sync-Job wird ausschliesslich hier im
            // Admin angelegt/aktiviert, Merchants haben keine eigene Anlage-
            // Moeglichkeit mehr -- ohne das faellt die Spalte auf ihren
            // Default 'merchant' zurueck, und der Job waere in der Merchant-
            // Ansicht ("Automatische Abgleiche"-Karte, filtert auf managed_by='system')
            // unsichtbar, obwohl er inhaltlich genau dorthin gehoert.
            $insert = $pdo->prepare(
                'INSERT INTO connect_sync_mappings
                    (shop, name, source_connection_id, source_object_id, sync_job_template_id, trigger_type, schedule_interval_minutes, enabled, managed_by)
                 VALUES (:shop, :name, :source_connection_id, :source_object_id, :sync_job_template_id, \'schedule\', :interval_minutes, :enabled, \'system\')'
            );
            $insert->execute([
                'shop' => $shop,
                'name' => $template['name'],
                'source_connection_id' => $sourceConnectionId,
                'source_object_id' => $template['object_id'],
                'sync_job_template_id' => $syncJobTemplateId,
                'interval_minutes' => $intervalMinutes,
                'enabled' => $enabled,
            ]);

            $flashMessage = "Sync-Job \"{$template['name']}\" angelegt.";
            $flashType = 'success';
        }
    }
}

// -----------------------------------------------------------------------
// POST: Aktiv/Inaktiv umschalten -- nur Admin (system-managed Mappings sind
// fuer Merchants nur lesbar).
// -----------------------------------------------------------------------
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_mapping') {
    $mappingId = (int) ($_POST['mapping_id'] ?? 0);

    $ownershipCheck = $pdo->prepare('SELECT enabled, name, settings FROM connect_sync_mappings WHERE id = :id AND shop = :shop');
    $ownershipCheck->execute(['id' => $mappingId, 'shop' => $shop]);
    $mapping = $ownershipCheck->fetch();

    if ($mapping === false) {
        $flashMessage = 'Sync Mapping nicht gefunden.';
        $flashType = 'danger';
    } else {
        $newEnabled = ((int) $mapping['enabled']) === 1 ? 0 : 1;
        // Beim Einschalten Nachlauf-Start auf JETZT (settings.backfill_since), siehe admin/mappings.php
        $settings = $mapping['settings'] !== null ? (json_decode((string) $mapping['settings'], true) ?: []) : [];
        if ($newEnabled === 1) {
            $settings['backfill_since'] = time();
        }
        $pdo->prepare('UPDATE connect_sync_mappings SET enabled = :enabled, settings = :settings, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['enabled' => $newEnabled, 'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'id' => $mappingId]);

        $flashMessage = "\"{$mapping['name']}\" ist jetzt " . ($newEnabled === 1 ? 'aktiv' : 'inaktiv') . '.';
        $flashType = 'success';
    }
}

// -----------------------------------------------------------------------
// POST: Sync Mapping löschen -- nur Admin.
// -----------------------------------------------------------------------
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_mapping') {
    $mappingId = (int) ($_POST['mapping_id'] ?? 0);

    $pdo->prepare('DELETE FROM connect_sync_mappings WHERE id = :id AND shop = :shop')
        ->execute(['id' => $mappingId, 'shop' => $shop]);

    $flashMessage = 'Sync Mapping gelöscht.';
    $flashType = 'success';
}

// -----------------------------------------------------------------------
// POST: jetzt manuell ausführen (ohne auf den Scheduler zu warten) --
// nuetzlich zum Testen, laeuft synchron im Request wie "Vorschau
// aktualisieren", aber unbegrenzt (kein PREVIEW_LIMIT) genau wie der
// Scheduler es taete. Nur Admin -- Merchants sehen den Status nur lesend.
// -----------------------------------------------------------------------
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_now') {
    $mappingId = (int) ($_POST['mapping_id'] ?? 0);

    $mappingStmt = $pdo->prepare('SELECT * FROM connect_sync_mappings WHERE id = :id AND shop = :shop');
    $mappingStmt->execute(['id' => $mappingId, 'shop' => $shop]);
    $mapping = $mappingStmt->fetch();

    if ($mapping === false) {
        $flashMessage = 'Sync Mapping nicht gefunden.';
        $flashType = 'danger';
    } else {
        try {
            $runner = new SyncRunner($pdo, $buildFactory());
            $runner->run($mapping);
            $flashMessage = "\"{$mapping['name']}\" wurde synchronisiert.";
            $flashType = 'success';
        } catch (\Throwable $e) {
            $flashMessage = "Synchronisierung von \"{$mapping['name']}\" fehlgeschlagen: " . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

// -----------------------------------------------------------------------
// POST: Merchant-Einstellungen speichern (Vergleichswert, Lagerbestand,
// Rechnungen/Aufträge) -- ersetzt das frühere "Sync Mapping anlegen" fürs
// Zielsystem. Werden nur GESPEICHERT, die tatsächliche Ausführung (Lager-
// bestand ins Zielsystem schreiben, Aufträge automatisch anlegen) ist noch
// nicht Teil dieses Bausteins (siehe Datei-Kopfkommentar).
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_comparison_key') {
    $comparisonKey = (string) ($_POST['comparison_key'] ?? '');

    if (!in_array($comparisonKey, ['title', 'sku', 'barcode'], true)) {
        $flashMessage = 'Bitte einen gültigen Vergleichswert wählen.';
        $flashType = 'danger';
    } else {
        $pdo->prepare('UPDATE connect_shops SET comparison_key = :comparison_key WHERE shop = :shop')
            ->execute(['comparison_key' => $comparisonKey, 'shop' => $shop]);

        $flashMessage = 'Vergleichswert gespeichert.';
        $flashType = 'success';
    }
}

// "Varianten-Vergleichswert": nur relevant fuer den Produkt-Import, wenn ein
// Datensatz eines varianten-losen Systems (z.B. HelloCash) als neue Variante
// eines bestehenden Shopify-Produkts erkannt werden soll (siehe
// Connect\Sync\ProductImportService, Migration 0034) -- muss vom aktiven
// Vergleichswert oben abweichen (z.B. Vergleichswert=Barcode,
// Varianten-Vergleichswert=SKU: gleiche SKU = gleiche Produktgruppe). Leer
// = Varianten-Import deaktiviert (Default).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_variant_comparison_key') {
    $variantComparisonKey = trim((string) ($_POST['variant_comparison_key'] ?? ''));

    $currentComparisonKeyStmt = $pdo->prepare('SELECT comparison_key FROM connect_shops WHERE shop = :shop');
    $currentComparisonKeyStmt->execute(['shop' => $shop]);
    $currentComparisonKey = (string) $currentComparisonKeyStmt->fetchColumn();

    if ($variantComparisonKey !== '' && !in_array($variantComparisonKey, ['title', 'sku', 'barcode'], true)) {
        $flashMessage = 'Bitte einen gültigen Varianten-Vergleichswert wählen.';
        $flashType = 'danger';
    } elseif ($variantComparisonKey !== '' && $variantComparisonKey === $currentComparisonKey) {
        $flashMessage = 'Der Varianten-Vergleichswert muss sich vom Vergleichswert oben unterscheiden.';
        $flashType = 'danger';
    } else {
        $pdo->prepare('UPDATE connect_shops SET variant_comparison_key = :variant_comparison_key WHERE shop = :shop')
            ->execute(['variant_comparison_key' => $variantComparisonKey !== '' ? $variantComparisonKey : null, 'shop' => $shop]);

        $flashMessage = 'Varianten-Vergleichswert gespeichert.';
        $flashType = 'success';
    }
}

// Nur relevant, wenn beim Import ein Produkt als neue Variante eines
// bestehenden Shopify-Produkts angelegt wird (siehe
// Connect\Sync\ProductImportService, Migration 0031) -- Name der Shopify-
// Produktoption (z.B. "Größe"), unter der die neue Variante erscheint.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_variant_option_name') {
    $variantOptionName = trim((string) ($_POST['variant_option_name'] ?? ''));

    if ($variantOptionName === '') {
        $flashMessage = 'Bitte einen Namen für die Variantenoption angeben.';
        $flashType = 'danger';
    } else {
        $pdo->prepare('UPDATE connect_shops SET variant_option_name = :variant_option_name WHERE shop = :shop')
            ->execute(['variant_option_name' => $variantOptionName, 'shop' => $shop]);

        $flashMessage = 'Variantenoption gespeichert.';
        $flashType = 'success';
    }
}

// Ob Shopifys Preis-Feld Netto oder Brutto (inkl. MwSt.) ist -- gebraucht von
// Connect\Mapping\MappingEngine (transform_type='derived_price', siehe
// Mapping-Vorlagen "Felder bearbeiten") um aus dem einen Shopify-Preis +
// einem Steuersatz BEIDE Preise fuers Zielsystem zu berechnen (z.B. HelloCash
// net_selling_price/gross_selling_price). Persistiert statt live bei jedem
// Import abgefragt -- robuster, und ueberschreibbar, falls die Shopify-
// Einstellung nicht der tatsaechlichen Preispflege entspricht.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_price_tax_mode') {
    $mode = (string) ($_POST['shopify_prices_include_tax'] ?? '');

    if (!in_array($mode, ['0', '1'], true)) {
        $flashMessage = 'Bitte Netto oder Brutto wählen.';
        $flashType = 'danger';
    } else {
        $pdo->prepare('UPDATE connect_shops SET shopify_prices_include_tax = :mode WHERE shop = :shop')
            ->execute(['mode' => (int) $mode, 'shop' => $shop]);

        $flashMessage = 'Preis-Einstellung gespeichert.';
        $flashType = 'success';
    }
}

// Vorbelegung/Uebernahme direkt aus Shopifys eigener Shop-Einstellung
// (taxesIncluded, siehe Migration 0007 "shop"-Object) -- ersetzt NICHT die
// Moeglichkeit, es oben manuell zu setzen (falls Shopifys Angabe die
// tatsaechliche Preispflege nicht korrekt widerspiegelt), sondern ist nur
// eine Komfort-Vorbelegung per Knopfdruck.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_price_tax_mode_from_shopify') {
    $shopifyConnectionStmt = $pdo->prepare(
        "SELECT c.id FROM connect_connections c
         JOIN connect_connector_types t ON t.id = c.connector_type_id
         WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
    );
    $shopifyConnectionStmt->execute(['shop' => $shop]);
    $shopifyConnectionId = $shopifyConnectionStmt->fetchColumn();

    if ($shopifyConnectionId === false) {
        $flashMessage = 'Keine Shopify-Connection für diesen Shop gefunden.';
        $flashType = 'danger';
    } else {
        try {
            $shopifyConnector = $buildFactory()->forConnection((int) $shopifyConnectionId);
            $shopRows = iterator_to_array($shopifyConnector->read('shop'));
            $taxesIncluded = $shopRows[0]['taxes_included'] ?? null;
            $shopCountry = $shopRows[0]['country'] ?? null;

            if ($taxesIncluded === null) {
                $flashMessage = 'Shopify hat keine Antwort für "taxesIncluded" geliefert -- bitte manuell wählen.';
                $flashType = 'danger';
            } else {
                $mode = ((bool) $taxesIncluded) ? 1 : 0;
                $pdo->prepare('UPDATE connect_shops SET shopify_prices_include_tax = :mode WHERE shop = :shop')
                    ->execute(['mode' => $mode, 'shop' => $shop]);

                $flashMessage = 'Aus Shopify übernommen: ' . ($mode === 1 ? 'Brutto (inkl. MwSt.)' : 'Netto') . '.';
                $flashType = 'success';

                // Standard-Steuersatz (Migration 0047/0048) gleich mit
                // uebernehmen -- Shopify liefert selbst keinen Steuersatz
                // mehr, nur das Land (billingAddress.countryCodeV2), daraus
                // wird ueber die eigene Referenztabelle abgeleitet.
                if ($shopCountry !== null && $shopCountry !== '') {
                    $countryRateStmt = $pdo->prepare('SELECT country_name, standard_rate FROM connect_country_tax_rates WHERE country_code = :code');
                    $countryRateStmt->execute(['code' => (string) $shopCountry]);
                    $countryRate = $countryRateStmt->fetch();

                    if ($countryRate !== false) {
                        $pdo->prepare('UPDATE connect_shops SET default_tax_rate = :rate WHERE shop = :shop')
                            ->execute(['rate' => $countryRate['standard_rate'], 'shop' => $shop]);

                        $flashMessage .= ' Standard-Steuersatz aus Land "' . $countryRate['country_name'] . '" übernommen: ' . $countryRate['standard_rate'] . '%.';
                    } else {
                        $flashMessage .= ' Land "' . (string) $shopCountry . '" hat keinen hinterlegten Standard-Steuersatz -- bitte manuell eintragen.';
                    }
                }
            }
        } catch (\Throwable $e) {
            $flashMessage = 'Konnte nicht von Shopify abgefragt werden: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

// Vorbelegung fuers manuelle "Steuersatz"-Eingabefeld auf der Produkte-Seite
// (siehe Connect\Sync\ProductImportService::manualInputFieldsForTargetImport(),
// Migration 0047) -- analog zum Altsystem-Fallback aus Shopifys Laendersatz
// (siehe Recherche: dort NIE fuer echte Netto/Brutto-Umrechnung genutzt, nur
// als unveraendert durchgereichter Bruttopreis + Steuersatz). Rein optional,
// leer = keine Vorbelegung.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_default_tax_rate') {
    $defaultTaxRateInput = trim((string) ($_POST['default_tax_rate'] ?? ''));
    $defaultTaxRateInput = str_replace(',', '.', $defaultTaxRateInput);

    if ($defaultTaxRateInput !== '' && (!is_numeric($defaultTaxRateInput) || (float) $defaultTaxRateInput < 0)) {
        $flashMessage = 'Bitte einen gültigen Steuersatz (z. B. 20) angeben oder leer lassen.';
        $flashType = 'danger';
    } else {
        $pdo->prepare('UPDATE connect_shops SET default_tax_rate = :rate WHERE shop = :shop')
            ->execute(['rate' => $defaultTaxRateInput !== '' ? $defaultTaxRateInput : null, 'shop' => $shop]);

        $flashMessage = 'Standard-Steuersatz gespeichert.';
        $flashType = 'success';
    }
}

// Anhaenge an der Rechnungsmail (Connect\Mail\ShopAttachments, Migration 0089) -- je Abo freigegeben
$attachmentStore = new \Connect\Mail\ShopAttachments($pdo);
$attachmentsSupported = \Connect\Sync\TargetCapabilities::supports($pdo, $shop, 'attachments');
$attachmentsAllowed = $attachmentsSupported && ($isAdmin || \Connect\Billing\Plans::allowsFeature(\Connect\Billing\Plans::forShop($pdo, $shop), 'attachments'));
if ($attachmentsAllowed && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['upload_attachment', 'remove_attachment', 'toggle_attachment'], true)) {
    try {
        if ($_POST['action'] === 'upload_attachment') {
            $attachmentError = $attachmentStore->upload($shop, $_FILES['attachment'] ?? null);
            $flashMessage = $attachmentError ?? 'Anhang gespeichert – er wird ab der nächsten Rechnungsmail mitgeschickt.';
            $flashType = $attachmentError === null ? 'success' : 'danger';
        } elseif ($_POST['action'] === 'remove_attachment') {
            $attachmentStore->remove($shop, (int) ($_POST['attachment_id'] ?? 0));
            $flashMessage = 'Anhang entfernt.';
            $flashType = 'success';
        } else {
            $attachmentStore->setEnabled($shop, (int) ($_POST['attachment_id'] ?? 0), ($_POST['enabled'] ?? '') === '1');
            $flashMessage = 'Gespeichert.';
            $flashType = 'success';
        }
    } catch (\PDOException) {
        $flashMessage = 'Migration 0089 fehlt noch.';
        $flashType = 'danger';
    }
}

// -----------------------------------------------------------------------
// POST: Rueckbuchung bei Kassenverkauf (Migration 0104) -- ADMIN: Rueckbuchung UND Dummy-Bestellung
// (legt echte Bestellungen im Shop an, deshalb nur hier). Haendler schalten nur die Rueckbuchung
// selbst (save_stock_settings unten). Siehe Connect\Setup\StockBackSettings / StockBackRunner.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_stock_back_settings') {
    if (!$isAdmin) {
        $flashMessage = 'Die Dummy-Bestellung kann nur im Admin-Bereich geändert werden.';
        $flashType = 'danger';
    } else {
        $backResult = \Connect\Setup\StockBackSettings::save(
            $pdo,
            $app,
            $shop,
            isset($_POST['stock_back_enabled']),
            isset($_POST['stock_back_dummy_order'])
        );
        $flashMessage = $backResult['message'];
        $flashType = $backResult['ok'] ? 'success' : 'danger';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_stock_settings') {
    $direction = (string) ($_POST['stock_sync_direction'] ?? '');
    $primaryLocationId = trim((string) ($_POST['stock_primary_location_id'] ?? ''));
    $enabled = isset($_POST['stock_sync_enabled']) ? 1 : 0;

    if (!in_array($direction, ['shopify_to_target', 'target_to_shopify'], true)) {
        $flashMessage = 'Bitte eine gültige Richtung wählen.';
        $flashType = 'danger';
    } else {
        $previousLocationStmt = $pdo->prepare('SELECT stock_primary_location_id FROM connect_shops WHERE shop = :shop');
        $previousLocationStmt->execute(['shop' => $shop]);
        $previousLocationId = (string) ($previousLocationStmt->fetchColumn() ?: '');

        $pdo->prepare(
            'UPDATE connect_shops
             SET stock_sync_direction = :direction, stock_primary_location_id = :location_id, stock_sync_enabled = :enabled
             WHERE shop = :shop'
        )->execute([
            'direction' => $direction,
            'location_id' => $primaryLocationId !== '' ? $primaryLocationId : null,
            'enabled' => $enabled,
            'shop' => $shop,
        ]);

        // Standort geaendert: Shopify-Produkte sofort neu laden, damit der Bestand des neuen Standorts
        // im Zwischenspeicher steht (bis dahin gleicht der Lagerabgleich die Shopify-Produkte nicht ab).
        if ($primaryLocationId !== '' && $primaryLocationId !== $previousLocationId) {
            \Connect\Sync\JobScheduler::dataJobsNowQuietly($pdo, $shop);
        }

        // Aktivierung fuer den Scheduler (mapping_kind='stock_sync'), gemeinsam mit der Einrichtung
        (new \Connect\Setup\ShopActivations($pdo, $app->crypto, $app->config))->syncStockMapping($shop, $enabled === 1);

        // Sicherheitsbestand (Migration 0087) -- eigenes UPDATE, damit das Speichern ohne Migration nicht scheitert
        try {
            $pdo->prepare('UPDATE connect_shops SET stock_safety_buffer = :buffer WHERE shop = :shop')
                ->execute(['buffer' => max(0, min(999, (int) ($_POST['stock_safety_buffer'] ?? 0))), 'shop' => $shop]);
        } catch (\PDOException) {
        }

        // Rueckbuchung bei Kassenverkauf (Migration 0104): nur wenn das Formular den Schalter enthielt. Wechselt die
        // Richtung auf "Kassensystem fuehrt", wird sie ausgeschaltet. Die Dummy-Bestellung bleibt unveraendert (Admin).
        $backNote = '';
        $backFailed = false;
        $backState = \Connect\Setup\StockBackSettings::state($pdo, $shop);
        if ($backState !== null) {
            $backWanted = isset($_POST['stock_back_present']) ? isset($_POST['stock_back_enabled']) : $backState['enabled'];
            if ($direction !== 'shopify_to_target') {
                $backNote = $backState['enabled'] ? ' Die Rückbuchung bei Kassenverkauf wurde ausgeschaltet (gilt nur, wenn der Bestand in Shopify geführt wird).' : '';
                $backWanted = false;
            }
            if ($backWanted !== $backState['enabled']) {
                $backResult = \Connect\Setup\StockBackSettings::save($pdo, $app, $shop, $backWanted, null);
                $backNote = $backNote !== '' ? $backNote : ' ' . $backResult['message'];
                $backFailed = !$backResult['ok'];
            }
        }

        $flashMessage = 'Lagerbestand-Einstellungen gespeichert.' . $backNote;
        $flashType = $backFailed ? 'warning' : 'success';
    }
}

// -----------------------------------------------------------------------
// POST: Merchant verbindet selbst sein Zielsystem (z.B. MGM) über den
// Einrichtungswizard -- legt die Connection an, testet sie sofort und setzt
// sie bei Erfolg direkt als connect_shops.target_connection_id. Ersetzt den
// bisherigen Admin-only Weg (admin/connections.php + admin/shops.php Ziel-
// Connection-Auswahl) für den Merchant-Fall, Admin kann weiterhin manuell
// eingreifen/korrigieren.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'connect_target_system') {
    // Gemeinsame Logik mit dem ersten Fenster (app/welcome.php), siehe Connect\Setup\TargetSystemSetup
    $targetResult = \Connect\Setup\TargetSystemSetup::connect(
        $pdo,
        $app->crypto,
        $buildFactory(),
        $shop,
        (int) ($_POST['connector_type_id'] ?? 0),
        is_array($_POST['credentials'] ?? null) ? $_POST['credentials'] : []
    );
    $flashMessage = $targetResult['message'];
    $flashType = !$targetResult['ok'] ? 'danger' : ($targetResult['connected'] ? 'success' : 'info');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_master_data') {
    // Knopf "Daten aktualisieren": Auswahllisten (Shopify-Standorte, Mitarbeiter und Zahlungsarten
    // der Kasse) sofort frisch laden, statt auf den naechsten Lauf der Sync-Job-Vorlage (taeglich)
    // zu warten. Schreibt nichts ins Zielsystem. Fehlt ein Object (nicht eingerichtet), wird es
    // uebersprungen.
    $refreshTargets = [];
    $refreshStmt = $pdo->prepare(
        'SELECT s.target_connection_id, (SELECT c.id FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id
                WHERE c.shop = s.shop AND t.type_key = \'shopify\' LIMIT 1) AS shopify_connection_id
         FROM connect_shops s WHERE s.shop = :shop'
    );
    $refreshStmt->execute(['shop' => $shop]);
    $refreshRow = $refreshStmt->fetch();
    if ($refreshRow !== false) {
        if ($refreshRow['shopify_connection_id'] !== null) {
            $refreshTargets[] = [(int) $refreshRow['shopify_connection_id'], 'location', 'Standorte'];
        }
        if ($refreshRow['target_connection_id'] !== null) {
            $refreshTargets[] = [(int) $refreshRow['target_connection_id'], 'employee', 'Mitarbeiter'];
            $refreshTargets[] = [(int) $refreshRow['target_connection_id'], 'payment_method', 'Zahlungsarten'];
        }
    }

    $refreshOk = [];
    $refreshFailed = [];
    $refreshObjectStmt = $pdo->prepare(
        'SELECT o.id FROM connect_objects o JOIN connect_connections c ON c.connector_type_id = o.connector_type_id
         WHERE c.id = :conn AND o.object_key = :key LIMIT 1'
    );
    @set_time_limit(120);
    foreach ($refreshTargets as [$refreshConnId, $refreshKey, $refreshLabel]) {
        $refreshObjectStmt->execute(['conn' => $refreshConnId, 'key' => $refreshKey]);
        $refreshObjectId = (int) $refreshObjectStmt->fetchColumn();
        if ($refreshObjectId === 0) {
            continue;
        }
        try {
            $refreshFactory = $buildFactory();
            $refreshCount = (new \Connect\Sync\ObjectCache($pdo))->refresh(
                $refreshFactory->forConnection($refreshConnId),
                $refreshConnId,
                $refreshFactory->objectDefinitionById($refreshObjectId),
                [],
                null
            )['processed'];
            $refreshOk[] = $refreshLabel . ' (' . $refreshCount . ')';
        } catch (\Throwable $e) {
            $refreshFailed[] = $refreshLabel . ': ' . $e->getMessage();
        }
    }

    if ($refreshFailed !== []) {
        $flashMessage = 'Nicht alles konnte aktualisiert werden: ' . implode(' | ', $refreshFailed) . ($refreshOk !== [] ? '. Aktualisiert: ' . implode(', ', $refreshOk) . '.' : '');
        $flashType = 'danger';
    } else {
        $flashMessage = $refreshOk !== [] ? 'Aktualisiert: ' . implode(', ', $refreshOk) . '.' : 'Es gab keine Auswahllisten zu aktualisieren.';
        $flashType = 'success';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_invoice_settings' && isset($_POST['test_smtp'])) {
    // Verbindungstest (eigener Button bei den SMTP-Feldern): prueft nur Login,
    // sendet keine Mail und speichert nichts. Nutzt die GERADE eingegebenen
    // Werte; ein leeres Passwortfeld greift wie beim Speichern auf das bereits
    // hinterlegte (entschluesselte) Passwort zurueck, damit ein Test nicht
    // erfordert, das Passwort erneut einzutippen.
    $testPassword = (string) ($_POST['invoice_mail_smtp_password'] ?? '');
    if ($testPassword === '') {
        $storedPasswordStmt = $pdo->prepare('SELECT invoice_mail_smtp_password FROM connect_shops WHERE shop = :shop');
        $storedPasswordStmt->execute(['shop' => $shop]);
        $storedEncrypted = $storedPasswordStmt->fetchColumn();
        if ($storedEncrypted) {
            $testPassword = $app->crypto->decrypt((string) $storedEncrypted);
        }
    }

    $testError = (new \Connect\Mail\InvoiceMailer())->testConnection([
        'host' => trim((string) ($_POST['invoice_mail_smtp_host'] ?? '')),
        'port' => trim((string) ($_POST['invoice_mail_smtp_port'] ?? '')),
        'username' => trim((string) ($_POST['invoice_mail_smtp_username'] ?? '')),
        'password' => $testPassword,
        'encryption' => in_array($_POST['invoice_mail_smtp_encryption'] ?? '', ['tls', 'ssl', 'none'], true)
            ? $_POST['invoice_mail_smtp_encryption'] : 'tls',
    ]);

    $flashMessage = $testError === null
        ? 'SMTP-Verbindung erfolgreich (Login geprüft, es wurde keine Mail versendet).'
        : 'SMTP-Verbindung fehlgeschlagen: ' . $testError;
    $flashType = $testError === null ? 'success' : 'danger';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_invoice_settings') {
    $invoiceEnabled = isset($_POST['invoice_creation_enabled']) ? 1 : 0;
    $posOnly = isset($_POST['invoice_pos_only']) ? 1 : 0;
    $suppressAndAdjustStock = isset($_POST['invoice_suppress_and_adjust_stock']) ? 1 : 0;
    $mailEnabled = isset($_POST['invoice_mail_enabled']) ? 1 : 0;
    $cashierIdRaw = trim((string) ($_POST['invoice_cashier_id'] ?? ''));
    $cashierId = ($cashierIdRaw !== '' && ctype_digit($cashierIdRaw)) ? (int) $cashierIdRaw : null;
    $paymentMethod = trim((string) ($_POST['invoice_payment_method'] ?? '')) ?: 'Webshop';
    // Fehler-Adresse steht seit Migration 0079 in der Karte "Benachrichtigungen" (notify_email)

    // Eigenes SMTP fuer den Rechnungs-Mailversand -- Passwort nur ueberschreiben, wenn ein
    // neuer Wert eingegeben wurde (wie bei den Connection-Zugangsdaten: leer = unveraendert
    // lassen), sonst koennte ein leeres Absenden versehentlich das bestehende Passwort loeschen.
    $smtpHost = trim((string) ($_POST['invoice_mail_smtp_host'] ?? '')) ?: null;
    $smtpPortRaw = trim((string) ($_POST['invoice_mail_smtp_port'] ?? ''));
    $smtpPort = ($smtpPortRaw !== '' && ctype_digit($smtpPortRaw)) ? (int) $smtpPortRaw : null;
    $smtpUsername = trim((string) ($_POST['invoice_mail_smtp_username'] ?? '')) ?: null;
    $smtpEncryption = in_array($_POST['invoice_mail_smtp_encryption'] ?? '', ['tls', 'ssl', 'none'], true)
        ? $_POST['invoice_mail_smtp_encryption'] : 'tls';

    $smtpPasswordSql = '';
    $smtpPasswordParams = [];
    if (($_POST['invoice_mail_smtp_password'] ?? '') !== '') {
        $smtpPasswordSql = ', invoice_mail_smtp_password = :smtp_password';
        $smtpPasswordParams['smtp_password'] = $app->crypto->encrypt((string) $_POST['invoice_mail_smtp_password']);
    }

    $pdo->prepare(
        'UPDATE connect_shops
         SET invoice_creation_enabled = :enabled, invoice_pos_only = :pos_only,
             invoice_suppress_and_adjust_stock = :suppress_and_adjust_stock,
             invoice_cashier_id = :cashier_id, invoice_payment_method = :payment_method,
             invoice_mail_enabled = :mail_enabled, invoice_mail_sender_name = :mail_sender_name,
             invoice_mail_sender_address = :mail_sender_address, invoice_mail_subject = :mail_subject,
             invoice_mail_message = :mail_message, invoice_mail_smtp_host = :smtp_host,
             invoice_mail_smtp_port = :smtp_port, invoice_mail_smtp_username = :smtp_username,
             invoice_mail_smtp_encryption = :smtp_encryption'
        . $smtpPasswordSql . '
         WHERE shop = :shop'
    )->execute(array_merge([
        'enabled' => $invoiceEnabled,
        'pos_only' => $posOnly,
        'suppress_and_adjust_stock' => $suppressAndAdjustStock,
        'cashier_id' => $cashierId,
        'payment_method' => $paymentMethod,
        'mail_enabled' => $mailEnabled,
        'mail_sender_name' => trim((string) ($_POST['invoice_mail_sender_name'] ?? '')) ?: null,
        'mail_sender_address' => trim((string) ($_POST['invoice_mail_sender_address'] ?? '')) ?: null,
        'mail_subject' => trim((string) ($_POST['invoice_mail_subject'] ?? '')) ?: null,
        'mail_message' => trim((string) ($_POST['invoice_mail_message'] ?? '')) ?: null,
        'smtp_host' => $smtpHost,
        'smtp_port' => $smtpPort,
        'smtp_username' => $smtpUsername,
        'smtp_encryption' => $smtpEncryption,
        'shop' => $shop,
    ], $smtpPasswordParams));

    // Signaturpflicht (Migration 0072) getrennt -- fehlt die Spalte noch, bleibt der Rest gespeichert
    try {
        $pdo->prepare('UPDATE connect_shops SET invoice_signature_mandatory = :sig WHERE shop = :shop')
            ->execute(['sig' => isset($_POST['invoice_signature_mandatory']) ? 1 : 0, 'shop' => $shop]);
    } catch (\Throwable) {
        // Migration 0072 noch nicht eingespielt
    }
    // Kundenzuordnung (Migration 0095) ebenso getrennt
    if (isset(\Connect\Sync\InvoiceService::CUSTOMER_MATCH_LABELS[$_POST['invoice_customer_match'] ?? ''])) {
        try {
            $pdo->prepare('UPDATE connect_shops SET invoice_customer_match = :match WHERE shop = :shop')
                ->execute(['match' => (string) $_POST['invoice_customer_match'], 'shop' => $shop]);
        } catch (\Throwable) {
            // Migration 0095 noch nicht eingespielt
        }
    }

    $flashMessage = 'Einstellung für Rechnungen/Aufträge gespeichert.';
    $flashType = 'success';

    // Aktivierung (mapping_kind='invoice', mit target_connection_id -- sonst greift
    // webhooks/order_paid.php sie nicht auf) und Shopify-Abo "Bestellung bezahlt",
    // gemeinsam mit der Einrichtung (Connect\Setup\ShopActivations)
    $activations = new \Connect\Setup\ShopActivations($pdo, $app->crypto, $app->config);
    $invoiceWarning = $activations->syncInvoiceMapping($shop, $invoiceEnabled === 1);
    if ($invoiceWarning !== null) {
        $flashMessage .= ' (Achtung: ' . $invoiceWarning . ')';
        $flashType = 'danger';
    }
    $webhookWarning = $activations->syncOrdersPaidWebhook($shop, $invoiceEnabled === 1);
    if ($webhookWarning !== null) {
        $flashMessage .= ' (' . $webhookWarning . ')';
    }
}

// -----------------------------------------------------------------------
// POST: Benachrichtigungen (Migration 0079, Connect\Notify\Notifier) -- Empfaenger
// (leer = Shop-E-Mail aus Shopify), Probleme sofort melden, Wochenbericht.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notification_settings') {
    $notifyEmail = trim((string) ($_POST['notify_email'] ?? ''));
    if ($notifyEmail !== '' && filter_var($notifyEmail, FILTER_VALIDATE_EMAIL) === false) {
        $flashMessage = 'Bitte eine gültige E-Mail-Adresse eintragen oder das Feld leer lassen (dann gilt die Adresse aus Shopify).';
        $flashType = 'danger';
    } else {
        try {
            $pdo->prepare('UPDATE connect_shops SET notify_email = :email, notify_problems = :problems, weekly_report_enabled = :weekly WHERE shop = :shop')
                ->execute([
                    'email' => $notifyEmail !== '' ? $notifyEmail : null,
                    'problems' => isset($_POST['notify_problems']) ? 1 : 0,
                    'weekly' => isset($_POST['weekly_report_enabled']) ? 1 : 0,
                    'shop' => $shop,
                ]);
            $flashMessage = 'Benachrichtigungen gespeichert.';
            $flashType = 'success';
            // Mail bei niedrigem Lagerstand (Migration 0092), getrennt -- fehlt die Spalte, bleibt der Rest gespeichert
            if (isset($_POST['low_stock_threshold'])) {
                $threshold = isset($_POST['low_stock_enabled']) ? max(0, min(9999, (int) $_POST['low_stock_threshold'])) : 0;
                try {
                    $pdo->prepare('UPDATE connect_shops SET low_stock_threshold = :t WHERE shop = :shop')
                        ->execute(['t' => $threshold > 0 ? $threshold : null, 'shop' => $shop]);
                } catch (\PDOException) {
                    $flashMessage .= ' Die Lagerstand-Mail ist erst nach dem nächsten Update verfügbar.';
                }
            }
        } catch (\PDOException) {
            $flashMessage = 'Benachrichtigungen können erst nach dem nächsten Update gespeichert werden (Migration 0079).';
            $flashType = 'danger';
        }
    }
}

// -----------------------------------------------------------------------
// POST: automatischer Produktimport (Shopify -> Zielsystem) -- ADMIN-ONLY
// (Nutzer-Vorgabe 2026-09-27: "diesen automatischen Produktimport aktiviert
// der Admin für den Merchant, bei Bedarf") -- serverseitig geprueft, nicht
// nur durch Verstecken der Karte weiter unten, gleiches Prinzip wie
// "create_connection" in app/_connections_body.php. Siehe Connect\Sync\
// AutoProductImportRunner (Migration 0059).
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_auto_import_settings' && !$isAdmin) {
    $flashMessage = 'Der automatische Produktimport kann nur im Admin-Bereich geändert werden.';
    $flashType = 'danger';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_auto_import_settings') {
    $autoImportEnabled = isset($_POST['auto_import_missing_to_target']) ? 1 : 0;
    $autoImportFilterFieldKey = trim((string) ($_POST['auto_import_filter_field_key'] ?? '')) ?: null;
    $autoImportFilterValue = trim((string) ($_POST['auto_import_filter_value'] ?? '')) ?: null;
    // Ein Filter braucht BEIDE Teile (Feld + Wert), sonst wirkt er nicht --
    // ohne diese Absicherung koennte z.B. ein Feld ohne Wert versehentlich
    // ALLE fehlenden Produkte ausschliessen (leerer Teilstring ist in JEDEM
    // Text enthalten, stripos('irgendwas', '') === 0, also eigentlich kein
    // Ausschluss -- trotzdem klarer, unvollstaendige Eingaben ganz zu ignorieren).
    if ($autoImportFilterFieldKey === null || $autoImportFilterValue === null) {
        $autoImportFilterFieldKey = null;
        $autoImportFilterValue = null;
    }

    $pdo->prepare(
        'UPDATE connect_shops
         SET auto_import_missing_to_target = :enabled, auto_import_filter_field_key = :filter_field_key,
             auto_import_filter_value = :filter_value
         WHERE shop = :shop'
    )->execute([
        'enabled' => $autoImportEnabled,
        'filter_field_key' => $autoImportFilterFieldKey,
        'filter_value' => $autoImportFilterValue,
        'shop' => $shop,
    ]);

    $flashMessage = 'Einstellung für den automatischen Produktimport gespeichert.';
    $flashType = 'success';

    // Sync-Mapping-Zeile fuer den Scheduler-Nachlauf/die Job-Historie upserten --
    // gleiches Muster wie bei "invoice"/"stock_sync". source_connection_id/
    // source_object_id sind hier nur fuer die Anzeige (NOT-NULL-Spalten) --
    // AutoProductImportRunner ermittelt Quelle/Ziel bei der Ausfuehrung selbst
    // neu (ueber ProductComparisonService/ProductMatchContext).
    $targetConnectionIdForAutoImportStmt = $pdo->prepare('SELECT target_connection_id FROM connect_shops WHERE shop = :shop');
    $targetConnectionIdForAutoImportStmt->execute(['shop' => $shop]);
    $targetConnectionIdForAutoImport = $targetConnectionIdForAutoImportStmt->fetchColumn();

    if ($autoImportEnabled === 1 && $targetConnectionIdForAutoImport === false) {
        $flashMessage .= ' (Achtung: keine Software verbunden -- bitte zuerst eine Connection als Ziel wählen, der automatische Import kann sonst nicht auslösen.)';
        $flashType = 'danger';
    } else {
        $existingAutoImportMappingStmt = $pdo->prepare(
            "SELECT id FROM connect_sync_mappings WHERE shop = :shop AND mapping_kind = 'auto_import_products'"
        );
        $existingAutoImportMappingStmt->execute(['shop' => $shop]);
        $autoImportMappingId = $existingAutoImportMappingStmt->fetchColumn();

        if ($autoImportMappingId !== false) {
            $pdo->prepare(
                "UPDATE connect_sync_mappings
                 SET enabled = :enabled, target_connection_id = :target_connection_id, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            )->execute(['enabled' => $autoImportEnabled, 'target_connection_id' => $targetConnectionIdForAutoImport, 'id' => $autoImportMappingId]);
        } elseif ($autoImportEnabled === 1) {
            $shopifyConnectionForAutoImportStmt = $pdo->prepare(
                "SELECT c.id, o.id AS object_id FROM connect_connections c
                 JOIN connect_connector_types t ON t.id = c.connector_type_id
                 JOIN connect_objects o ON o.connector_type_id = t.id AND o.object_key = 'product'
                 WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
            );
            $shopifyConnectionForAutoImportStmt->execute(['shop' => $shop]);
            $shopifyForAutoImport = $shopifyConnectionForAutoImportStmt->fetch();

            if ($shopifyForAutoImport !== false) {
                $pdo->prepare(
                    "INSERT INTO connect_sync_mappings
                        (shop, name, source_connection_id, source_object_id, target_connection_id, trigger_type, schedule_interval_minutes, enabled, managed_by, mapping_kind)
                     VALUES (:shop, 'Automatischer Produktimport', :source_connection_id, :source_object_id, :target_connection_id, 'schedule', 60, 1, 'system', 'auto_import_products')"
                )->execute([
                    'shop' => $shop,
                    'source_connection_id' => (int) $shopifyForAutoImport['id'],
                    'source_object_id' => (int) $shopifyForAutoImport['object_id'],
                    'target_connection_id' => $targetConnectionIdForAutoImport,
                ]);
            } else {
                $flashMessage .= ' (Achtung: keine Shopify-Connection mit "Produkt"-Objekt gefunden -- automatischer Import kann so nicht auslösen.)';
                $flashType = 'danger';
            }
        }
    }
}

// -----------------------------------------------------------------------
// POST: Ausverkaufte Produkte ausblenden / wieder einblenden -- ADMIN-ONLY,
// gleiches Muster wie der automatische Produktimport oben. Siehe
// Connect\Sync\ProductVisibilityRunner (Migration 0073).
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_visibility_settings' && !$isAdmin) {
    $flashMessage = 'Das Ausblenden/Einblenden kann nur im Admin-Bereich geändert werden.';
    $flashType = 'danger';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_visibility_settings') {
    $visibilityEnabled = isset($_POST['visibility_sync_enabled']) ? 1 : 0;
    $reactivateMode = in_array($_POST['visibility_reactivate_mode'] ?? '', ['own', 'all', 'never'], true) ? (string) $_POST['visibility_reactivate_mode'] : 'own';

    $pdo->prepare(
        'UPDATE connect_shops
         SET visibility_sync_enabled = :enabled, visibility_single_variant_only = :single_variant,
             visibility_require_description = :require_description, visibility_only_matched = :only_matched,
             visibility_reactivate_mode = :reactivate_mode
         WHERE shop = :shop'
    )->execute([
        'enabled' => $visibilityEnabled,
        'single_variant' => isset($_POST['visibility_single_variant_only']) ? 1 : 0,
        'require_description' => isset($_POST['visibility_require_description']) ? 1 : 0,
        'only_matched' => isset($_POST['visibility_only_matched']) ? 1 : 0,
        'reactivate_mode' => $reactivateMode,
        'shop' => $shop,
    ]);

    $flashMessage = 'Einstellung für das Ausblenden/Einblenden gespeichert.';
    $flashType = 'success';

    // Job-Zeile fuer Scheduler/Job-Historie upserten (source_* nur fuer die Anzeige, NOT NULL)
    $visibilityMappingStmt = $pdo->prepare("SELECT id FROM connect_sync_mappings WHERE shop = :shop AND mapping_kind = 'product_visibility'");
    $visibilityMappingStmt->execute(['shop' => $shop]);
    $visibilityMappingId = $visibilityMappingStmt->fetchColumn();

    if ($visibilityMappingId !== false) {
        $pdo->prepare('UPDATE connect_sync_mappings SET enabled = :enabled, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['enabled' => $visibilityEnabled, 'id' => $visibilityMappingId]);
    } elseif ($visibilityEnabled === 1) {
        $shopifyForVisibilityStmt = $pdo->prepare(
            "SELECT c.id, o.id AS object_id FROM connect_connections c
             JOIN connect_connector_types t ON t.id = c.connector_type_id
             JOIN connect_objects o ON o.connector_type_id = t.id AND o.object_key = 'product'
             WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
        );
        $shopifyForVisibilityStmt->execute(['shop' => $shop]);
        $shopifyForVisibility = $shopifyForVisibilityStmt->fetch();

        if ($shopifyForVisibility !== false) {
            $pdo->prepare(
                "INSERT INTO connect_sync_mappings
                    (shop, name, source_connection_id, source_object_id, target_connection_id, trigger_type, schedule_interval_minutes, enabled, managed_by, mapping_kind)
                 VALUES (:shop, 'Ausverkaufte Produkte ausblenden', :source_connection_id, :source_object_id, NULL, 'schedule', 60, 1, 'system', 'product_visibility')"
            )->execute([
                'shop' => $shop,
                'source_connection_id' => (int) $shopifyForVisibility['id'],
                'source_object_id' => (int) $shopifyForVisibility['object_id'],
            ]);
        } else {
            $flashMessage .= ' (Achtung: keine Shopify-Connection mit "Produkt"-Objekt gefunden -- der Job kann so nicht laufen.)';
            $flashType = 'danger';
        }
    }
}

// -----------------------------------------------------------------------
// POST: Sicherheitsbremse Lagerabgleich freigeben -- ADMIN-ONLY (Connect\Sync\StockGuard)
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'release_stock_guard') {
    if (!$isAdmin) {
        $flashMessage = 'Die Sicherheitsbremse kann nur im Admin-Bereich freigegeben werden.';
        $flashType = 'danger';
    } else {
        (new \Connect\Sync\StockGuard($pdo))->release($shop);
        // gleich einplanen statt bis zum naechsten Intervall zu warten
        $releaseJob = $pdo->prepare("SELECT id FROM connect_sync_mappings WHERE shop = :shop AND mapping_kind = 'stock_sync' AND enabled = 1");
        $releaseJob->execute(['shop' => $shop]);
        $releaseMappingId = $releaseJob->fetchColumn();
        if ($releaseMappingId !== false) {
            $pdo->prepare("INSERT INTO connect_sync_jobs (sync_mapping_id, status) SELECT :id, 'pending' FROM DUAL
                           WHERE NOT EXISTS (SELECT 1 FROM connect_sync_jobs WHERE sync_mapping_id = :id2 AND status IN ('pending', 'running'))")
                ->execute(['id' => (int) $releaseMappingId, 'id2' => (int) $releaseMappingId]);
        }
        $flashMessage = 'Freigegeben: Der nächste Lagerabgleich läuft ohne Prüfung (innerhalb einer Minute) und setzt den Vergleichsstand neu.';
        $flashType = 'success';
    }
}

// -----------------------------------------------------------------------
// Einrichtungs-Assistent (Dashboard, Connect\Setup\SetupAssistant): eine
// erfolgreich gespeicherte Karte hakt ihre Schritte ab.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flashType !== 'danger' && isset(\Connect\Setup\SetupAssistant::SAVE_ACTIONS[$_POST['action'] ?? ''])
    && !isset($_POST['test_smtp'])
) {
    (new \Connect\Setup\SetupAssistant($pdo))->confirm($shop, \Connect\Setup\SetupAssistant::SAVE_ACTIONS[(string) $_POST['action']]);
}

// -----------------------------------------------------------------------
// Daten fuer die Anzeige laden
// -----------------------------------------------------------------------
// target_connection_id IS NULL grenzt bewusst auf reine Cache-Mappings ein --
// Uebersetzungs-Mappings (Ziel gesetzt, siehe Connect\Mapping\MappingEngine)
// leben ab jetzt in admin/mappings.php, damit diese Seite ("Sync") nicht mit
// zwei grundverschiedenen Konzepten ueberladen ist (siehe Plan "Mapping
// Engine v1", Abschnitt "Admin-UI — neue, klarere Struktur").
$mappingsStmt = $pdo->prepare(
    'SELECT m.*, c.label AS source_connection_label, o.label AS source_object_label,
            (SELECT r.status FROM connect_sync_runs r WHERE r.sync_mapping_id = m.id ORDER BY r.id DESC LIMIT 1) AS last_run_status,
            (SELECT r.started_at FROM connect_sync_runs r WHERE r.sync_mapping_id = m.id ORDER BY r.id DESC LIMIT 1) AS last_run_started_at,
            (SELECT r.records_read FROM connect_sync_runs r WHERE r.sync_mapping_id = m.id ORDER BY r.id DESC LIMIT 1) AS last_run_records,
            (SELECT r.error_summary FROM connect_sync_runs r WHERE r.sync_mapping_id = m.id ORDER BY r.id DESC LIMIT 1) AS last_run_error,
            -- Naechster geplanter Lauf = letzter bekannter Zeitpunkt (aus einem
            -- bereits angelegten Job ODER, falls noch kein Job existiert, aus dem
            -- letzten abgeschlossenen Lauf) + Intervall -- entspricht der
            -- Due-Pruefung in bin/scheduler.php::enqueueDueMappings(). NULL, wenn
            -- weder je ein Job noch ein Lauf existiert (dann ist es "in Kürze",
            -- sobald der Scheduler das erste Mal vorbeischaut).
            DATE_ADD(
                COALESCE(
                    (SELECT MAX(j.scheduled_for) FROM connect_sync_jobs j WHERE j.sync_mapping_id = m.id),
                    (SELECT MAX(r2.started_at) FROM connect_sync_runs r2 WHERE r2.sync_mapping_id = m.id)
                ),
                INTERVAL m.schedule_interval_minutes MINUTE
            ) AS next_run_at
     FROM connect_sync_mappings m
     JOIN connect_connections c ON c.id = m.source_connection_id
     JOIN connect_objects o ON o.id = m.source_object_id
     WHERE m.shop = :shop AND m.target_connection_id IS NULL
     ORDER BY m.id DESC'
);
$mappingsStmt->execute(['shop' => $shop]);
$mappings = $mappingsStmt->fetchAll();

// Fuer das Anlage-Formular: alle global gepflegten Job-Vorlagen (siehe
// admin/sync_job_templates.php) + alle Connections dieses Shops -- welche
// Connection tatsaechlich zur gewaehlten Vorlage passt (Connector Type),
// wird beim Speichern serverseitig geprueft (siehe create_mapping oben).
$syncJobTemplates = $pdo->query(
    'SELECT t.id, t.name, t.connector_type_id, t.default_interval_minutes, ct.name AS type_name
     FROM connect_sync_job_templates t
     JOIN connect_connector_types ct ON ct.id = t.connector_type_id
     ORDER BY ct.name, t.name'
)->fetchAll();

$connectionsForShopStmt = $pdo->prepare(
    'SELECT c.id, c.label, c.connector_type_id, t.name AS type_name
     FROM connect_connections c
     JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE c.shop = :shop
     ORDER BY c.label'
);
$connectionsForShopStmt->execute(['shop' => $shop]);
$connectionsForShop = $connectionsForShopStmt->fetchAll();

$statusBadge = [
    'success' => 'bg-green-lt',
    'error' => 'bg-red-lt',
    'running' => 'bg-yellow-lt',
    'partial' => 'bg-yellow-lt',
];

// -----------------------------------------------------------------------
// Merchant-Einstellungen (Vergleichswert, Lagerbestand, Rechnungen/
// Aufträge) + Ziel-Connection-Label + gecachte Shopify-Standorte fürs
// Standort-Dropdown. Auch im Admin-Zweig geladen (schadet nicht, wird dort
// aktuell nur nicht angezeigt).
// -----------------------------------------------------------------------
$shopSettingsStmt = $pdo->prepare(
    'SELECT comparison_key, target_connection_id, stock_sync_enabled, stock_sync_direction,
            stock_primary_location_id, invoice_creation_enabled, invoice_pos_only, invoice_suppress_and_adjust_stock,
            variant_option_name, variant_comparison_key,
            shopify_prices_include_tax, default_tax_rate,
            invoice_cashier_id, invoice_payment_method, invoice_mail_enabled, invoice_mail_sender_name,
            invoice_mail_sender_address, invoice_mail_subject, invoice_mail_message,
            invoice_mail_smtp_host, invoice_mail_smtp_port, invoice_mail_smtp_username,
            invoice_mail_smtp_password, invoice_mail_smtp_encryption, invoice_error_notification_email,
            auto_import_missing_to_target, auto_import_filter_field_key, auto_import_filter_value
     FROM connect_shops WHERE shop = :shop'
);
$shopSettingsStmt->execute(['shop' => $shop]);
$shopSettings = $shopSettingsStmt->fetch() ?: [
    'comparison_key' => 'sku',
    'target_connection_id' => null,
    'stock_sync_enabled' => 0,
    'stock_sync_direction' => 'shopify_to_target',
    'stock_primary_location_id' => null,
    'invoice_creation_enabled' => 0,
    'invoice_pos_only' => 0,
    'invoice_suppress_and_adjust_stock' => 0,
    'variant_option_name' => 'Option',
    'variant_comparison_key' => null,
    'shopify_prices_include_tax' => null,
    'default_tax_rate' => null,
    'invoice_cashier_id' => null,
    'invoice_payment_method' => 'Webshop',
    'invoice_mail_enabled' => 0,
    'invoice_mail_sender_name' => null,
    'invoice_mail_sender_address' => null,
    'invoice_mail_subject' => null,
    'invoice_mail_message' => null,
    'invoice_mail_smtp_host' => null,
    'invoice_mail_smtp_port' => null,
    'invoice_mail_smtp_username' => null,
    'invoice_mail_smtp_password' => null,
    'invoice_mail_smtp_encryption' => 'tls',
    'invoice_error_notification_email' => null,
    'auto_import_missing_to_target' => 0,
    'auto_import_filter_field_key' => null,
    'auto_import_filter_value' => null,
];
// Signaturpflicht (Migration 0072) getrennt gelesen, null = Spalte fehlt noch
try {
    $sigStmt = $pdo->prepare('SELECT invoice_signature_mandatory FROM connect_shops WHERE shop = :shop');
    $sigStmt->execute(['shop' => $shop]);
    $sigValue = $sigStmt->fetchColumn();
    $shopSettings['invoice_signature_mandatory'] = $sigValue !== false ? (int) $sigValue : 0;
} catch (\Throwable) {
    $shopSettings['invoice_signature_mandatory'] = null;
}
// Kundenzuordnung (Migration 0095) getrennt gelesen, null = Spalte fehlt noch (Auswahl ausgeblendet)
try {
    $matchStmt = $pdo->prepare('SELECT invoice_customer_match FROM connect_shops WHERE shop = :shop');
    $matchStmt->execute(['shop' => $shop]);
    $matchValue = (string) $matchStmt->fetchColumn();
    $shopSettings['invoice_customer_match'] = isset(\Connect\Sync\InvoiceService::CUSTOMER_MATCH_LABELS[$matchValue])
        ? $matchValue : \Connect\Sync\InvoiceService::CUSTOMER_MATCH_EMAIL_NAME;
} catch (\Throwable) {
    $shopSettings['invoice_customer_match'] = null;
}
// Benachrichtigungen + Abo (Migration 0079): Empfaenger, Shopify-Adresse, eigenes SMTP erlaubt?
$notifySettings = (new \Connect\Notify\Notifier($pdo, $app->config))->settings($shop);
// Mail bei niedrigem Lagerstand (Migration 0092), null = Spalte fehlt noch
try {
    $lowStockStmt = $pdo->prepare('SELECT low_stock_threshold FROM connect_shops WHERE shop = :shop');
    $lowStockStmt->execute(['shop' => $shop]);
    $lowStockThreshold = (int) $lowStockStmt->fetchColumn();
} catch (\PDOException) {
    $lowStockThreshold = null;
}
// Name des Kassensystems fuer die Merchant-Texte statt "Zielsystem"
$targetName = \Connect\Sync\TargetCapabilities::forShop($pdo, $shop)['target_name'];
$shopPlan = \Connect\Billing\Plans::forShop($pdo, $shop);
$ownSmtpAllowed = \Connect\Billing\Plans::allowsOwnSmtp($shopPlan);
// Ausblenden/Einblenden (Migration 0073) getrennt gelesen, null = Spalten fehlen noch
try {
    $visibilityStmt = $pdo->prepare(
        'SELECT visibility_sync_enabled, visibility_single_variant_only, visibility_require_description,
                visibility_only_matched, visibility_reactivate_mode
         FROM connect_shops WHERE shop = :shop'
    );
    $visibilityStmt->execute(['shop' => $shop]);
    $visibilitySettings = $visibilityStmt->fetch() ?: [
        'visibility_sync_enabled' => 0,
        'visibility_single_variant_only' => 0,
        'visibility_require_description' => 0,
        'visibility_only_matched' => 1,
        'visibility_reactivate_mode' => 'own',
    ];
} catch (\Throwable) {
    $visibilitySettings = null;
}

// Card "Varianten-Import" nur zeigen, wenn ueberhaupt eine der Connections
// dieses Shops zu einem System mit Varianten-Unterstuetzung gehoert (aktuell
// nur Shopify, siehe Migration 0034) -- sonst waere die Einstellung fuer
// diesen Shop nie wirksam.
$anyConnectionSupportsVariantsStmt = $pdo->prepare(
    'SELECT 1 FROM connect_connections c
     JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE c.shop = :shop AND t.supports_variants = 1 LIMIT 1'
);
$anyConnectionSupportsVariantsStmt->execute(['shop' => $shop]);
$anyConnectionSupportsVariants = $anyConnectionSupportsVariantsStmt->fetchColumn() !== false;

$targetConnectionLabel = null;
if ($shopSettings['target_connection_id'] !== null) {
    $targetConnectionStmt = $pdo->prepare(
        'SELECT c.label, t.name AS type_name FROM connect_connections c
         JOIN connect_connector_types t ON t.id = c.connector_type_id
         WHERE c.id = :id'
    );
    $targetConnectionStmt->execute(['id' => $shopSettings['target_connection_id']]);
    $targetConnectionRow = $targetConnectionStmt->fetch();
    if ($targetConnectionRow !== false) {
        $targetConnectionLabel = $targetConnectionRow['label'] . ' (' . $targetConnectionRow['type_name'] . ')';
    }
}

$shopifyLocations = [];
$shopifyConnectionStmt = $pdo->prepare(
    "SELECT c.id FROM connect_connections c
     JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
);
$shopifyConnectionStmt->execute(['shop' => $shop]);
$shopifyConnectionId = $shopifyConnectionStmt->fetchColumn();
if ($shopifyConnectionId !== false) {
    $shopifyLocations = (new \Connect\Sync\ObjectCache($pdo))->getCached((int) $shopifyConnectionId, 'location');
}

// Feldauswahl fuer den optionalen Filter beim automatischen Produktimport
// (Migration 0060, admin-only, siehe Connect\Sync\AutoProductImportRunner) --
// nur TOP-LEVEL-Felder des Shopify-"Produkt"-Objects (parent_field_id IS NULL),
// ein Filter auf ein einzelnes Positions-/Gruppen-Feld ergaebe hier keinen Sinn.
$shopifyProductFieldsForFilter = [];
if ($shopifyConnectionId !== false) {
    $shopifyProductFieldsStmt = $pdo->prepare(
        "SELECT f.field_key, f.label FROM connect_object_fields f
         JOIN connect_objects o ON o.id = f.object_id
         JOIN connect_connections c ON c.connector_type_id = o.connector_type_id
         WHERE c.id = :connection_id AND o.object_key = 'product' AND f.parent_field_id IS NULL
         ORDER BY f.label"
    );
    $shopifyProductFieldsStmt->execute(['connection_id' => (int) $shopifyConnectionId]);
    $shopifyProductFieldsForFilter = $shopifyProductFieldsStmt->fetchAll();
}

// Kassierer-Auswahl (Connect\Sync\InvoiceService) -- wie die Shopify-Standorte oben aus dem
// bereits gecachten Bestand gelesen (Object "employee", regelmaessig ueber eine normale
// Sync-Job-Vorlage aktualisiert, kein Live-Abruf bei jedem Aufruf dieser Seite).
$helloCashEmployees = [];
if ($shopSettings['target_connection_id'] !== null) {
    $helloCashEmployees = (new \Connect\Sync\ObjectCache($pdo))->getCached((int) $shopSettings['target_connection_id'], 'employee');
}

// Zahlungsart-Auswahl: Object "payment_method" (GET /payment-methods, Scope payment_methods:read),
// per Connector Builder angelegt wie "employee". Fehlt der Cache, bleibt es ein Textfeld.
$helloCashPaymentMethods = [];
if ($shopSettings['target_connection_id'] !== null) {
    try {
        foreach ((new \Connect\Sync\ObjectCache($pdo))->getCached((int) $shopSettings['target_connection_id'], 'payment_method') as $pm) {
            $pmName = trim((string) ($pm['name'] ?? ''));
            $pmStatus = strtolower((string) ($pm['status'] ?? 'active'));
            if ($pmName !== '' && ($pmStatus === 'active' || $pmStatus === '1' || $pmStatus === 'true')) {
                $helloCashPaymentMethods[$pmName] = $pmName;
            }
        }
    } catch (\Throwable $e) {
        $helloCashPaymentMethods = [];
    }
    ksort($helloCashPaymentMethods);
}

// Fuer den Merchant-Einrichtungswizard: alle Zielsystem-Optionen ausser
// Shopify (das ist immer die feste Quelle, siehe auch admin/shops.php),
// private Typen nur fuer Shops mit bestehender Connection (Migration 0096).
$targetConnectorTypes = \Connect\Setup\TargetSystemSetup::availableTypes($pdo, $shop);

$pageTitle = $isAdmin ? ('Jobs — ' . $shop) : 'Einstellungen';

$navItems = require __DIR__ . '/_shop_nav.php';

if ($isAdmin) {
    $homeUrl = 'shops.php';
    $adminNavItems = require dirname(__DIR__) . '/admin/_nav.php';
    $adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
    $topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
        . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
}

require __DIR__ . '/_header.php';
?>

<?php if (!$isAdmin && !isset($_GET['preview'])): ?>
    <?php // Einrichtung erneut durchgehen (app/welcome.php?rerun=1): vorbelegt, wirksam erst mit "Übernehmen" ?>
    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <div>
                <div class="fw-bold">Einrichtungs-Assistent</div>
                <div class="text-secondary">Die wichtigsten Fragen noch einmal Schritt für Schritt beantworten. Geändert wird erst, wenn du am Ende „Übernehmen“ klickst.</div>
            </div>
            <a href="welcome.php?shop=<?php echo urlencode($shop); ?>&amp;rerun=1" class="btn btn-primary">Assistent starten</a>
        </div>
    </div>
<?php endif; ?>

<div class="row row-cards">
    <?php if ($isAdmin): ?>
        <?php if (count($syncJobTemplates) === 0): ?>
            <div class="col-12">
                <div class="alert alert-info">
                    Es gibt noch keine <a href="sync_job_templates.php">Job-Vorlage</a> — zuerst dort eine anlegen (welche
                    Objects grundsätzlich regelmäßig synchronisiert werden können, z. B. "Shopify: Produkt").
                </div>
            </div>
        <?php elseif (count($connectionsForShop) === 0): ?>
            <div class="col-12">
                <div class="alert alert-info">
                    Es gibt noch keine Connection für diesen Shop — zuerst unter "Connections" eine anlegen.
                </div>
            </div>
        <?php endif; ?>

        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Automatische Synchronisierungen</h3>
                </div>
                <p class="text-secondary px-3 pt-2">
                    Läuft im Hintergrund (per Scheduler) regelmäßig und unbegrenzt — im Unterschied zur Vorschau unter
                    "Daten ansehen", die auf 100 Datensätze begrenzt und nur bei Knopfdruck aktiv ist.
                </p>
                <?php if (count($mappings) === 0): ?>
                    <div class="card-body text-secondary">Noch kein Sync Mapping angelegt.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Quelle</th>
                                    <th>Intervall</th>
                                    <th>Aktiv</th>
                                    <th>Letzter Lauf</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mappings as $mapping): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($mapping['name']); ?></td>
                                        <td><?php echo htmlspecialchars($mapping['source_connection_label'] . ' — ' . $mapping['source_object_label']); ?></td>
                                        <td><?php echo (int) $mapping['schedule_interval_minutes']; ?> Min.</td>
                                        <td>
                                            <span class="badge <?php echo ((int) $mapping['enabled']) === 1 ? 'bg-green-lt' : 'bg-secondary-lt'; ?>">
                                                <?php echo ((int) $mapping['enabled']) === 1 ? 'aktiv' : 'inaktiv'; ?>
                                            </span>
                                        </td>
                                        <td class="text-secondary">
                                            <?php if ($mapping['last_run_started_at'] !== null): ?>
                                                <span class="badge <?php echo htmlspecialchars($statusBadge[$mapping['last_run_status']] ?? 'bg-secondary-lt'); ?>">
                                                    <?php echo htmlspecialchars($mapping['last_run_status']); ?>
                                                </span>
                                                <?php echo htmlspecialchars($mapping['last_run_started_at']); ?>
                                                (<?php echo (int) $mapping['last_run_records']; ?> Datensätze)
                                                <?php if ($mapping['last_run_error'] !== null): ?>
                                                    <br><small class="text-danger"><?php echo htmlspecialchars($mapping['last_run_error']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                noch nie gelaufen
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="run_now">
                                                <input type="hidden" name="mapping_id" value="<?php echo (int) $mapping['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Jetzt ausführen</button>
                                            </form>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="toggle_mapping">
                                                <input type="hidden" name="mapping_id" value="<?php echo (int) $mapping['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                    <?php echo ((int) $mapping['enabled']) === 1 ? 'Deaktivieren' : 'Aktivieren'; ?>
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Sync Mapping \'<?php echo htmlspecialchars(addslashes($mapping['name'])); ?>\' wirklich löschen?');">
                                                <input type="hidden" name="action" value="delete_mapping">
                                                <input type="hidden" name="mapping_id" value="<?php echo (int) $mapping['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Entfernen</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (count($syncJobTemplates) > 0 && count($connectionsForShop) > 0): ?>
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Job-Vorlage für diesen Shop aktivieren</h3>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary">
                            Welche Objects grundsätzlich synchronisiert werden können, wird zentral unter
                            <a href="sync_job_templates.php">Job-Vorlagen</a> gepflegt (gilt für alle Shops) — hier wird nur
                            festgelegt, MIT WELCHER Connection dieses Shops eine Vorlage läuft.
                        </p>
                        <form method="post" class="row g-2">
                            <input type="hidden" name="action" value="create_mapping">
                            <div class="col-md-4">
                                <label class="form-label">Job-Vorlage</label>
                                <select name="sync_job_template_id" id="sync-job-template-select" class="form-select" required onchange="syncJobTemplateChanged()">
                                    <option value="">-- auswählen --</option>
                                    <?php foreach ($syncJobTemplates as $template): ?>
                                        <option value="<?php echo (int) $template['id']; ?>" data-interval="<?php echo (int) $template['default_interval_minutes']; ?>">
                                            <?php echo htmlspecialchars($template['name'] . ' (' . $template['type_name'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Connection (welche dieses Shops?)</label>
                                <select name="source_connection_id" class="form-select" required>
                                    <option value="">-- auswählen --</option>
                                    <?php foreach ($connectionsForShop as $connection): ?>
                                        <option value="<?php echo (int) $connection['id']; ?>"><?php echo htmlspecialchars($connection['label'] . ' (' . $connection['type_name'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Alle wie viele Minuten?</label>
                                <input type="number" name="schedule_interval_minutes" id="sync-job-interval" class="form-control" value="60" min="1" required>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <label class="form-check">
                                    <input type="checkbox" name="enabled" class="form-check-input" checked>
                                    <span class="form-check-label">Sofort aktiv</span>
                                </label>
                            </div>
                            <div class="col-12">
                                <small class="text-secondary">
                                    Legt fest, dass der Hintergrund-Scheduler dieses Object regelmäßig und <strong>unbegrenzt</strong>
                                    (nicht nur die ersten 100 wie in "Daten ansehen") aktualisiert — inklusive Erkennung von
                                    Datensätzen, die in der Quelle nicht mehr existieren.
                                </small>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">Anlegen</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <script>
                // Fuellt beim Wechsel der Job-Vorlage deren Standard-Intervall
                // vor -- bleibt trotzdem frei editierbar (z.B. ein Shop mit
                // hohem Bestellaufkommen will haeufiger als der Vorlagen-Standard).
                function syncJobTemplateChanged() {
                    var select = document.getElementById('sync-job-template-select');
                    var interval = document.getElementById('sync-job-interval');
                    var selectedOption = select.options[select.selectedIndex];
                    if (selectedOption && selectedOption.dataset.interval) {
                        interval.value = selectedOption.dataset.interval;
                    }
                }
            </script>
        <?php endif; ?>

        <?php $stockGuardState = (new \Connect\Sync\StockGuard($pdo))->load($shop); ?>
        <?php if ($stockGuardState !== null && ((int) ($shopSettings['stock_sync_enabled'] ?? 0) === 1 || ($stockGuardState['state'] ?? null) !== null)): ?>
            <?php
            $guardStatus = (string) ($stockGuardState['state'] ?? '');
            $guardBadge = ['paused' => ['bg-red-lt', 'angehalten'], 'warned' => ['bg-yellow-lt', '1 Lauf ausgelassen'], 'released' => ['bg-blue-lt', 'freigegeben – nächster Lauf ohne Prüfung']][$guardStatus] ?? ['bg-green-lt', 'aktiv, unauffällig'];
            ?>
            <div class="col-12" id="stock-guard">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Sicherheitsbremse Lagerabgleich (Admin-only)</h3>
                        <div class="card-actions"><span class="badge <?php echo $guardBadge[0]; ?>"><?php echo htmlspecialchars($guardBadge[1]); ?></span></div>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary">
                            Hält den Abgleich an, wenn die führende Quelle verdächtige Daten liefert: Liste mehr als 20 % kürzer als zuletzt,
                            mind. 25 Artikel (und 10 %) würden von Bestand auf 0 fallen, oder deutlich mehr Nullen als zuletzt.
                            Erste Auffälligkeit: Lauf ausgelassen. Zweite in Folge: angehalten + Mail an dich. Der Merchant bekommt keine Mail.
                        </p>
                        <?php if (in_array($guardStatus, ['paused', 'warned'], true)): ?>
                            <div class="alert <?php echo $guardStatus === 'paused' ? 'alert-danger' : 'alert-warning'; ?>">
                                <strong><?php echo $guardStatus === 'paused' ? 'Angehalten' : 'Lauf ausgelassen'; ?> am <?php echo htmlspecialchars((string) ($stockGuardState['at'] ?? '?')); ?>:</strong>
                                <ul class="mb-0">
                                    <?php foreach ((array) ($stockGuardState['reason'] ?? []) as $guardReason): ?>
                                        <li><?php echo htmlspecialchars((string) $guardReason); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if (!empty($stockGuardState['examples'])): ?>
                                    <div class="mt-2 small">Beispiele (bisher → geplant): <?php echo htmlspecialchars(implode(' · ', (array) $stockGuardState['examples'])); ?></div>
                                <?php endif; ?>
                            </div>
                            <form method="post" onsubmit="return confirm('Sind die Änderungen gewollt (z. B. Inventur)? Der nächste Lauf schreibt dann ohne Prüfung und setzt den Vergleichsstand neu.');">
                                <input type="hidden" name="action" value="release_stock_guard">
                                <button type="submit" class="btn btn-primary">Änderungen sind gewollt – ausführen</button>
                                <span class="text-secondary ms-2">Sonst zuerst die Ursache in der Quelle beheben und danach freigeben.</span>
                            </form>
                        <?php endif; ?>
                        <div class="text-secondary small mt-2">
                            Vergleichsstand: <?php echo isset($stockGuardState['last_count']) ? (int) $stockGuardState['last_count'] . ' Artikel in der Quelle, ' . htmlspecialchars((string) ($stockGuardState['last_zero_share'] ?? '–')) . ' % mit Bestand 0 (' . htmlspecialchars((string) ($stockGuardState['baseline_at'] ?? '')) . ')' : 'noch keiner – wird beim nächsten Lauf gesetzt'; ?>.
                            <?php if ((int) ($stockGuardState['fail_streak'] ?? 0) > 0): ?>
                                <br>Fehlgeschlagene Läufe in Folge: <?php echo (int) $stockGuardState['fail_streak']; ?><?php echo !empty($stockGuardState['fail_notified']) ? ' (Mail verschickt)' : ' (Mail ab 3)'; ?>.
                                <?php if (!empty($stockGuardState['last_error'])): ?>Letzter Fehler: <?php echo htmlspecialchars((string) $stockGuardState['last_error']); ?><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Automatischer Produktimport (Admin-only)</h3>
                </div>
                <div class="card-body">
                    <p class="text-secondary">
                        Legt fehlende Produkte automatisch in <?php echo htmlspecialchars($targetName); ?> an (Shopify → <?php echo htmlspecialchars($targetName); ?>) --
                        dieselbe Logik wie der „Importieren"-Button auf der Merchant-Seite „Produkte", nur ohne
                        manuellen Klick. Der Merchant kann das nicht selbst umschalten -- bewusst eine Admin-
                        Entscheidung je Shop, bei Bedarf zu aktivieren.
                    </p>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="save_auto_import_settings">
                        <div class="col-12">
                            <label class="form-check">
                                <input type="checkbox" name="auto_import_missing_to_target" class="form-check-input" <?php echo ((int) $shopSettings['auto_import_missing_to_target']) === 1 ? 'checked' : ''; ?>>
                                <span class="form-check-label">Fehlende Produkte automatisch in <?php echo htmlspecialchars($targetName); ?> anlegen</span>
                            </label>
                            <small class="text-secondary d-block">Braucht eine verbundene Software (siehe Einstellungen in der Merchant-Vorschau). Läuft im Hintergrund, max. 20 Produkte pro Lauf.</small>
                        </div>
                        <?php if (count($shopifyProductFieldsForFilter) > 0): ?>
                            <div class="col-12 mt-2">
                                <label class="form-label">Filter (optional)</label>
                                <p class="text-secondary mb-2">
                                    Nur Produkte importieren, bei denen das gewählte Shopify-Feld den angegebenen Text
                                    enthält (Teilstring, Groß-/Kleinschreibung egal) — z. B. „Produktart“ enthält „AT-01“.
                                    Beide Felder leer lassen, um alle fehlenden Produkte zu importieren.
                                </p>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <select name="auto_import_filter_field_key" class="form-select">
                                            <option value="">-- kein Filter --</option>
                                            <?php foreach ($shopifyProductFieldsForFilter as $field): ?>
                                                <option value="<?php echo htmlspecialchars($field['field_key']); ?>" <?php echo $shopSettings['auto_import_filter_field_key'] === $field['field_key'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($field['label']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <input type="text" name="auto_import_filter_value" class="form-control" placeholder="z. B. AT-01" value="<?php echo htmlspecialchars((string) ($shopSettings['auto_import_filter_value'] ?? '')); ?>">
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="col-12 mt-2">
                            <button type="submit" class="btn btn-primary">Speichern</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php $stockBack = \Connect\Setup\StockBackSettings::state($pdo, $shop); ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Rückbuchung bei Kassenverkauf (Admin)</h3>
                </div>
                <div class="card-body">
                    <?php if ($stockBack === null): ?>
                        <div class="alert alert-warning mb-0">Migration 0104 ist noch nicht eingespielt.</div>
                    <?php else: ?>
                        <p class="text-secondary">
                            Neue Kassenverkäufe werden in Shopify nachgetragen (Bestand der verkauften Artikel), damit der
                            Lagerabgleich sie nicht überschreibt. Nur bei „Shopify führt“, nur wenn der Shop in Connect live ist,
                            Lauf alle <?php echo (int) \Connect\Setup\ShopActivations::STOCK_BACK_MINUTES; ?> Minuten.
                            Die <strong>Dummy-Bestellung</strong> legt zusätzlich je Kassenrechnung eine Bestellung in Shopify an
                            (Notiz „POS helloCash #Nr“) – echte Bestellungen im Shop des Händlers, deshalb nur hier schaltbar.
                        </p>
                        <?php if (!$stockBack['available']): ?>
                            <div class="alert alert-warning">Im Connector Builder fehlt das Object „<?php echo htmlspecialchars(\Connect\Sync\StockBackRunner::INVOICE_OBJECT); ?>“ für diese Software (read_list + read_one für GET /invoices) – die Rückbuchung lässt sich erst danach einschalten.</div>
                        <?php elseif ($stockBack['direction'] !== 'shopify_to_target'): ?>
                            <div class="alert alert-info">Richtung ist „Kassensystem führt“ – die Rückbuchung gilt nur bei „Shopify führt“.</div>
                        <?php endif; ?>
                        <form method="post" class="row g-2">
                            <input type="hidden" name="action" value="save_stock_back_settings">
                            <div class="col-12">
                                <label class="form-check">
                                    <input type="checkbox" name="stock_back_enabled" class="form-check-input" <?php echo $stockBack['enabled'] ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Kassenverkäufe in Shopify zurückbuchen</span>
                                </label>
                                <?php if ($stockBack['enabled'] && $stockBack['since'] !== null): ?>
                                    <small class="text-secondary d-block">Verarbeitet werden Kassenrechnungen ab <?php echo htmlspecialchars($stockBack['since']); ?>.</small>
                                <?php endif; ?>
                            </div>
                            <div class="col-12">
                                <label class="form-check">
                                    <input type="checkbox" name="stock_back_dummy_order" class="form-check-input" <?php echo $stockBack['dummy'] ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Zusätzlich Dummy-Bestellung in Shopify anlegen</span>
                                </label>
                            </div>
                            <div class="col-12 mt-2">
                                <button type="submit" class="btn btn-primary">Speichern</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Ausverkaufte Produkte ausblenden (Admin-only)</h3>
                </div>
                <div class="card-body">
                    <?php if ($visibilitySettings === null): ?>
                        <div class="alert alert-warning mb-0">Migration 0073 ist noch nicht eingespielt.</div>
                    <?php else: ?>
                        <p class="text-secondary">
                            Setzt Shopify-Produkte, deren Varianten alle keinen Lagerbestand mehr haben, auf „Entwurf“
                            und aktiviert sie wieder, sobald Bestand da ist. Bestand = das als Lagerbestand markierte
                            Shopify-Feld (ggf. am gewählten Standort). Archivierte Produkte bleiben unberührt.
                            Läuft stündlich, max. 20 Produkte je Richtung und Lauf, nur wenn der Shop in Connect live ist.
                        </p>
                        <form method="post" class="row g-2">
                            <input type="hidden" name="action" value="save_visibility_settings">
                            <div class="col-12">
                                <label class="form-check">
                                    <input type="checkbox" id="visibility-enabled" name="visibility_sync_enabled" class="form-check-input" onchange="document.getElementById('visibility-options').hidden = !this.checked" <?php echo ((int) $visibilitySettings['visibility_sync_enabled']) === 1 ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Ausverkaufte Produkte ausblenden / wieder einblenden</span>
                                </label>
                            </div>
                            <!-- Unteroptionen erst zeigen, wenn eingeschaltet -- sie wirken sonst nicht. Versteckt
                                 werden sie trotzdem mitgesendet, die gespeicherten Werte bleiben also erhalten. -->
                            <div id="visibility-options" class="row g-2 m-0 p-0" <?php echo ((int) $visibilitySettings['visibility_sync_enabled']) === 1 ? '' : 'hidden'; ?>>
                            <div class="col-12">
                                <label class="form-check">
                                    <input type="checkbox" name="visibility_only_matched" class="form-check-input" <?php echo ((int) $visibilitySettings['visibility_only_matched']) === 1 ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Nur Produkte, die in <?php echo htmlspecialchars($targetName); ?> zugeordnet sind (Vergleichswert)</span>
                                </label>
                            </div>
                            <div class="col-12">
                                <label class="form-check">
                                    <input type="checkbox" name="visibility_single_variant_only" class="form-check-input" <?php echo ((int) $visibilitySettings['visibility_single_variant_only']) === 1 ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Nur Produkte mit genau einer Variante ausblenden</span>
                                </label>
                            </div>
                            <div class="col-12">
                                <label class="form-check">
                                    <input type="checkbox" name="visibility_require_description" class="form-check-input" <?php echo ((int) $visibilitySettings['visibility_require_description']) === 1 ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Nur wieder aktivieren, wenn eine Beschreibung vorhanden ist</span>
                                </label>
                            </div>
                            <div class="col-md-6 mt-2">
                                <label class="form-label">Welche Entwürfe wieder aktivieren?</label>
                                <select name="visibility_reactivate_mode" class="form-select">
                                    <option value="own" <?php echo $visibilitySettings['visibility_reactivate_mode'] === 'own' ? 'selected' : ''; ?>>Nur von Connect ausgeblendete</option>
                                    <option value="all" <?php echo $visibilitySettings['visibility_reactivate_mode'] === 'all' ? 'selected' : ''; ?>>Alle Entwürfe mit Bestand (wie alte Welt)</option>
                                    <option value="never" <?php echo $visibilitySettings['visibility_reactivate_mode'] === 'never' ? 'selected' : ''; ?>>Nie (nur ausblenden)</option>
                                </select>
                                <small class="text-secondary d-block">„Nur von Connect ausgeblendete“ lässt Entwürfe, die der Merchant selbst angelegt hat, in Ruhe. Beim Umzug aus der alten Welt kennt Connect deren Ausblendungen aber nicht – dann „Alle Entwürfe“ wählen.</small>
                            </div>
                            </div>
                            <div class="col-12 mt-2">
                                <button type="submit" class="btn btn-primary">Speichern</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>

        <?php if ($targetConnectionLabel === null && count($targetConnectorTypes) > 0): ?>
            <!-- Einrichtungswizard: erscheint automatisch, solange der Merchant
             noch kein Zielsystem verbunden hat. Schritt 1 System waehlen,
             Schritt 2 Zugangsdaten -- ein Formular, per JS in zwei
             Abschnitte aufgeteilt (gleiches Prinzip wie showAuthFields() in
             _connections_body.php, nur zusaetzlich mit Schritt-Wechsel). -->
            <div class="modal modal-blur" id="target-system-wizard" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Software verbinden</h5>
                        </div>
                        <form method="post">
                            <input type="hidden" name="action" value="connect_target_system">
                            <div class="modal-body">
                                <?php if ($flashMessage !== null): ?>
                                    <!-- Das Modal deckt den normalen Flash-Bereich der Seite ab
                                     (siehe _header.php) -- Meldung deshalb hier zusaetzlich
                                     anzeigen, sonst sieht der Merchant Fehler nie. -->
                                    <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>">
                                        <?php echo htmlspecialchars($flashMessage); ?>
                                    </div>
                                <?php endif; ?>
                                <div id="wizard-step-1">
                                    <p class="text-secondary">Mit welcher Software soll dein Shopify-Lagerbestand abgeglichen werden?</p>
                                    <?php foreach ($targetConnectorTypes as $type): ?>
                                        <label class="form-selectgroup-item d-block mb-2">
                                            <input type="radio" name="connector_type_id" value="<?php echo (int) $type['id']; ?>" class="form-selectgroup-input" onchange="wizardShowStep2(<?php echo (int) $type['id']; ?>)" required>
                                            <span class="form-selectgroup-label d-block p-2"><?php echo htmlspecialchars($type['name']); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div id="wizard-step-2" style="display:none">
                                    <p class="text-secondary">Zugangsdaten für <strong id="wizard-step-2-type-name"></strong>:</p>
                                    <?php foreach ($targetConnectorTypes as $type): ?>
                                        <?php $authFields = json_decode((string) $type['auth_field_schema'], true) ?: []; ?>
                                        <div id="wizard-fields-<?php echo (int) $type['id']; ?>" class="wizard-fields" style="display:none">
                                            <?php foreach ($authFields as $field): ?>
                                                <?php if (($field['type'] ?? 'text') === 'hidden'): ?>
                                                    <input type="hidden" name="credentials[<?php echo htmlspecialchars($field['key']); ?>]" value="<?php echo htmlspecialchars($field['value'] ?? ''); ?>">
                                                <?php else: ?>
                                                    <div class="mb-3">
                                                        <label class="form-label"><?php echo htmlspecialchars($field['label']); ?></label>
                                                        <input
                                                            type="<?php echo htmlspecialchars($field['type'] ?? 'text'); ?>"
                                                            name="credentials[<?php echo htmlspecialchars($field['key']); ?>]"
                                                            class="form-control"
                                                            placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                                            <?php echo !empty($field['required']) ? 'required' : ''; ?>
                                                            disabled>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <button type="button" class="btn btn-link px-0" onclick="wizardShowStep1()">&larr; Zurück</button>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="submit" class="btn btn-primary" id="wizard-submit" style="display:none">Verbinden</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <script>
                var wizardTypeNames = <?php echo json_encode(array_column($targetConnectorTypes, 'name', 'id')); ?>;

                function wizardShowStep2(typeId) {
                    document.getElementById('wizard-step-1').style.display = 'none';
                    document.getElementById('wizard-step-2').style.display = 'block';
                    document.getElementById('wizard-step-2-type-name').textContent = wizardTypeNames[typeId] || '';
                    document.querySelectorAll('.wizard-fields').forEach(function(el) {
                        el.style.display = 'none';
                        el.querySelectorAll('input').forEach(function(input) {
                            input.disabled = true;
                        });
                    });
                    var target = document.getElementById('wizard-fields-' + typeId);
                    if (target) {
                        target.style.display = 'block';
                        target.querySelectorAll('input').forEach(function(input) {
                            input.disabled = false;
                        });
                    }
                    document.getElementById('wizard-submit').style.display = 'inline-block';
                }

                function wizardShowStep1() {
                    document.getElementById('wizard-step-1').style.display = 'block';
                    document.getElementById('wizard-step-2').style.display = 'none';
                    document.getElementById('wizard-submit').style.display = 'none';
                }

                // Kein Bootstrap-JS im vendorten Tabler-Bundle enthalten (nur CSS +
                // eigenes Tabler-Verhalten, kein Modal-Component) -- Anzeige daher
                // von Hand statt ueber bootstrap.Modal(). Kein Schliessen-Mechanismus
                // noetig, das Modal bleibt bewusst offen, bis der Wizard abgeschickt wird.
                document.addEventListener('DOMContentLoaded', function() {
                    var modalEl = document.getElementById('target-system-wizard');
                    if (!modalEl) {
                        return;
                    }
                    modalEl.style.display = 'block';
                    modalEl.classList.add('show');
                    document.body.classList.add('modal-open');
                    var backdrop = document.createElement('div');
                    backdrop.className = 'modal-backdrop show';
                    document.body.appendChild(backdrop);
                });
            </script>
        <?php endif; ?>

        <div class="col-12">
            <div class="text-secondary small d-flex align-items-center gap-3 flex-wrap">
                <span>Was wann im Hintergrund läuft, siehst du unter <a href="status.php?shop=<?php echo urlencode($shop); ?><?php echo isset($_GET['preview']) ? '&amp;preview=1' : ''; ?>">Status</a>.</span>
                <form method="post" class="m-0">
                    <input type="hidden" name="action" value="refresh_master_data">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Lädt Standorte, Mitarbeiter und Zahlungsarten sofort neu. Sonst passiert das einmal am Tag.">Daten aktualisieren</button>
                </form>
                <span>Standorte, Mitarbeiter und Zahlungsarten werden sonst einmal am Tag neu geladen – hast du gerade etwas in <?php echo htmlspecialchars((string) $targetName); ?> ergänzt, hier sofort aktualisieren.</span>
            </div>
        </div>

        <div class="col-12 setup-anchor" id="setup-comparison">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Produkte zuordnen</h3>
                </div>
                <div class="card-body">
                    <p class="text-secondary">
                        Woran erkennt oView, welches Shopify-Produkt zu welchem Artikel in <?php echo htmlspecialchars($targetName); ?> gehört?
                        Nimm das, was auf beiden Seiten gleich eingetragen ist.
                    </p>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="save_comparison_key">
                        <div class="col-12">
                            <?php foreach ([
                                'barcode' => ['Barcode (EAN)', 'am zuverlässigsten, wenn deine Produkte Barcodes haben'],
                                'sku' => ['Artikelnummer (SKU)', 'gut, wenn du überall dieselben Artikelnummern verwendest'],
                                'title' => ['Produktname', 'nur, wenn die Namen auf beiden Seiten exakt gleich geschrieben sind'],
                            ] as $value => [$label, $hint]): ?>
                                <label class="form-check">
                                    <input type="radio" name="comparison_key" class="form-check-input" value="<?php echo $value; ?>" <?php echo $shopSettings['comparison_key'] === $value ? 'checked' : ''; ?>>
                                    <span class="form-check-label"><?php echo $label; ?> <span class="text-secondary small">– <?php echo $hint; ?></span></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Speichern</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 setup-anchor" id="setup-stock">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Lagerbestand</h3>
                </div>
                <div class="card-body">
                    <?php if ($targetConnectionLabel === null): ?>
                        <div class="alert alert-info">
                            <?php echo count($targetConnectorTypes) > 0 ? 'Noch nicht eingerichtet — bitte im Einrichtungswizard eine Software verbinden.' : 'Noch nicht eingerichtet, bitte kontaktiere uns.'; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-secondary">
                            oView hält den Bestand in Shopify und <?php echo htmlspecialchars($targetName); ?> gleich.
                            <?php if ((int) $shopSettings['stock_sync_enabled'] === 1): ?>
                                <span class="badge bg-green-lt ms-1">läuft</span>
                            <?php endif; ?>
                        </p>
                        <form method="post" class="row g-2">
                            <input type="hidden" name="action" value="save_stock_settings">
                            <div class="col-12">
                                <label class="form-label">Wo pflegst du deinen Bestand?</label>
                                <label class="form-check">
                                    <input type="radio" name="stock_sync_direction" class="form-check-input" value="shopify_to_target" <?php echo $shopSettings['stock_sync_direction'] === 'shopify_to_target' ? 'checked' : ''; ?>>
                                    <span class="form-check-label">In Shopify <span class="text-secondary small">– <?php echo htmlspecialchars($targetName); ?> übernimmt den Bestand aus Shopify</span></span>
                                </label>
                                <label class="form-check">
                                    <input type="radio" name="stock_sync_direction" class="form-check-input" value="target_to_shopify" <?php echo $shopSettings['stock_sync_direction'] === 'target_to_shopify' ? 'checked' : ''; ?>>
                                    <span class="form-check-label">In <?php echo htmlspecialchars($targetName); ?> <span class="text-secondary small">– Shopify übernimmt den Bestand aus <?php echo htmlspecialchars($targetName); ?></span></span>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Lager-Standort in Shopify</label>
                                <select name="stock_primary_location_id" class="form-select">
                                    <option value="">-- auswählen --</option>
                                    <?php foreach ($shopifyLocations as $location): ?>
                                        <option value="<?php echo htmlspecialchars((string) ($location['shopify_id'] ?? '')); ?>" <?php echo $shopSettings['stock_primary_location_id'] === ($location['shopify_id'] ?? null) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string) ($location['name'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (count($shopifyLocations) === 0): ?>
                                    <small class="text-secondary">Die Standorte werden gerade aus Shopify geladen – schau in ein paar Minuten wieder vorbei.</small>
                                <?php else: ?>
                                    <small class="text-secondary">Der Bestand dieses Standorts wird abgeglichen.</small>
                                <?php endif; ?>
                            </div>
                            <?php $stockRules = \Connect\Sync\ShopStockRules::forShop($pdo, $shop); ?>
                            <div class="col-md-6">
                                <label class="form-label" for="stock-safety-buffer">Reserve für den Laden</label>
                                <div class="input-group">
                                    <input id="stock-safety-buffer" type="number" min="0" max="999" name="stock_safety_buffer" class="form-control" value="<?php echo (int) $stockRules['buffer']; ?>">
                                    <span class="input-group-text">Stück</span>
                                </div>
                                <small class="text-secondary">So viele Stück hältst du online zurück, damit nichts doppelt verkauft wird. Beispiel: 5 im Lager, Reserve 1 → online sind 4 kaufbar. 0 = keine Reserve. Gilt nur, wenn du den Bestand in <?php echo htmlspecialchars($targetName); ?> pflegst.</small>
                            </div>
                            <?php $stockBackMerchant = \Connect\Setup\StockBackSettings::state($pdo, $shop); ?>
                            <?php if ($stockBackMerchant !== null && ($stockBackMerchant['available'] || $stockBackMerchant['enabled'])): ?>
                                <div class="col-12">
                                    <input type="hidden" name="stock_back_present" value="1">
                                    <label class="form-check">
                                        <input type="checkbox" name="stock_back_enabled" class="form-check-input" <?php echo $stockBackMerchant['enabled'] ? 'checked' : ''; ?>>
                                        <span class="form-check-label">Verkäufe an der Kasse in Shopify zurückbuchen</span>
                                    </label>
                                    <small class="text-secondary d-block">Nur wenn du den Bestand in Shopify pflegst: verkaufst du etwas an der Kasse, trägt oView den neuen Kassenbestand in Shopify nach. Sonst würde der nächste Abgleich den Verkauf wieder überschreiben. Gilt für Verkäufe ab dem Einschalten.</small>
                                </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-check">
                                    <input type="checkbox" name="stock_sync_enabled" class="form-check-input" <?php echo ((int) $shopSettings['stock_sync_enabled']) === 1 ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Bestand automatisch abgleichen</span>
                                </label>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">Speichern</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($anyConnectionSupportsVariants): ?>
            <div class="col-12 setup-anchor" id="setup-variants">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Varianten (Größen, Farben …)</h3>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary">
                            Kennt <?php echo htmlspecialchars($targetName); ?> keine Varianten, ist dort jede Größe oder Farbe ein eigener Artikel.
                            Beim Übernehmen nach Shopify kann oView solche Artikel als Varianten eines bestehenden Produkts anlegen,
                            statt als einzelne neue Produkte. Dazu wählst du, woran oView die Zusammengehörigkeit erkennt –
                            z. B. gleiche Artikelnummer (SKU), während jede Größe einen eigenen Barcode hat.
                        </p>
                        <form method="post" class="row g-2">
                            <input type="hidden" name="action" value="save_variant_comparison_key">
                            <div class="col-md-6">
                                <label class="form-label">Zusammengehörige Artikel erkennen an</label>
                                <select name="variant_comparison_key" class="form-select">
                                    <option value="">-- nicht verwenden, immer als eigenes Produkt anlegen --</option>
                                    <?php foreach (['title' => 'Produktname', 'sku' => 'Artikelnummer (SKU)', 'barcode' => 'Barcode (EAN)'] as $value => $label): ?>
                                        <?php if ($value === $shopSettings['comparison_key']) continue; ?>
                                        <option value="<?php echo $value; ?>" <?php echo $shopSettings['variant_comparison_key'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">Speichern</button>
                            </div>
                        </form>
                        <?php if ($shopSettings['variant_comparison_key'] !== null): ?>
                            <hr>
                            <p class="text-secondary mb-2">
                                Bezeichnung der Auswahl in Shopify, z. B. „Größe“ oder „Farbe“. Wird nur verwendet, wenn das
                                Produkt noch keine eigene hat. Den Wert (z. B. „M“ oder „42“) gibst du beim Übernehmen auf der
                                Produkte-Seite an.
                            </p>
                            <form method="post" class="row g-2">
                                <input type="hidden" name="action" value="save_variant_option_name">
                                <div class="col-md-6">
                                    <input type="text" name="variant_option_name" class="form-control" value="<?php echo htmlspecialchars((string) $shopSettings['variant_option_name']); ?>" placeholder="Option">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">Speichern</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-12 setup-anchor" id="setup-prices">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Preise und Steuer</h3>
                </div>
                <div class="card-body">
                    <p class="text-secondary">
                        Sind deine Preise in Shopify mit oder ohne Mehrwertsteuer eingetragen? oView braucht das, um sie
                        richtig mit <?php echo htmlspecialchars($targetName); ?> zu vergleichen und zu übertragen.
                    </p>
                    <?php $priceTaxMode = $shopSettings['shopify_prices_include_tax'] !== null ? (int) $shopSettings['shopify_prices_include_tax'] : null; ?>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="save_price_tax_mode">
                        <div class="col-md-6">
                            <select name="shopify_prices_include_tax" class="form-select">
                                <option value="">-- nicht festgelegt (gilt als inkl. MwSt.) --</option>
                                <option value="1" <?php echo $priceTaxMode === 1 ? 'selected' : ''; ?>>inkl. MwSt. (brutto)</option>
                                <option value="0" <?php echo $priceTaxMode === 0 ? 'selected' : ''; ?>>ohne MwSt. (netto)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Speichern</button>
                        </div>
                    </form>
                    <form method="post" class="mt-2">
                        <input type="hidden" name="action" value="refresh_price_tax_mode_from_shopify">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Wie in Shopify eingestellt</button>
                        <span class="text-secondary small">übernimmt deine Steuer-Einstellung aus Shopify</span>
                    </form>
                    <hr>
                    <label class="form-label">Üblicher Steuersatz</label>
                    <p class="text-secondary small mb-2">
                        Wird beim Übernehmen neuer Produkte vorausgefüllt und kann pro Produkt geändert werden (z. B. für ermäßigte Sätze).
                        Leer lassen, wenn nichts vorausgefüllt werden soll.
                    </p>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="save_default_tax_rate">
                        <div class="col-md-6">
                            <div class="input-group">
                                <input type="text" name="default_tax_rate" class="form-control" value="<?php echo htmlspecialchars((string) ($shopSettings['default_tax_rate'] ?? '')); ?>" placeholder="z. B. 20">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Speichern</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 setup-anchor" id="setup-invoice">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Rechnungen / Aufträge</h3>
                </div>
                <div class="card-body">
                    <p class="text-secondary">
                        Für jede bezahlte Shopify-Bestellung legt oView automatisch eine Rechnung in <?php echo htmlspecialchars($targetName); ?> an.
                        Den Kunden sucht oView dort über die E-Mail-Adresse oder den Namen und legt ihn bei Bedarf neu an.
                    </p>
                    <form method="post" class="row g-2" autocomplete="off">
                        <input type="hidden" name="action" value="save_invoice_settings">
                        <div class="col-12">
                            <label class="form-check">
                                <input type="checkbox" name="invoice_creation_enabled" class="form-check-input" <?php echo ((int) $shopSettings['invoice_creation_enabled']) === 1 ? 'checked' : ''; ?>>
                                <span class="form-check-label">Rechnungen automatisch anlegen</span>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-check">
                                <input type="checkbox" name="invoice_pos_only" class="form-check-input" <?php echo ((int) $shopSettings['invoice_pos_only']) === 1 ? 'checked' : ''; ?>>
                                <span class="form-check-label">Nur Kassenbestellungen (Shopify POS) berücksichtigen</span>
                            </label>
                            <small class="text-secondary d-block">Online-Bestellungen werden dann ignoriert — z. B. wenn diese anderweitig abgerechnet werden.</small>
                        </div>
                        <?php if ($shopSettings['invoice_signature_mandatory'] !== null): ?>
                            <div class="col-12">
                                <label class="form-check">
                                    <input type="checkbox" name="invoice_signature_mandatory" class="form-check-input" <?php echo ((int) $shopSettings['invoice_signature_mandatory']) === 1 ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Rechnungen signieren (Registrierkassenpflicht/RKSV)</span>
                                </label>
                                <small class="text-secondary d-block">Rechnungen werden in <?php echo htmlspecialchars($targetName); ?> mit Signatur der Registrierkasse erstellt. Frag im Zweifel deine Steuerberatung.</small>
                            </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-check">
                                <input type="checkbox" name="invoice_suppress_and_adjust_stock" class="form-check-input" id="invoice-suppress-toggle" <?php echo ((int) $shopSettings['invoice_suppress_and_adjust_stock']) === 1 ? 'checked' : ''; ?> onchange="document.getElementById('invoice-normal-fields').hidden = this.checked; if (this.checked) { document.getElementById('invoice-mail-toggle').checked = false; document.getElementById('invoice-mail-fields').hidden = true; }">
                                <span class="form-check-label">Keine Rechnung erstellen, nur den Bestand abbuchen</span>
                            </label>
                            <small class="text-secondary d-block">Wenn du die Rechnung anderswo erstellst: oView bucht dann nur die verkauften Stück in <?php echo htmlspecialchars($targetName); ?> ab. Kassierer, Zahlungsart und Rechnungsmail fallen weg.</small>
                        </div>
                        <div id="invoice-normal-fields" class="row g-2" <?php echo ((int) $shopSettings['invoice_suppress_and_adjust_stock']) === 1 ? 'hidden' : ''; ?>>
                        <div class="col-md-4">
                            <label class="form-label">Rechnung erstellt von</label>
                            <select name="invoice_cashier_id" class="form-select">
                                <option value="">-- keiner --</option>
                                <?php foreach ($helloCashEmployees as $employee): ?>
                                    <option value="<?php echo (int) ($employee['id'] ?? 0); ?>" <?php echo ((string) ($shopSettings['invoice_cashier_id'] ?? '')) === (string) ($employee['id'] ?? '') ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) ($employee['name'] ?? '')); ?><?php echo (($employee['rank'] ?? '') !== '') ? ' (' . htmlspecialchars((string) $employee['rank']) . ')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (count($helloCashEmployees) === 0): ?>
                                <small class="text-secondary">Die Mitarbeiter werden gerade aus <?php echo htmlspecialchars($targetName); ?> geladen – schau in ein paar Minuten wieder vorbei.</small>
                            <?php else: ?>
                                <small class="text-secondary">Mitarbeiter, unter dem die Rechnung in <?php echo htmlspecialchars($targetName); ?> erscheint.</small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Zahlungsart</label>
                            <?php $currentPaymentMethod = (string) $shopSettings['invoice_payment_method']; ?>
                            <?php if (count($helloCashPaymentMethods) > 0): ?>
                                <select name="invoice_payment_method" class="form-select">
                                    <?php if ($currentPaymentMethod !== '' && !isset($helloCashPaymentMethods[$currentPaymentMethod])): ?>
                                        <option value="<?php echo htmlspecialchars($currentPaymentMethod); ?>" selected>&#9888; <?php echo htmlspecialchars($currentPaymentMethod); ?> (in <?php echo htmlspecialchars($targetName); ?> nicht gefunden)</option>
                                    <?php endif; ?>
                                    <?php foreach ($helloCashPaymentMethods as $pmName): ?>
                                        <option value="<?php echo htmlspecialchars($pmName); ?>" <?php echo $pmName === $currentPaymentMethod ? 'selected' : ''; ?>><?php echo htmlspecialchars($pmName); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($currentPaymentMethod !== '' && !isset($helloCashPaymentMethods[$currentPaymentMethod])): ?>
                                    <small class="text-danger">Diese Zahlungsart gibt es in <?php echo htmlspecialchars($targetName); ?> nicht (oder sie ist deaktiviert) – Rechnungen schlagen fehl. Bitte eine andere wählen.</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <input type="text" name="invoice_payment_method" class="form-control" value="<?php echo htmlspecialchars($currentPaymentMethod); ?>" placeholder="z. B. Webshop">
                                <small class="text-secondary">Muss genau so in <?php echo htmlspecialchars($targetName); ?> als aktive Zahlungsart existieren.</small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Bei Problemen Mail an</label>
                            <div class="form-control-plaintext small">an <?php echo htmlspecialchars($notifySettings['effective'] ?? 'office@oview.at'); ?> · <a href="#setup-notify">ändern</a></div>
                        </div>
                        <?php if ($shopSettings['invoice_customer_match'] !== null): ?>
                            <div class="col-md-8">
                                <label class="form-label">Kunden zuordnen über</label>
                                <select name="invoice_customer_match" class="form-select">
                                    <?php foreach (\Connect\Sync\InvoiceService::CUSTOMER_MATCH_LABELS as $matchKey => $matchLabel): ?>
                                        <option value="<?php echo htmlspecialchars($matchKey); ?>" <?php echo $shopSettings['invoice_customer_match'] === $matchKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($matchLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-secondary">So sucht oView den Kunden in <?php echo htmlspecialchars($targetName); ?>. Nicht gefunden = neuer Kunde. Bei „Name und Adresse“ müssen Name, PLZ, Ort und Straße übereinstimmen. Bei „Nur E-Mail-Adresse“ bekommen Bestellungen ohne E-Mail-Adresse keinen Kunden.</small>
                            </div>
                        <?php endif; ?>

                        <div class="col-12 mt-3">
                            <label class="form-check">
                                <input type="checkbox" name="invoice_mail_enabled" class="form-check-input" id="invoice-mail-toggle" <?php echo ((int) $shopSettings['invoice_mail_enabled']) === 1 ? 'checked' : ''; ?> onchange="document.getElementById('invoice-mail-fields').hidden = !this.checked">
                                <span class="form-check-label">Rechnung automatisch per E-Mail an den Kunden senden</span>
                            </label>
                        </div>
                        <div id="invoice-mail-fields" class="row g-2" <?php echo ((int) $shopSettings['invoice_mail_enabled']) === 1 ? '' : 'hidden'; ?>>
                            <div class="col-md-6">
                                <label class="form-label">Absendername</label>
                                <input type="text" name="invoice_mail_sender_name" class="form-control" value="<?php echo htmlspecialchars((string) ($shopSettings['invoice_mail_sender_name'] ?? '')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Absender-/Antwortadresse</label>
                                <input type="email" name="invoice_mail_sender_address" class="form-control" value="<?php echo htmlspecialchars((string) ($shopSettings['invoice_mail_sender_address'] ?? '')); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Betreff</label>
                                <input type="text" name="invoice_mail_subject" class="form-control" value="<?php echo htmlspecialchars((string) ($shopSettings['invoice_mail_subject'] ?? '')); ?>" placeholder="z. B. Ihre Rechnung zu Bestellung %BESTELLUNG%">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Nachricht</label>
                                <textarea rows="6" name="invoice_mail_message" class="form-control"><?php echo htmlspecialchars((string) ($shopSettings['invoice_mail_message'] ?? '')); ?></textarea>
                                <small class="text-secondary">Platzhalter: %VORNAME%, %NACHNAME%, %BESTELLUNG%, %DATUM%.</small>
                            </div>
                            <div class="col-12">
                                <details>
                                    <summary class="text-secondary" style="cursor:pointer;">Eigenes SMTP-Konto verwenden (optional)</summary>
                                    <?php if (!$ownSmtpAllowed): ?>
                                        <div class="alert alert-info mt-2 mb-0">
                                            Mit deinem Abo werden Rechnungen über die oview.at-Adresse versendet. Eigene SMTP-Adresse gibt es ab
                                            <strong>OVIEW HELLOCASH +</strong>. <a href="billing.php?shop=<?php echo urlencode($shop); ?><?php echo isset($_GET['preview']) ? '&amp;preview=1' : ''; ?>">Abo ansehen</a>
                                        </div>
                                    <?php endif; ?>
                                    <div class="row g-2 mt-1" <?php echo $ownSmtpAllowed ? '' : 'hidden'; ?>>
                                        <p class="text-secondary">
                                            Damit kommen die Rechnungsmails von deiner eigenen Adresse. Die Zugangsdaten bekommst du von deinem
                                            E-Mail-Anbieter. Klappt der Versand darüber einmal nicht, schickt oView die Mail automatisch über das oView-Postfach.
                                        </p>
                                        <div class="col-md-6">
                                            <label class="form-label">SMTP-Host</label>
                                            <input type="text" name="invoice_mail_smtp_host" class="form-control" value="<?php echo htmlspecialchars((string) ($shopSettings['invoice_mail_smtp_host'] ?? '')); ?>" placeholder="z. B. smtp.office365.com" autocomplete="off">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Port</label>
                                            <input type="number" name="invoice_mail_smtp_port" class="form-control" value="<?php echo htmlspecialchars((string) ($shopSettings['invoice_mail_smtp_port'] ?? '')); ?>" placeholder="587">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Verschlüsselung</label>
                                            <select name="invoice_mail_smtp_encryption" class="form-select">
                                                <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => 'Keine'] as $value => $label): ?>
                                                    <option value="<?php echo $value; ?>" <?php echo $shopSettings['invoice_mail_smtp_encryption'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">SMTP-Benutzername</label>
                                            <input type="text" name="invoice_mail_smtp_username" class="form-control" value="<?php echo htmlspecialchars((string) ($shopSettings['invoice_mail_smtp_username'] ?? '')); ?>" autocomplete="off">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">SMTP-Passwort</label>
                                            <input type="password" name="invoice_mail_smtp_password" class="form-control" placeholder="<?php echo $shopSettings['invoice_mail_smtp_password'] ? 'unverändert lassen' : ''; ?>" autocomplete="new-password">
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" name="test_smtp" value="1" class="btn btn-sm btn-outline-secondary">SMTP-Verbindung testen</button>
                                            <small class="text-secondary d-block mt-1">Prüft nur Verbindung &amp; Login mit den oben eingetragenen Daten (leeres Passwort = gespeichertes verwenden), es wird keine Mail versendet.</small>
                                        </div>
                                    </div>
                                </details>
                            </div>
                        </div>
                        </div>

                        <div class="col-12 mt-2">
                            <button type="submit" class="btn btn-primary">Speichern</button>
                        </div>
                    </form>

                    <?php if ($attachmentsSupported): ?>
                    <hr class="my-4">
                    <h4 class="mb-1"><?php echo \Connect\Ui\Icon::svg('files', 18, 'me-1'); ?>Anhänge an der Rechnungsmail</h4>
                    <p class="text-secondary small mb-2">Diese PDFs werden jeder Rechnungsmail zusätzlich angehängt – z. B. Widerrufsbelehrung (im Online-Handel muss sie dem Kunden in Textform vorliegen), AGB oder Pflegehinweise. Höchstens <?php echo \Connect\Mail\ShopAttachments::MAX_FILES; ?> Dateien zu je <?php echo round(\Connect\Mail\ShopAttachments::MAX_BYTES / 1000000, 1); ?> MB.</p>
                    <?php if (!$attachmentsAllowed): ?>
                        <div class="text-secondary small">Enthalten ab dem Abo <strong><?php echo htmlspecialchars(\Connect\Billing\Plans::lowestPlanFor('attachments')); ?></strong> – <a href="billing.php?shop=<?php echo urlencode($shop); ?>">Abo ansehen</a>.</div>
                    <?php else: ?>
                        <?php $attachmentList = $attachmentStore->list($shop); ?>
                        <?php if ($attachmentList !== []): ?>
                            <div class="list-group mb-2">
                                <?php foreach ($attachmentList as $attachment): ?>
                                    <div class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                                        <?php echo \Connect\Ui\Icon::svg('file-invoice', 18); ?>
                                        <span class="<?php echo $attachment['enabled'] ? '' : 'text-secondary text-decoration-line-through'; ?>"><?php echo htmlspecialchars($attachment['filename']); ?></span>
                                        <span class="text-secondary small"><?php echo round($attachment['size'] / 1000); ?> KB</span>
                                        <form method="post" class="m-0 ms-auto">
                                            <input type="hidden" name="action" value="toggle_attachment">
                                            <input type="hidden" name="attachment_id" value="<?php echo (int) $attachment['id']; ?>">
                                            <input type="hidden" name="enabled" value="<?php echo $attachment['enabled'] ? '0' : '1'; ?>">
                                            <button type="submit" class="btn btn-sm"><?php echo $attachment['enabled'] ? 'pausieren' : 'wieder anhängen'; ?></button>
                                        </form>
                                        <form method="post" class="m-0">
                                            <input type="hidden" name="action" value="remove_attachment">
                                            <input type="hidden" name="attachment_id" value="<?php echo (int) $attachment['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Anhang entfernen?')">Entfernen</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (count($attachmentList) < \Connect\Mail\ShopAttachments::MAX_FILES): ?>
                            <form method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap align-items-center">
                                <input type="hidden" name="action" value="upload_attachment">
                                <input type="file" name="attachment" accept="application/pdf,.pdf" class="form-control" style="max-width: 360px" required>
                                <button type="submit" class="btn btn-outline-primary">Hochladen</button>
                            </form>
                        <?php endif; ?>
                        <?php if ((int) $shopSettings['invoice_mail_enabled'] !== 1): ?>
                            <div class="small text-warning mt-2">Hinweis: Die Rechnungsmail ist oben ausgeschaltet – Anhänge werden erst verschickt, wenn sie aktiv ist.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 setup-anchor" id="setup-notify">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Benachrichtigungen</h3>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3" autocomplete="off">
                        <input type="hidden" name="action" value="save_notification_settings">
                        <div class="col-md-6">
                            <label class="form-label" for="notify-email">E-Mail-Adresse</label>
                            <input type="email" id="notify-email" name="notify_email" class="form-control"
                                value="<?php echo htmlspecialchars((string) ($notifySettings['custom'] ?? '')); ?>"
                                placeholder="<?php echo htmlspecialchars($notifySettings['shopify'] ?? 'name@beispiel.at'); ?>">
                            <small class="text-secondary">
                                <?php if ($notifySettings['custom'] === null && $notifySettings['shopify'] !== null): ?>
                                    Aus Shopify übernommen. Trag hier eine andere Adresse ein, wenn die Mails woanders hin sollen.
                                <?php elseif ($notifySettings['custom'] === null): ?>
                                    Shopify hat uns noch keine Adresse gemeldet. Bitte trag eine ein.
                                <?php else: ?>
                                    Leer lassen, um die Adresse aus Shopify zu verwenden<?php echo $notifySettings['shopify'] !== null ? ' (' . htmlspecialchars($notifySettings['shopify']) . ')' : ''; ?>.
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="col-12">
                            <label class="form-check">
                                <input type="checkbox" name="notify_problems" class="form-check-input" <?php echo $notifySettings['problems'] ? 'checked' : ''; ?>>
                                <span class="form-check-label">Probleme sofort melden</span>
                            </label>
                            <small class="text-secondary d-block">Zum Beispiel, wenn für eine bezahlte Bestellung keine Rechnung erstellt werden konnte. Eine Kopie geht an den oView-Support, damit wir dir helfen können.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-check">
                                <input type="checkbox" name="weekly_report_enabled" class="form-check-input" <?php echo $notifySettings['weekly'] ? 'checked' : ''; ?>>
                                <span class="form-check-label">Wochenbericht jeden Montag</span>
                            </label>
                            <small class="text-secondary d-block">Was oView in der vergangenen Woche erledigt hat: Rechnungen, Lagerabgleiche, offene Punkte. Kommt nur, wenn etwas passiert ist.</small>
                        </div>
                        <?php if ($lowStockThreshold !== null): ?>
                            <div class="col-12">
                                <label class="form-check">
                                    <input type="checkbox" name="low_stock_enabled" class="form-check-input" id="low-stock-toggle" <?php echo $lowStockThreshold > 0 ? 'checked' : ''; ?> onchange="document.getElementById('low-stock-fields').hidden = !this.checked">
                                    <span class="form-check-label">Mail, wenn der Lagerstand knapp wird</span>
                                </label>
                                <small class="text-secondary d-block">
                                    Einmal am Morgen eine Liste der Artikel, die neu unter die Grenze gefallen sind. Schon gemeldete Artikel
                                    kommen erst wieder, wenn sie aufgefüllt waren und erneut knapp werden.
                                    Maßgeblich ist der Bestand <?php echo $shopSettings['stock_sync_direction'] === 'target_to_shopify' ? 'in ' . htmlspecialchars($targetName) : 'in Shopify'; ?>.
                                </small>
                                <div id="low-stock-fields" class="mt-2" style="max-width: 280px" <?php echo $lowStockThreshold > 0 ? '' : 'hidden'; ?>>
                                    <label class="form-label" for="low-stock-threshold">Melden ab</label>
                                    <div class="input-group">
                                        <input type="number" min="1" max="9999" id="low-stock-threshold" name="low_stock_threshold" class="form-control" value="<?php echo $lowStockThreshold > 0 ? $lowStockThreshold : 2; ?>">
                                        <span class="input-group-text">Stück oder weniger</span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Speichern</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>