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

$tokenRefresher = new TokenRefresher(
    new HttpClient(),
    $app->config->optional('CONNECT_SHOPIFY_API_KEY'),
    $app->config->optional('CONNECT_SHOPIFY_API_SECRET')
);
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
                $fieldOverrides[$role] = trim((string) ($fieldOverridePost[$role][$index] ?? ''));
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
    'no_comparison_value_target' => 'Fehlender Vergleichswert (Zielsystem)',
    'duplicate_shopify' => 'Vergleichswert doppelt (Shopify)',
    'duplicate_target' => 'Vergleichswert doppelt (Zielsystem)',
    'missing_on_shopify' => 'Fehlt bei Shopify',
    'missing_on_target' => 'Fehlt im Zielsystem',
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
    'missing_on_shopify' => 'bg-orange-lt',
    'missing_on_target' => 'bg-orange-lt',
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
    'no_comparison_value_target' => 'Fehlender Vergleichswert (Zielsystem)',
    'duplicate_shopify' => 'Vergleichswert doppelt (Shopify)',
    'duplicate_target' => 'Vergleichswert doppelt (Zielsystem)',
    'missing_on_shopify' => 'Fehlt bei Shopify',
    'missing_on_target' => 'Fehlt im Zielsystem',
    'unknown' => 'Bestand unbekannt',
];

$allRows = $report['rows'];
// Sets (Migration 0091) nur als Filter zeigen, wenn es welche gibt
if (in_array('bundle', array_column($allRows, 'status'), true)) {
    $filterOptions['bundle'] = 'Sets';
}

// Zähler je Status für die Filterleiste -- immer über ALLE Zeilen, nicht
// nur die aktuell angezeigte Seite.
$counts = ['all' => count($allRows)];
foreach ($allRows as $row) {
    $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
}

