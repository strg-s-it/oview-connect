<?php

declare(strict_types=1);

/**
 * connect/public/app/_products_body.php
 *
 * Merchant-Ansicht "Produkte": Vergleichsübersicht zwischen Shopify- und
 * Ziel-Cache (Connect\Sync\ProductComparisonService, nutzt denselben
 * comparison_role/is_stock_quantity-Kontext wie der automatische
 * Lagerbestand-Abgleich, aber rein lesend). Filterleiste mit Zählern +
 * serverseitige Paginierung, plus Checkbox-Mehrfachauswahl mit einem
 * gemeinsamen "Importieren"-Button für Zeilen mit Status
 * missing_on_shopify/missing_on_target (siehe Connect\Sync\
 * ProductImportService) -- analog zum Altsystem (manual.php: Checkboxen +
 * "Alles auswählen" + ein Klick für alle markierten). Andere manuelle
 * Eingriffe (z.B. Werte direkt angleichen) kommen laut Nutzer erst in einem
 * späteren Schritt.
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Shopify\TokenRefresher;
use Connect\Sync\ProductComparisonService;
use Connect\Sync\ProductImportService;

$isAdmin = $isAdmin ?? false;

$pdo = $app->pdo;
$shop = $app->shop;

$tokenRefresher = TokenRefresher::fromConfig($app->config);
$factory = new ConnectorFactory($pdo, $app->crypto, new HttpClient(), $tokenRefresher);
$importService = new ProductImportService($pdo, $factory);

// Zielfelder, die in der fuer diesen Shop aufgeloesten Shopify->Zielsystem-
// Mapping-Vorlage als "Manuelle Eingabe beim Import" markiert sind (siehe
// Connect\Mapping\MappingEngine, transform_type='manual_input') -- z.B.
// HelloCash "Steuersatz": kann nicht aus Shopify ausgelesen und nicht
// sinnvoll fest in der Vorlage hinterlegt werden (variiert pro Produkt/Land),
// daher traegt der Merchant den Wert hier direkt pro Zeile ein. Leeres Array,
// wenn keine Vorlage konfiguriert ist oder sie kein solches Feld hat -- dann
// erscheint einfach kein Zusatzfeld.
$manualInputFields = $importService->manualInputFieldsForTargetImport($shop);

// Rollen, die auf importierbaren Zeilen als editierbares Feld angeboten
// werden (siehe Rendering weiter unten) -- alles AUSSER dem gewaehlten
// Vergleichswert, analog zum Altsystem (manual.php: alle Felder editierbar,
// nur der Matching-Schluessel nicht). Reihenfolge hier bestimmt die
// Spaltenreihenfolge der Tabelle unten.
$editableRoles = ['title', 'sku', 'barcode', 'price'];

// Name der verbundenen Software (z. B. "HelloCash") fuer alle Texte statt "Zielsystem"
$targetName = \Connect\Sync\TargetCapabilities::forShop($pdo, $shop)['target_name'];
$previewQuery = isset($_GET['preview']) ? '&preview=1' : '';

// Sammelimport braucht fuer jedes manuelle Pflichtfeld (z. B. Steuersatz) eine Vorbelegung aus den
// Einstellungen -- eine Eingabe je Produkt gibt es dort nicht.
$bulkManualValues = [];
$bulkMissingDefault = [];
foreach ($manualInputFields as $manualInputField) {
    if ($manualInputField['default_value'] !== null && $manualInputField['default_value'] !== '') {
        $bulkManualValues[$manualInputField['field_key']] = $manualInputField['default_value'];
    } else {
        $bulkMissingDefault[] = $manualInputField['label'];
    }
}

// -----------------------------------------------------------------------
// POST (fetch/JSON): Sammelimport "alle fehlenden anlegen" -- ein kleiner Stapel je Aufruf, die Seite
// ruft so lange erneut auf, bis nichts mehr uebrig ist (Webserver-Zeitlimit, Fortschrittsanzeige).
// Dieselbe Logik wie der Einzelimport (ProductImportService::importMissingProduct), ohne
// Feld-Aenderungen. "skip" = JSON-Liste bereits versuchter Vergleichswerte, damit ein Fehlschlag
// nicht endlos wiederholt wird.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_batch') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $batchMissingOn = (string) ($_POST['missing_on'] ?? '');
    if (!in_array($batchMissingOn, ['shopify', 'target'], true)) {
        echo json_encode(['error' => 'Ungültige Richtung.']);
        exit;
    }
    if ($batchMissingOn === 'target' && $bulkMissingDefault !== []) {
        echo json_encode(['error' => 'Für den Sammelimport fehlt eine Vorbelegung in den Einstellungen: ' . implode(', ', $bulkMissingDefault) . '.']);
        exit;
    }
    @set_time_limit(120);
    $skipDecoded = json_decode((string) ($_POST['skip'] ?? '[]'), true);
    $skipSet = is_array($skipDecoded) ? array_flip(array_map('strval', $skipDecoded)) : [];
    $batchStatus = $batchMissingOn === 'shopify' ? 'missing_on_shopify' : 'missing_on_target';

    $batchReport = (new ProductComparisonService($pdo, $factory))->buildReport($shop);
    if ($batchReport['status'] !== 'success') {
        echo json_encode(['error' => $batchReport['message'] ?? 'Vergleich nicht möglich.']);
        exit;
    }
    $candidates = array_values(array_filter(
        $batchReport['rows'],
        static fn (array $r): bool => $r['status'] === $batchStatus && (string) ($r['match_value'] ?? '') !== '' && !isset($skipSet[(string) $r['match_value']])
    ));
    $batchRows = array_slice($candidates, 0, 5);
    $batchResults = [];
    foreach ($batchRows as $batchRow) {
        $matchValue = (string) $batchRow['match_value'];
        $res = $importService->importMissingProduct($shop, $matchValue, $batchMissingOn, null, $batchMissingOn === 'target' ? $bulkManualValues : [], []);
        $batchResults[] = ['match' => $matchValue, 'label' => (string) ($batchRow['label'] ?? $matchValue), 'success' => (bool) $res['success'], 'message' => (string) $res['message']];
    }
    echo json_encode(['results' => $batchResults, 'remaining' => count($candidates) - count($batchRows)], JSON_UNESCAPED_UNICODE);
    exit;
}

$flashMessage = null;
$flashType = 'info';

// -----------------------------------------------------------------------
// POST: ausgewaehlte fehlende Produkte importieren (Checkboxen je Zeile mit
// Status missing_on_shopify/missing_on_target + "Alle auswählen", siehe
// Tabelle unten) -- nutzt eine vom Admin bereits eingerichtete, aber bewusst
// deaktivierte Mapping-Vorlagen-Aktivierung (siehe Connect\Sync\
// ProductImportService-Klassenkommentar: kein automatischer Bulk-Import im
// Hintergrund, aber ein gezielter manueller Mehrfach-Import ist hier bewusst
// gewuenscht -- der Merchant waehlt selbst aus, es passiert nichts von
// alleine). Zeilen sind ueber ihren Index auf der aktuellen Seite adressiert
// (match_value[]/missing_on[]/variant_option[]/manual_field[<field_key>][]/
// field_override[<role>][] parallel zu selected[]), damit Werte mit
// Sonderzeichen (Punkt/Leerzeichen) nicht als POST-Array-Schluessel
// verwendet werden muessen. VOR dem Aufbau des Vergleichsreports, damit
// gerade importierte Produkte im selben Request schon korrekt einsortiert
// erscheinen.
// -----------------------------------------------------------------------
$importResults = [];
// Nach einem (ggf. fehlgeschlagenen) Import bleiben Auswahl + eingegebene
// Varianten-Option-/manuelle Zusatzfeld-/Feld-Override-Werte erhalten, wenn
// die Seite gleich danach neu gerendert wird -- sonst muesste der Merchant
// nach jedem Fehlschlag alles erneut ankreuzen/eintippen. Schluessel ist der
// Vergleichswert (match_value), NICHT der Zeilen-Index -- der ist nur
// innerhalb EINES Requests stabil, der Vergleichswert bleibt es auch ueber
// den Refresh hinweg (gleiche Zeile an vermutlich gleicher Stelle). Wert ist
// ['variant_option' => string, 'manual_fields' => [field_key => string],
//  'field_overrides' => [role => string]].
$preservedSelection = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_selected_products') {
    $selectedIndices = (array) ($_POST['selected'] ?? []);
    $matchValues = (array) ($_POST['match_value'] ?? []);
    $missingOns = (array) ($_POST['missing_on'] ?? []);
    $variantOptions = (array) ($_POST['variant_option'] ?? []);
    $manualFieldPost = (array) ($_POST['manual_field'] ?? []);
    $fieldOverridePost = (array) ($_POST['field_override'] ?? []);

    if (count($selectedIndices) === 0) {
        $flashMessage = 'Bitte mindestens ein Produkt auswählen.';
        $flashType = 'danger';
    } else {
        foreach ($selectedIndices as $index) {
            $matchValue = (string) ($matchValues[$index] ?? '');
            $missingOn = (string) ($missingOns[$index] ?? '');
            $variantOptionValue = isset($variantOptions[$index]) && $variantOptions[$index] !== '' ? (string) $variantOptions[$index] : null;

            $manualFieldValues = [];
            foreach ($manualInputFields as $manualInputField) {
                $manualFieldValues[$manualInputField['field_key']] = trim((string) ($manualFieldPost[$manualInputField['field_key']][$index] ?? ''));
            }

            $fieldOverrides = [];
            foreach ($editableRoles as $role) {
                // Nur tatsaechlich gesendete Felder (Spalte sichtbar): ein leeres,
                // gesendetes Feld heisst "bewusst geleert", ein fehlendes "unveraendert".
                if (isset($fieldOverridePost[$role][$index])) {
                    $fieldOverrides[$role] = trim((string) $fieldOverridePost[$role][$index]);
                }
            }

            if ($matchValue === '' || !in_array($missingOn, ['shopify', 'target'], true)) {
                continue;
            }

            $preservedSelection[$matchValue] = ['variant_option' => $variantOptionValue ?? '', 'manual_fields' => $manualFieldValues, 'field_overrides' => $fieldOverrides];

            $result = $importService->importMissingProduct($shop, $matchValue, $missingOn, $variantOptionValue, $manualFieldValues, $fieldOverrides);
            $importResults[] = ['label' => $matchValue, 'success' => $result['success'], 'message' => $result['message']];

            // Erfolgreich importierte Zeilen nicht erneut vorauswaehlen --
            // sie tauchen im frischen Report ohnehin nicht mehr unter
            // "Fehlt bei ..." auf, eine haengengebliebene Vorauswahl waere
            // nur verwirrend.
            if ($result['success']) {
                unset($preservedSelection[$matchValue]);
            }
        }

        $successCount = count(array_filter($importResults, static fn(array $r): bool => $r['success']));
        $totalCount = count($importResults);
        $flashMessage = "{$successCount} von {$totalCount} Produkten erfolgreich importiert.";
        $flashType = $successCount === $totalCount ? 'success' : ($successCount === 0 ? 'danger' : 'warning');
    }
}

$report = (new ProductComparisonService($pdo, $factory))->buildReport($shop);

// Ob bei "Fehlt bei Shopify"-Zeilen ein Eingabefeld fuer die Varianten-Option
// angeboten wird -- nur wenn der Shop ueberhaupt einen Varianten-
// Vergleichswert gewaehlt hat (siehe Connect\Sync\ProductImportService,
// Migration 0034). Der Options-Wert selbst kommt bewusst per Freitext-Eingabe
// vom Merchant, nicht aus einem HelloCash-Feld -- varianten-lose Systeme
// kennen dafuer keinen eigenen strukturierten Wert (siehe Altsystem
// manual.php "Varianten Option"-Spalte).
$variantComparisonKeyStmt = $pdo->prepare('SELECT variant_comparison_key FROM connect_shops WHERE shop = :shop');
$variantComparisonKeyStmt->execute(['shop' => $shop]);
$variantImportActive = (string) ($variantComparisonKeyStmt->fetchColumn() ?: '') !== '';

$statusLabels = [
    'no_comparison_value_shopify' => 'Fehlender Vergleichswert (Shopify)',
    'no_comparison_value_target' => 'Fehlender Vergleichswert (' . $targetName . ')',
    'duplicate_shopify' => 'Vergleichswert doppelt (Shopify)',
    'duplicate_target' => 'Vergleichswert doppelt (' . $targetName . ')',
    'missing_on_shopify' => 'Fehlt bei Shopify',
    'missing_on_target' => 'Fehlt in ' . $targetName,
    'diff' => 'Abweichender Bestand',
    'unknown' => 'Bestand unbekannt',
    'in_sync' => 'Synchron',
    'bundle' => 'Set',
];

$statusBadgeClass = [
    'no_comparison_value_shopify' => 'bg-red-lt',
    'no_comparison_value_target' => 'bg-red-lt',
    'duplicate_shopify' => 'bg-red-lt',
    'duplicate_target' => 'bg-red-lt',
    'missing_on_shopify' => 'bg-blue-lt',
    'missing_on_target' => 'bg-blue-lt',
    'diff' => 'bg-yellow-lt',
    'unknown' => 'bg-secondary-lt',
    'in_sync' => 'bg-green-lt',
    'bundle' => 'bg-purple-lt',
];

// Reihenfolge hier bestimmt die Reihenfolge der Filter-Buttons -- "Alle"
// zuerst, danach absteigend nach vermuteter Relevanz für den Merchant
// (Abweichung/fehlender Vergleichswert vor "fehlt komplett").
$filterOptions = [
    'all' => 'Alle',
    // Synchron: zur Kontrolle vor dem Einschalten des Lagerabgleichs (wie viele Paare passen schon)
    'in_sync' => 'Synchron',
    'diff' => 'Abweichender Bestand',
    'no_comparison_value_shopify' => 'Fehlender Vergleichswert (Shopify)',
    'no_comparison_value_target' => 'Fehlender Vergleichswert (' . $targetName . ')',
    'duplicate_shopify' => 'Vergleichswert doppelt (Shopify)',
    'duplicate_target' => 'Vergleichswert doppelt (' . $targetName . ')',
    'missing_on_shopify' => 'Fehlt bei Shopify',
    'missing_on_target' => 'Fehlt in ' . $targetName,
    'unknown' => 'Bestand unbekannt',
];

$allRows = $report['rows'];
// Sets (Migration 0091) nur als Filter zeigen, wenn es welche gibt
if (in_array('bundle', array_column($allRows, 'status'), true)) {
    $filterOptions['bundle'] = 'Sets';
}

// Zähler je Status für die Filterleiste -- ohne Suche über ALLE Zeilen, nicht
// nur die aktuell angezeigte Seite.
$totalCounts = ['all' => count($allRows)];
foreach ($allRows as $row) {
    $totalCounts[$row['status']] = ($totalCounts[$row['status']] ?? 0) + 1;
}

// Laengen-Pruefungen (connect_object_fields.max_length, siehe
// ProductComparisonService): je Pruefung ein eigener Filter + Zaehler, nur
// vorhanden, wenn im Connector Builder ein Limit gesetzt ist.
$lengthProblems = $report['problems'] ?? [];
foreach ($lengthProblems as $lengthProblem) {
    $totalCounts[$lengthProblem['key']] = $lengthProblem['count'];
    $filterOptions[$lengthProblem['key']] = $lengthProblem['label'];
}

// Suche (GET q): Produktname, Vergleichswert, SKU, Barcode, Variante. Die Zähler der Filterleiste
// beziehen sich dann auf die Treffer, damit Suche und Filter zusammenpassen.
$searchTerm = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$searchRows = $allRows;
$counts = $totalCounts;
if ($searchTerm !== '') {
    $searchRows = array_values(array_filter($allRows, static function (array $row) use ($searchTerm): bool {
        foreach ([$row['label'] ?? null, $row['match_value'] ?? null, $row['sku'] ?? null, $row['barcode'] ?? null, $row['variant_title'] ?? null] as $haystack) {
            if ($haystack !== null && mb_stripos((string) $haystack, $searchTerm) !== false) {
                return true;
            }
        }

        return false;
    }));
    $counts = ['all' => count($searchRows)];
    foreach ($searchRows as $row) {
        $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        foreach ($row['flags'] as $flagKey) {
            $counts[$flagKey] = ($counts[$flagKey] ?? 0) + 1;
        }
    }
}

// Zusätzliche Spalten (SKU/Barcode/Preis/Variantentitel, siehe
// Connect\Sync\ProductComparisonService) nur zeigen, wenn irgendeine Zeile
// überhaupt einen Wert dafür hat -- sonst bliebe die Spalte für Shops ohne
// diese Rolle einfach durchgehend leer. SKU/Barcode zusätzlich nur, wenn sie
// nicht ohnehin schon als "Vergleichswert"-Spalte zu sehen sind.
$comparisonKey = (string) ($report['comparisonKey'] ?? '');
$hasAnySku = false;
$hasAnyBarcode = false;
$hasAnyPrice = false;
$hasAnyVariantTitle = false;
foreach ($allRows as $row) {
    $hasAnySku = $hasAnySku || $row['sku'] !== null;
    $hasAnyBarcode = $hasAnyBarcode || $row['barcode'] !== null;
    $hasAnyPrice = $hasAnyPrice || $row['price'] !== null;
    $hasAnyVariantTitle = $hasAnyVariantTitle || $row['variant_title'] !== null;
}
$showSkuColumn = $hasAnySku && $comparisonKey !== 'sku';
$showBarcodeColumn = $hasAnyBarcode && $comparisonKey !== 'barcode';
$showPriceColumn = $hasAnyPrice;
$showVariantTitleColumn = $hasAnyVariantTitle;

$filter = $_GET['filter'] ?? 'all';
if (!array_key_exists($filter, $filterOptions)) {
    $filter = 'all';
}

$filteredRows = $filter === 'all'
    ? $searchRows
    : array_values(array_filter($searchRows, static fn(array $row): bool => $row['status'] === $filter || in_array($filter, $row['flags'], true)));

// Paginierung server-seitig über das bereits gefilterte PHP-Array (kein SQL
// LIMIT/OFFSET möglich -- die Datenquelle ist der JSON-Objekt-Cache, siehe
// ProductComparisonService/ObjectCache).
$pageSizeOptions = [25, 50, 100, 200];
$pageSize = (int) ($_GET['page_size'] ?? 50);
if (!in_array($pageSize, $pageSizeOptions, true)) {
    $pageSize = 50;
}
$totalFiltered = count($filteredRows);
$totalPages = max(1, (int) ceil($totalFiltered / $pageSize));
$page = max(1, (int) ($_GET['page'] ?? 1));
if ($page > $totalPages) {
    $page = $totalPages;
}
$pageRows = array_slice($filteredRows, ($page - 1) * $pageSize, $pageSize);

$pageTitle = 'Produkte' . ($isAdmin ? ' — ' . $shop : '');
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

<div class="row row-cards">
    <?php if ($report['status'] !== 'success'): ?>
        <div class="col-12">
            <div class="alert alert-info">
                <?php echo htmlspecialchars($report['message'] ?? 'Vergleichsübersicht noch nicht verfügbar.'); ?>
            </div>
        </div>
    <?php else: ?>
        <?php if (!empty($report['location_missing'])): ?>
            <div class="col-12">
                <div class="alert alert-danger mb-0">
                    <strong>Kein Shopify-Standort gewählt.</strong> Der Lagerabgleich ist pausiert, die Shopify-Bestände sind unbekannt.
                    Bitte unter Einstellungen → Lagerbestand den Standort wählen.
                </div>
            </div>
        <?php endif; ?>
        <?php if ((int) ($report['hidden_by_location'] ?? 0) > 0): ?>
            <div class="col-12">
                <div class="alert alert-info mb-0 py-2">
                    <strong><?php echo (int) $report['hidden_by_location']; ?> Shopify-Produkte</strong> werden nicht angezeigt, weil sie am gewählten Standort nicht geführt werden.
                    <span class="text-secondary">Standort prüfen unter Einstellungen → Lagerbestand; nach einer Änderung stimmt die Liste erst nach dem nächsten Abruf der Shopify-Produkte.</span>
                </div>
            </div>
        <?php endif; ?>
        <?php
        $e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);
        // Link auf diese Seite mit gesetzten Parametern (Filter/Suche/Seitengroesse bleiben erhalten,
        // die Vorschau-Ansicht des Admins ebenso)
        $urlFor = static function (array $override = []) use ($shop, $previewQuery, $filter, $searchTerm, $pageSize): string {
            $params = array_merge(['filter' => $filter, 'q' => $searchTerm, 'page_size' => $pageSize], $override);
            $query = '?shop=' . urlencode($shop) . $previewQuery;
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null || ($key === 'filter' && $value === 'all') || ($key === 'page_size' && (int) $value === 50) || ($key === 'page' && (int) $value <= 1)) {
                    continue;
                }
                $query .= '&' . $key . '=' . urlencode((string) $value);
            }

            return $query;
        };

        // Produkt-Check: letzter Punktwert (Connect\Sync\ProductCheck, Migration 0090)
        try {
            $checkScoreStmt = $pdo->prepare("SELECT score FROM connect_product_checks WHERE shop = :shop AND status = 'done'");
            $checkScoreStmt->execute(['shop' => $shop]);
            $checkScore = $checkScoreStmt->fetchColumn();
        } catch (\PDOException) {
            $checkScore = false;
        }
        $checkScore = $checkScore !== false && $checkScore !== null ? (int) $checkScore : null;
        $checkColor = $checkScore === null ? 'secondary' : ($checkScore >= 85 ? 'green' : ($checkScore >= 65 ? 'yellow' : 'red'));

        $missingOnTargetCount = (int) ($totalCounts['missing_on_target'] ?? 0);
        $missingOnShopifyCount = (int) ($totalCounts['missing_on_shopify'] ?? 0);

        // Altsystem-Pruefungen ("Probleme"): Datensaetze mit leerem oder
        // mehrfach vorkommendem Vergleichswert koennen nicht synchronisiert
        // werden. Zaehlung = Anzahl betroffener Datensaetze, entspricht damit
        // exakt der Zeilenzahl des verlinkten Filters.
        $problemChecks = [
            'duplicate_target' => 'Vergleichswert-Duplikate in ' . $targetName,
            'duplicate_shopify' => 'Vergleichswert-Duplikate in Shopify',
            'no_comparison_value_target' => 'Fehlende Vergleichswerte in ' . $targetName,
            'no_comparison_value_shopify' => 'Fehlende Vergleichswerte in Shopify',
        ];
        foreach ($lengthProblems as $lengthProblem) {
            $problemChecks[$lengthProblem['key']] = $lengthProblem['label'];
        }
        $openProblems = [];
        foreach ($problemChecks as $problemKey => $problemLabel) {
            if ((int) ($totalCounts[$problemKey] ?? 0) > 0) {
                $openProblems[$problemKey] = $problemLabel;
            }
        }
        ?>

        <?php // ---- Basis-Aktionen: Produkte uebernehmen + Produkt-Check -------------------------------- ?>
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title"><?php echo \Connect\Ui\Icon::svg('packages', 18, 'me-1'); ?>Produkte übernehmen</h3></div>
                <div class="card-body">
                    <?php if ($missingOnTargetCount === 0 && $missingOnShopifyCount === 0): ?>
                        <div class="text-success"><?php echo \Connect\Ui\Icon::svg('circle-check', 16, 'me-1'); ?>Alle Produkte sind zwischen Shopify und <?php echo $e($targetName); ?> verknüpft.</div>
                        <div class="small text-secondary mt-1">Neue Produkte erscheinen hier automatisch, sobald sie auf einer Seite angelegt werden.</div>
                    <?php else: ?>
                        <div class="d-grid gap-3">
                            <?php foreach ([['target', $missingOnTargetCount, 'Shopify', $targetName, 'missing_on_target'], ['shopify', $missingOnShopifyCount, $targetName, 'Shopify', 'missing_on_shopify']] as [$bulkDir, $bulkCount, $bulkFrom, $bulkTo, $bulkFilter]): ?>
                                <?php if ($bulkCount === 0) { continue; } ?>
                                <?php $bulkBlocked = $bulkDir === 'target' && $bulkMissingDefault !== []; ?>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <div class="flex-fill" style="min-width: 14rem;">
                                        <strong><?php echo $bulkCount; ?></strong> Produkte aus <?php echo $e($bulkFrom); ?> gibt es noch nicht in <?php echo $e($bulkTo); ?>
                                    </div>
                                    <a href="<?php echo $e($urlFor(['filter' => $bulkFilter, 'q' => '', 'page' => 1])); ?>" class="btn btn-sm">Einzeln auswählen</a>
                                    <button type="button" class="btn btn-sm btn-primary" data-bulk-import="<?php echo $bulkDir; ?>" data-count="<?php echo $bulkCount; ?>" data-from="<?php echo $e($bulkFrom); ?>" data-to="<?php echo $e($bulkTo); ?>" <?php echo $bulkBlocked ? 'disabled' : ''; ?>>Alle <?php echo $bulkCount; ?> anlegen</button>
                                </div>
                                <?php if ($bulkBlocked): ?>
                                    <div class="alert alert-warning py-2 mb-0 small">
                                        Für den Sammelimport fehlt ein Standardwert für <strong><?php echo $e(implode(', ', $bulkMissingDefault)); ?></strong>.
                                        <a href="sync_mappings.php?shop=<?php echo urlencode($shop); ?><?php echo $e($previewQuery); ?>#setup-prices">In den Einstellungen festlegen</a> – oder die Produkte einzeln auswählen und den Wert je Zeile eintragen.
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="small text-secondary mt-3">
                            Ein Klick übernimmt alle fehlenden Produkte der jeweiligen Richtung – Schritt für Schritt im Hintergrund dieser Seite
                            (je 5 Produkte pro Durchgang, bei großen Beständen dauert das einige Minuten). Titel, SKU, Barcode und Preis werden so übernommen, wie sie in der Liste unten stehen.
                            Die Seite bitte offen lassen; ein Abbruch ist jederzeit möglich, bereits angelegte Produkte bleiben bestehen.
                        </div>
                        <div id="bulk-box" class="mt-3" hidden>
                            <div class="d-flex justify-content-between small mb-1"><span id="bulk-status">Starte …</span><span id="bulk-count"></span></div>
                            <div class="progress progress-sm mb-2"><div id="bulk-bar" class="progress-bar" style="width: 0%"></div></div>
                            <div class="d-flex gap-2">
                                <button type="button" id="bulk-stop" class="btn btn-sm btn-outline-danger">Abbrechen</button>
                                <a href="<?php echo $e($urlFor(['page' => 1])); ?>" id="bulk-reload" class="btn btn-sm btn-primary" hidden>Liste aktualisieren</a>
                            </div>
                            <div id="bulk-errors" class="small text-danger mt-2"></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title"><?php echo \Connect\Ui\Icon::svg('list-check', 18, 'me-1'); ?>Produkt-Check</h3></div>
                <div class="card-body d-flex flex-column">
                    <div class="d-flex align-items-center gap-3">
                        <div class="display-6 fw-bold text-<?php echo $checkColor; ?>"><?php echo $checkScore !== null ? $checkScore . ' %' : '–'; ?></div>
                        <div class="text-secondary small">
                            <?php echo $checkScore !== null ? 'Qualität deiner Shopify-Produkte (Daten, SEO, Texte).' : 'Prüft deine Shopify-Produkte auf fehlende Daten, SEO und Textqualität.'; ?>
                        </div>
                    </div>
                    <div class="mt-auto pt-3">
                        <a href="product_check.php?shop=<?php echo urlencode($shop); ?><?php echo $e($previewQuery); ?>" class="btn btn-primary"><?php echo $checkScore !== null ? 'Befunde ansehen' : 'Jetzt prüfen'; ?></a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($openProblems !== []): ?>
            <div class="col-12">
                <div class="card">
                    <div class="card-body py-3">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="fw-bold me-2"><?php echo \Connect\Ui\Icon::svg('alert-triangle', 16, 'me-1 text-secondary'); ?>Nicht abgleichbar</span>
                            <?php foreach ($openProblems as $problemKey => $problemLabel): ?>
                                <a href="<?php echo $e($urlFor(['filter' => $problemKey, 'q' => '', 'page' => 1])); ?>" class="btn btn-sm <?php echo $filter === $problemKey ? 'btn-dark' : 'btn-outline-secondary'; ?>"><?php echo $e($problemLabel); ?> <span class="badge bg-red-lt ms-1"><?php echo (int) $totalCounts[$problemKey]; ?></span></a>
                            <?php endforeach; ?>
                        </div>
                        <div class="small text-secondary mt-2">Artikel mit fehlendem oder mehrfach vorkommendem Vergleichswert werden nicht synchronisiert. Die Werte bitte in der jeweiligen Software korrigieren.</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php // ---- Suche + Filter ------------------------------------------------------------------- ?>
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="get" class="d-flex flex-wrap gap-2 mb-3">
                        <input type="hidden" name="shop" value="<?php echo $e($shop); ?>">
                        <?php if ($previewQuery !== ''): ?><input type="hidden" name="preview" value="1"><?php endif; ?>
                        <input type="hidden" name="filter" value="<?php echo $e($filter); ?>">
                        <input type="hidden" name="page_size" value="<?php echo $pageSize; ?>">
                        <div class="flex-fill" style="min-width: 14rem;">
                            <input type="search" name="q" value="<?php echo $e($searchTerm); ?>" class="form-control" placeholder="Produkt suchen: Name, Artikelnummer / SKU, Barcode …" maxlength="100" aria-label="Produkt suchen">
                        </div>
                        <button type="submit" class="btn btn-primary">Suchen</button>
                        <?php if ($searchTerm !== ''): ?><a href="<?php echo $e($urlFor(['q' => '', 'page' => 1])); ?>" class="btn">Zurücksetzen</a><?php endif; ?>
                    </form>
                    <?php if ($searchTerm !== ''): ?>
                        <div class="small text-secondary mb-2"><?php echo (int) $counts['all']; ?> Treffer für „<?php echo $e($searchTerm); ?>“ – die Zahlen an den Filtern gelten für diese Suche.</div>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-2" role="group">
                        <?php foreach ($filterOptions as $key => $label): ?>
                            <?php // Filter ohne Eintraege ausblenden (ausser "Alle", Synchron, Abweichend und dem aktiven) -- sonst ist die Leiste voller Nullen ?>
                            <?php if ($key !== 'all' && $key !== $filter && (int) ($counts[$key] ?? 0) === 0 && !in_array($key, ['in_sync', 'diff'], true)) { continue; } ?>
                            <a href="<?php echo $e($urlFor(['filter' => $key, 'page' => 1])); ?>"
                                class="btn btn-sm <?php echo $filter === $key ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                                <?php echo htmlspecialchars($label); ?>
                                <?php // auf dem aktiven (blauen) Knopf heller Zaehler, sonst war die Zahl kaum lesbar ?>
                                <span class="badge ms-1 <?php echo $filter === $key ? 'bg-white text-dark' : 'bg-secondary-lt'; ?>"><?php echo $counts[$key] ?? 0; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (count($importResults) > 0): ?>
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Import-Ergebnis</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table table-striped">
                            <tbody>
                                <?php foreach ($importResults as $result): ?>
                                    <tr>
                                        <td class="w-1">
                                            <span class="badge <?php echo $result['success'] ? 'bg-green-lt' : 'bg-red-lt'; ?>">
                                                <?php echo $result['success'] ? 'OK' : 'Fehler'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($result['label']); ?></td>
                                        <td class="text-secondary"><?php echo htmlspecialchars($result['message']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between w-100">
                    <h3 class="card-title mb-0">Produkte (Vergleichswert: <?php echo htmlspecialchars((string) $report['comparisonKey']); ?>)
                        <?php if ((int) ($report['buffer'] ?? 0) > 0): ?>
                            <span class="badge bg-blue-lt ms-2" title="Shopify zeigt den Bestand aus <?php echo htmlspecialchars($targetName); ?> minus diese Anzahl – das gilt als synchron.">Sicherheitsbestand <?php echo (int) $report['buffer']; ?></span>
                        <?php endif; ?>
                    </h3>
                    <form method="get" class="d-flex align-items-center gap-2">
                        <a href="bundles.php?shop=<?php echo urlencode($shop); ?><?php echo isset($_GET['preview']) ? '&amp;preview=1' : ''; ?>" class="btn btn-sm btn-outline-primary me-2"><?php echo \Connect\Ui\Icon::svg('packages', 16, 'me-1'); ?>Sets</a>
                        <input type="hidden" name="shop" value="<?php echo htmlspecialchars($shop); ?>">
                        <input type="hidden" name="q" value="<?php echo htmlspecialchars($searchTerm); ?>">
                        <?php if ($previewQuery !== ''): ?><input type="hidden" name="preview" value="1"><?php endif; ?>
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <label class="text-secondary mb-0">pro Seite</label>
                        <select name="page_size" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                            <?php foreach ($pageSizeOptions as $option): ?>
                                <option value="<?php echo $option; ?>" <?php echo $pageSize === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <?php if (count($pageRows) === 0): ?>
                    <div class="card-body text-secondary"><?php echo $searchTerm !== '' ? 'Keine Treffer für „' . htmlspecialchars($searchTerm) . '“ in dieser Ansicht.' : 'Keine Produkte in dieser Ansicht.'; ?></div>
                <?php else: ?>
                    <?php
                    // Gibt es auf dieser Seite ueberhaupt eine importierbare
                    // Zeile? Nur dann lohnt sich "Alle auswählen" + die
                    // Sammel-Aktionen in der Werkzeugleiste.
                    $anyImportableRow = false;
                    foreach ($pageRows as $row) {
                        if (in_array($row['status'], ['missing_on_shopify', 'missing_on_target'], true) && $row['match_value'] !== null) {
                            $anyImportableRow = true;
                            break;
                        }
                    }

                    // Aktion haengt bewusst am jeweiligen BUTTON (name="action",
                    // je ein eigener value), nicht an einem festen versteckten
                    // Feld -- so teilen sich mehrere Sammel-Aktionen (aktuell nur
                    // Importieren, spaeter z.B. "Preise synchronisieren") dieselben
                    // Checkboxen/dasselbe Formular, ohne die Auswahl-Logik je
                    // Aktion neu zu bauen. Jeder Aktions-Button bekommt sein
                    // eigenes onclick-confirm() statt eines form-weiten
                    // onsubmit(), damit der Bestaetigungstext zur tatsaechlich
                    // gewaehlten Aktion passt.
                    ?>
                    <form method="post" id="import-selected-form">
                        <?php if ($anyImportableRow): ?>
                            <div class="card-body d-flex align-items-center gap-2 pb-0">
                                <label class="form-check mb-0">
                                    <input type="checkbox" id="select-all-checkbox" class="form-check-input">
                                    <span class="form-check-label">Alle auswählen</span>
                                </label>
                                <button type="submit" name="action" value="import_selected_products" class="btn btn-sm btn-primary ms-auto" onclick="return confirm('Ausgewählte Produkte jetzt importieren?');">Importieren</button>
                            </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table table-striped">
                                <thead>
                                    <tr>
                                        <th class="w-1"></th>
                                        <th>Produkt</th>
                                        <th>Vergleichswert</th>
                                        <?php if ($showSkuColumn): ?><th>SKU</th><?php endif; ?>
                                        <?php if ($showBarcodeColumn): ?><th>Barcode</th><?php endif; ?>
                                        <?php if ($showPriceColumn): ?><th>Preis</th><?php endif; ?>
                                        <?php if ($showVariantTitleColumn): ?><th>Variante</th><?php endif; ?>
                                        <th class="text-end">Bestand Shopify</th>
                                        <th class="text-end">Bestand <?php echo htmlspecialchars($targetName); ?></th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pageRows as $i => $row): ?>
                                        <?php
                                        $missingOn = null;
                                        if ($row['status'] === 'missing_on_shopify') {
                                            $missingOn = 'shopify';
                                        } elseif ($row['status'] === 'missing_on_target') {
                                            $missingOn = 'target';
                                        }
                                        $canImport = $missingOn !== null && $row['match_value'] !== null;
                                        $isPreselected = $canImport && array_key_exists((string) $row['match_value'], $preservedSelection);
                                        $preservedOptionValue = $isPreselected ? $preservedSelection[(string) $row['match_value']]['variant_option'] : '';
                                        $preservedManualFields = $isPreselected ? $preservedSelection[(string) $row['match_value']]['manual_fields'] : [];
                                        $preservedFieldOverrides = $isPreselected ? $preservedSelection[(string) $row['match_value']]['field_overrides'] : [];

                                        // Editierbares Feld je Rolle rendern, AUSSER fuer den
                                        // aktiven Vergleichswert (Matching-Schluessel, darf sich
                                        // nicht vom Quell-Datensatz unterscheiden) -- Vorbelegung:
                                        // eingegebener Wert (nach Fehlschlag) > aktueller Wert aus
                                        // dem Vergleich.
                                        $editableCell = function (string $role, ?string $currentValue) use ($canImport, $comparisonKey, $preservedFieldOverrides, $i): string {
                                            if (!$canImport || $role === $comparisonKey) {
                                                return htmlspecialchars($currentValue ?? '—');
                                            }
                                            $value = $preservedFieldOverrides[$role] ?? $currentValue ?? '';
                                            return '<input type="text" name="field_override[' . htmlspecialchars($role) . '][' . $i . ']" value="' . htmlspecialchars($value) . '" class="form-control form-control-sm">';
                                        };
                                        ?>
                                        <tr>
                                            <td>
                                                <?php if ($canImport): ?>
                                                    <input type="checkbox" name="selected[]" value="<?php echo $i; ?>" class="form-check-input row-checkbox" <?php echo $isPreselected ? 'checked' : ''; ?>>
                                                    <input type="hidden" name="match_value[<?php echo $i; ?>]" value="<?php echo htmlspecialchars((string) $row['match_value']); ?>">
                                                    <input type="hidden" name="missing_on[<?php echo $i; ?>]" value="<?php echo htmlspecialchars($missingOn); ?>">
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo $editableCell('title', $row['label'] ?? $row['match_value']); ?>
                                                <?php // Bezeichnung im Zielsystem nur zeigen, wenn sie von der Shopify-Bezeichnung abweicht ?>
                                                <?php if (($row['target_label'] ?? null) !== null && mb_strtolower(trim((string) $row['target_label'])) !== mb_strtolower(trim((string) ($row['label'] ?? '')))): ?>
                                                    <div class="small text-secondary" title="Bezeichnung in <?php echo htmlspecialchars($targetName); ?>"><?php echo htmlspecialchars($targetName); ?>: <?php echo htmlspecialchars((string) $row['target_label']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-secondary">
                                                <?php if ($row['match_value'] !== null && !$canImport): ?>
                                                    <a href="stock_history.php?shop=<?php echo urlencode($shop); ?><?php echo isset($_GET['preview']) ? '&amp;preview=1' : ''; ?>&amp;match=<?php echo urlencode((string) $row['match_value']); ?>" title="Bestandsverlauf ansehen"><?php echo \Connect\Ui\Icon::svg('history', 14, 'me-1'); ?><?php echo htmlspecialchars((string) $row['match_value']); ?></a>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars((string) ($row['match_value'] ?? '—')); ?>
                                                <?php endif; ?>
                                            </td>
                                            <?php if ($showSkuColumn): ?><td><?php echo $editableCell('sku', $row['sku']); ?></td><?php endif; ?>
                                            <?php if ($showBarcodeColumn): ?><td><?php echo $editableCell('barcode', $row['barcode']); ?></td><?php endif; ?>
                                            <?php if ($showPriceColumn): ?>
                                                <td>
                                                    <?php echo $editableCell('price', $row['price']); ?>
                                                    <?php if (in_array('price_diff', $row['flags'], true)): ?>
                                                        <div class="small text-secondary text-nowrap" title="Preis in <?php echo htmlspecialchars($targetName); ?>"><?php echo htmlspecialchars($targetName); ?>: <?php echo htmlspecialchars(number_format((float) $row['target_price'], 2, ',', '.')); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                            <?php if ($showVariantTitleColumn): ?><td class="text-secondary"><?php echo htmlspecialchars($row['variant_title'] ?? '—'); ?></td><?php endif; ?>
                                            <td class="text-end"><?php echo $row['shopify_quantity'] !== null ? (int) $row['shopify_quantity'] : '—'; ?></td>
                                            <td class="text-end"><?php echo $row['target_quantity'] !== null ? (int) $row['target_quantity'] : '—'; ?></td>
                                            <td>
                                                <span class="badge <?php echo htmlspecialchars($statusBadgeClass[$row['status']] ?? 'bg-secondary-lt'); ?>">
                                                    <?php echo htmlspecialchars($statusLabels[$row['status']] ?? $row['status']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end text-nowrap">
                                                <?php if ($missingOn === 'shopify' && $variantImportActive): ?>
                                                    <input type="text" name="variant_option[<?php echo $i; ?>]" value="<?php echo htmlspecialchars($preservedOptionValue); ?>" class="form-control form-control-sm" style="width:100px" placeholder="Variante (z.B. Größe)" title="Falls dieses Produkt eine Variante eines bereits bestehenden Shopify-Produkts ist (oder die erste Variante eines neuen Produkts sein soll): hier den unterscheidenden Wert eintragen (z.B. Größe/Farbe). Leer lassen für ein eigenständiges neues Produkt ohne Varianten.">
                                                <?php endif; ?>
                                                <?php if ($missingOn === 'target'): ?>
                                                    <?php foreach ($manualInputFields as $manualInputField): ?>
                                                        <?php $manualFieldTitle = htmlspecialchars($manualInputField['label']) . ' -- wird beim Import an ' . htmlspecialchars($targetName) . ' übermittelt.' . ($manualInputField['default_value'] !== null ? ' Vorbelegt aus dem Standard-Steuersatz unter Einstellungen -- pro Produkt änderbar.' : ''); ?>
                                                        <input type="text" name="manual_field[<?php echo htmlspecialchars($manualInputField['field_key']); ?>][<?php echo $i; ?>]" value="<?php echo htmlspecialchars((string) ($preservedManualFields[$manualInputField['field_key']] ?? $manualInputField['default_value'] ?? '')); ?>" class="form-control form-control-sm mb-1" style="width:120px" placeholder="<?php echo htmlspecialchars($manualInputField['label']); ?>" title="<?php echo $manualFieldTitle; ?>">
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                    <div class="card-footer d-flex align-items-center justify-content-between">
                        <span class="text-secondary"><?php echo $totalFiltered; ?> Produkte — Seite <?php echo $page; ?> von <?php echo $totalPages; ?></span>
                        <ul class="pagination mb-0">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo $e($urlFor(['page' => max(1, $page - 1)])); ?>">Zurück</a>
                            </li>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo $e($urlFor(['page' => min($totalPages, $page + 1)])); ?>">Weiter</a>
                            </li>
                        </ul>
                    </div>
                    <?php if ($anyImportableRow): ?>
                        <script>
                            // Analog zur bestehenden Client-Suche in _objects_body.php:
                            // schlichtes Vanilla-JS statt einer Bibliothek, nur fuer
                            // diese eine Seite gebraucht.
                            (function() {
                                var selectAll = document.getElementById('select-all-checkbox');
                                var rowCheckboxes = document.querySelectorAll('#import-selected-form .row-checkbox');

                                selectAll.addEventListener('change', function() {
                                    rowCheckboxes.forEach(function(checkbox) {
                                        checkbox.checked = selectAll.checked;
                                    });
                                });
                            })();
                        </script>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    // Sammelimport "Alle anlegen": ruft den Import in kleinen Stapeln auf (action=import_batch), bis nichts mehr
    // fehlt. Bereits versuchte Produkte werden mitgeschickt (skip), damit ein Fehlschlag nicht endlos wiederholt wird.
    (function () {
        var buttons = document.querySelectorAll('[data-bulk-import]');
        if (!buttons.length) { return; }
        var csrf = <?php echo json_encode((string) ($_SESSION['connect_csrf'] ?? '')); ?>;
        var box = document.getElementById('bulk-box');
        var bar = document.getElementById('bulk-bar');
        var statusEl = document.getElementById('bulk-status');
        var countEl = document.getElementById('bulk-count');
        var errorsEl = document.getElementById('bulk-errors');
        var stopBtn = document.getElementById('bulk-stop');
        var reloadBtn = document.getElementById('bulk-reload');
        var stopped = false;

        stopBtn.addEventListener('click', function () { stopped = true; stopBtn.disabled = true; statusEl.textContent = 'Wird nach dem aktuellen Durchgang angehalten …'; });

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var dir = btn.dataset.bulkImport, total = parseInt(btn.dataset.count, 10);
                var msg = 'Alle ' + total + ' Produkte aus ' + btn.dataset.from + ' jetzt in ' + btn.dataset.to + ' anlegen?\n\n'
                    + (dir === 'shopify' ? 'Sie werden als neue, eigenständige Shopify-Produkte angelegt (ohne Varianten).\n' : 'Es werden echte Artikel angelegt.\n')
                    + 'Das kann bei großen Beständen einige Minuten dauern – die Seite bitte offen lassen.';
                if (!window.confirm(msg)) { return; }

                buttons.forEach(function (b) { b.disabled = true; });
                var done = 0, failed = 0, attempted = [], failures = [];
                stopped = false;
                stopBtn.disabled = false;
                reloadBtn.hidden = true;
                errorsEl.textContent = '';
                box.hidden = false;
                bar.className = 'progress-bar';

                function finish(text, isError) {
                    statusEl.textContent = text;
                    bar.className = 'progress-bar ' + (isError ? 'bg-danger' : (failed > 0 ? 'bg-warning' : 'bg-success'));
                    stopBtn.disabled = true;
                    reloadBtn.hidden = false;
                }

                function step() {
                    if (stopped) { finish('Angehalten: ' + done + ' angelegt' + (failed ? ', ' + failed + ' mit Fehler' : '') + '.', false); return; }
                    var body = new URLSearchParams();
                    body.set('action', 'import_batch');
                    body.set('missing_on', dir);
                    body.set('skip', JSON.stringify(attempted));
                    fetch(window.location.href, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf }, body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.error) { finish('Abgebrochen: ' + data.error, true); return; }
                            data.results.forEach(function (res) {
                                attempted.push(res.match);
                                if (res.success) { done++; } else { failed++; failures.push(res.label + ': ' + res.message); }
                            });
                            var processed = done + failed;
                            bar.style.width = Math.min(100, Math.round(processed / total * 100)) + '%';
                            countEl.textContent = processed + ' von ' + total;
                            statusEl.textContent = 'Lege Produkte an …';
                            errorsEl.innerHTML = '';
                            failures.slice(-10).forEach(function (line) { var d = document.createElement('div'); d.textContent = line; errorsEl.appendChild(d); });
                            if (failures.length > 10) { var more = document.createElement('div'); more.textContent = '… und ' + (failures.length - 10) + ' weitere Fehler (Details in den Aktivitäten).'; errorsEl.appendChild(more); }
                            if (data.remaining <= 0 || data.results.length === 0) {
                                finish('Fertig: ' + done + ' angelegt' + (failed ? ', ' + failed + ' mit Fehler' : '') + '.', false);
                            } else {
                                step();
                            }
                        })
                        .catch(function () { finish('Verbindung unterbrochen nach ' + done + ' angelegten Produkten. Du kannst neu starten – bereits angelegte werden übersprungen.', true); });
                }
                step();
            });
        });
    })();
</script>

<?php require __DIR__ . '/_footer.php'; ?>