// Laengen-Pruefungen (connect_object_fields.max_length, siehe
// ProductComparisonService): je Pruefung ein eigener Filter + Zaehler, nur
// vorhanden, wenn im Connector Builder ein Limit gesetzt ist.
$lengthProblems = $report['problems'] ?? [];
foreach ($lengthProblems as $lengthProblem) {
    $counts[$lengthProblem['key']] = $lengthProblem['count'];
    $filterOptions[$lengthProblem['key']] = $lengthProblem['label'];
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
    ? $allRows
    : array_values(array_filter($allRows, static fn(array $row): bool => $row['status'] === $filter || in_array($filter, $row['flags'], true)));

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
        <?php
        // Altsystem-Pruefungen ("Probleme"): Datensaetze mit leerem oder
        // mehrfach vorkommendem Vergleichswert koennen nicht synchronisiert
        // werden. Zaehlung = Anzahl betroffener Datensaetze, entspricht damit
        // exakt der Zeilenzahl des verlinkten Filters.
        $problemChecks = [
            'duplicate_target' => 'Vergleichswert-Duplikate im Zielsystem',
            'duplicate_shopify' => 'Vergleichswert-Duplikate in Shopify',
            'no_comparison_value_target' => 'Fehlende Vergleichswerte im Zielsystem',
            'no_comparison_value_shopify' => 'Fehlende Vergleichswerte in Shopify',
        ];
        ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Probleme</h3>
                </div>
                <div class="card-body">
                    <p class="text-secondary">Wichtig: Artikel mit fehlendem (leerem) oder mehrfach vorkommendem Vergleichswert können nicht synchronisiert werden!</p>
                    <div class="list-group list-group-flush">
                        <?php foreach ($problemChecks as $problemStatus => $problemLabel): ?>
                            <?php $problemCount = (int) ($counts[$problemStatus] ?? 0); ?>
                            <div class="list-group-item d-flex align-items-center justify-content-between px-0">
                                <a href="?shop=<?php echo urlencode($shop); ?>&amp;filter=<?php echo urlencode($problemStatus); ?>"><?php echo htmlspecialchars($problemLabel); ?></a>
                                <span class="badge <?php echo $problemCount > 0 ? 'bg-orange-lt' : 'bg-green-lt'; ?>"><?php echo $problemCount; ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($lengthProblems as $lengthProblem): ?>
                            <div class="list-group-item d-flex align-items-center justify-content-between px-0">
                                <a href="?shop=<?php echo urlencode($shop); ?>&amp;filter=<?php echo urlencode($lengthProblem['key']); ?>"><?php echo htmlspecialchars($lengthProblem['label']); ?></a>
                                <span class="badge <?php echo $lengthProblem['count'] > 0 ? 'bg-orange-lt' : 'bg-green-lt'; ?>"><?php echo (int) $lengthProblem['count']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="btn-group flex-wrap" role="group">
                        <?php foreach ($filterOptions as $key => $label): ?>
                            <a href="?shop=<?php echo urlencode($shop); ?>&amp;filter=<?php echo urlencode($key); ?>"
                                class="btn btn-sm <?php echo $filter === $key ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                                <?php echo htmlspecialchars($label); ?>
                                <?php // auf dem aktiven (blauen) Knopf heller Zaehler, sonst war die Zahl kaum lesbar ?>
                                <span class="badge ms-1 <?php echo $filter === $key ? 'bg-white text-primary' : 'bg-secondary-lt'; ?>"><?php echo $counts[$key] ?? 0; ?></span>
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
                            <span class="badge bg-blue-lt ms-2" title="Shopify zeigt den Bestand des Zielsystems minus diese Anzahl – das gilt als synchron.">Sicherheitsbestand <?php echo (int) $report['buffer']; ?></span>
                        <?php endif; ?>
                    </h3>
                    <form method="get" class="d-flex align-items-center gap-2">
                        <a href="product_check.php?shop=<?php echo urlencode($shop); ?><?php echo isset($_GET['preview']) ? '&amp;preview=1' : ''; ?>" class="btn btn-sm btn-outline-primary me-2"><?php echo \Connect\Ui\Icon::svg('list-check', 16, 'me-1'); ?>Produkt-Check</a>
                        <a href="bundles.php?shop=<?php echo urlencode($shop); ?><?php echo isset($_GET['preview']) ? '&amp;preview=1' : ''; ?>" class="btn btn-sm btn-outline-primary me-2"><?php echo \Connect\Ui\Icon::svg('packages', 16, 'me-1'); ?>Sets</a>
                        <input type="hidden" name="shop" value="<?php echo htmlspecialchars($shop); ?>">
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
                    <div class="card-body text-secondary">Keine Produkte in dieser Ansicht.</div>
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
                                        <th class="text-end">Bestand Zielsystem</th>
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
                                            <td><?php echo $editableCell('title', $row['label'] ?? $row['match_value']); ?></td>
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
                                                        <div class="small text-warning text-nowrap" title="Preis im Zielsystem">Kasse: <?php echo htmlspecialchars(number_format((float) $row['target_price'], 2, ',', '.')); ?></div>
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
                                                        <?php $manualFieldTitle = htmlspecialchars($manualInputField['label']) . ' -- wird beim Import an das Zielsystem übermittelt.' . ($manualInputField['default_value'] !== null ? ' Vorbelegt aus dem Standard-Steuersatz unter Einstellungen -- pro Produkt änderbar.' : ''); ?>
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
                                <a class="page-link" href="?shop=<?php echo urlencode($shop); ?>&amp;filter=<?php echo urlencode($filter); ?>&amp;page_size=<?php echo $pageSize; ?>&amp;page=<?php echo max(1, $page - 1); ?>">Zurück</a>
                            </li>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?shop=<?php echo urlencode($shop); ?>&amp;filter=<?php echo urlencode($filter); ?>&amp;page_size=<?php echo $pageSize; ?>&amp;page=<?php echo min($totalPages, $page + 1); ?>">Weiter</a>
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

<?php require __DIR__ . '/_footer.php'; ?>