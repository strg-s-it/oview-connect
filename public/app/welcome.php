<?php

declare(strict_types=1);

/**
 * connect/public/app/welcome.php
 *
 * Einrichtung fuer neue Merchants (Migration 0078, Connect\Setup\SetupWizard): eine einfache
 * Frage pro Fenster -- Willkommen + Rechtstexte, Software waehlen, verbinden, Produkte
 * erkennen, Lagerbestand ja/nein, wo gepflegt, was bei bezahlter Bestellung, Netto/Brutto,
 * Zusammenfassung. Erst "oView starten" macht die Antworten wirksam und gibt das Dashboard
 * frei (bootstrap.php, Connect\Setup\WelcomeGate).
 *
 * Eingerichtete Shops sehen hier nur noch neue Versionen der Rechtstexte.
 *
 * Jeder Schritt speichert per POST und leitet auf den naechsten weiter (?step=...).
 */

use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Setup\LegalTexts;
use Connect\Setup\SetupWizard;
use Connect\Setup\ShopActivations;
use Connect\Setup\TargetSystemSetup;
use Connect\Setup\WelcomeGate;
use Connect\Shopify\TokenRefresher;

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';

$pdo = $app->pdo;
$shop = $app->shop;
$q = '?shop=' . urlencode($shop);
$dashboardUrl = 'dashboard.php' . $q;

$wizard = new SetupWizard($pdo);
$legal = new LegalTexts($pdo);
$pending = $legal->pendingForShop($shop);

if (!$wizard->isAvailable()) {
    if (!WelcomeGate::isRequired($pdo, $shop)) {
        header('Location: ' . \Connect\Http\Url::withToken($dashboardUrl)); // z.B. "Assistent starten" vor Migration 0078
        exit;
    }
    \Connect\Http\ErrorPage::show(503, 'Einrichtung wird vorbereitet', 'oView Connect wird gerade aktualisiert. Bitte versuche es in ein paar Minuten noch einmal.');
}

// Einrichtung erneut durchgehen (eingerichteter Shop, Link in Einstellungen/Status): mit den
// aktuellen Einstellungen vorbelegt, wirksam erst mit "Übernehmen", Abbrechen aendert nichts.
// Das Dashboard bleibt waehrenddessen erreichbar (setup_completed_at bleibt gesetzt).
$rerunKey = 'connect_wizard_rerun';
if (isset($_GET['cancel'])) {
    unset($_SESSION[$rerunKey]);
    header('Location: ' . \Connect\Http\Url::withToken($dashboardUrl));
    exit;
}
if (isset($_GET['rerun']) && $wizard->isCompleted($shop) && $pending === []) {
    $wizard->startRerun($shop);
    $_SESSION[$rerunKey] = $shop;
    header('Location: ' . \Connect\Http\Url::withToken('welcome.php' . $q . '&wz=rerun&step=software'));
    exit;
}
// Der Merkzettel "erneut durchgehen" steckt in der Session -- ohne Session-Cookie (cookielose Anmeldung) reist er
// stattdessen als Parameter wz=rerun in den Schritt-Adressen und Formularen mit.
$rerun = (($_SESSION[$rerunKey] ?? null) === $shop || (string) ($_GET['wz'] ?? '') === 'rerun') && $wizard->isCompleted($shop);
if ($rerun) {
    $q .= '&wz=rerun';
}

if (!$rerun && !WelcomeGate::isRequired($pdo, $shop) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . \Connect\Http\Url::withToken($dashboardUrl));
    exit;
}

// Eingerichteter Shop: nur neue Rechtstexte bestaetigen
$legalOnly = $wizard->isCompleted($shop) && !$rerun;

$stepUrl = static fn (string $step): string => 'welcome.php' . $q . '&step=' . urlencode($step);
$redirect = static function (string $url): never {
    header('Location: ' . \Connect\Http\Url::withToken($url));
    exit;
};
$nextStepAfter = static function (string $step) use ($wizard, $shop): string {
    $visible = $wizard->visibleSteps($shop);
    $index = array_search($step, $visible, true);

    return $index !== false && isset($visible[$index + 1]) ? $visible[$index + 1] : $wizard->firstOpenStep($shop);
};
$buildFactory = static function () use ($pdo, $app): ConnectorFactory {
    $tokenRefresher = TokenRefresher::fromConfig($app->config);

    return new ConnectorFactory($pdo, $app->crypto, new HttpClient(), $tokenRefresher);
};

// Abo kennen, bevor die Einrichtung den Lagerabgleich anlegt (Haeufigkeit 60/30/15 Min.)
(new \Connect\Billing\BillingService($pdo, $buildFactory(), $app->config))->refreshIfStale($shop);

$flashMessage = null;
$flashType = 'danger';
$connectFailed = false;

// -----------------------------------------------------------------------
// POST: Antwort eines Schritts speichern
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'wizard') {
    $postedStep = (string) ($_POST['step'] ?? '');

    switch ($postedStep) {
        case 'welcome':
            $accepted = array_keys(is_array($_POST['accept'] ?? null) ? $_POST['accept'] : []);
            $missing = array_diff(array_keys($pending), $accepted);
            if ($missing !== []) {
                $flashMessage = 'Bitte noch zustimmen: ' . implode(', ', array_map(static fn (string $k): string => LegalTexts::DOCS[$k][0], $missing)) . '.';
                break;
            }
            foreach ($pending as $docKey => $doc) {
                $legal->accept($shop, $docKey, (int) $doc['version'], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
            }
            $wizard->remember($shop, ['welcome' => date('c')]);
            $redirect($legalOnly ? $dashboardUrl : $stepUrl($wizard->firstOpenStep($shop)));

        case 'software':
            $typeId = (int) ($_POST['connector_type_id'] ?? 0);
            $typeIds = array_map('intval', array_column(TargetSystemSetup::availableTypes($pdo, $shop), 'id'));
            if (!in_array($typeId, $typeIds, true)) {
                $flashMessage = 'Bitte wähle deine Software aus.';
                break;
            }
            $wizard->remember($shop, ['type_id' => $typeId]);
            $redirect($stepUrl('connect'));

        case 'connect':
            // Bewusst ohne erfolgreichen Test weitermachen: merken, damit der Schritt beim Neuladen nicht
            // wieder auftaucht (er zaehlt sonst nur mit aktiver Verbindung als erledigt)
            if (!empty($_POST['skip'])) {
                $wizard->remember($shop, ['connect_skipped' => date('c')]);
                $redirect($stepUrl($nextStepAfter('connect')));
            }
            // Bereits verbunden und nichts Neues eingetragen: Zugangsdaten nicht mit Leerwerten ueberschreiben
            $chosenTypeId = (int) ($wizard->answers($shop)['type_id'] ?? 0);
            $typedCredentials = false;
            foreach (TargetSystemSetup::availableTypes($pdo, $shop) as $type) {
                if ((int) $type['id'] !== $chosenTypeId) {
                    continue;
                }
                foreach (json_decode((string) $type['auth_field_schema'], true) ?: [] as $field) {
                    if (($field['type'] ?? 'text') !== 'hidden' && trim((string) ($_POST['credentials'][$field['key']] ?? '')) !== '') {
                        $typedCredentials = true;
                    }
                }
            }
            $connectedShop = $wizard->shop($shop);
            if (!$typedCredentials && ($connectedShop['target_connection_id'] ?? null) !== null
                && (int) ($connectedShop['target_type_id'] ?? 0) === $chosenTypeId) {
                $redirect($stepUrl($nextStepAfter('connect')));
            }
            $result = TargetSystemSetup::connect(
                $pdo,
                $app->crypto,
                $buildFactory(),
                $shop,
                (int) ($wizard->answers($shop)['type_id'] ?? 0),
                is_array($_POST['credentials'] ?? null) ? $_POST['credentials'] : []
            );
            if ($result['connected']) {
                $redirect($stepUrl($nextStepAfter('connect')));
            }
            $flashMessage = $result['ok']
                ? 'Die Verbindung hat noch nicht geklappt. Bitte prüfe den Schlüssel und versuche es noch einmal.'
                : $result['message'];
            $connectFailed = $result['ok'];
            break;

        case 'compare':
            $compare = (string) ($_POST['compare'] ?? '');
            if (!isset(SetupWizard::COMPARE_LABELS[$compare])) {
                $flashMessage = 'Bitte wähle aus, woran wir deine Produkte erkennen sollen.';
                break;
            }
            $wizard->remember($shop, ['compare' => $compare]);
            $redirect($stepUrl($nextStepAfter('compare')));

        case 'stock':
            $stock = (string) ($_POST['stock'] ?? '');
            if (!in_array($stock, ['yes', 'no'], true)) {
                $flashMessage = 'Bitte wähle Ja oder Nein.';
                break;
            }
            $wizard->remember($shop, ['stock' => $stock]);
            $redirect($stepUrl($nextStepAfter('stock')));

        case 'stock_lead':
            $lead = (string) ($_POST['lead'] ?? '');
            $locations = $wizard->shopifyLocations($shop);
            $location = (string) ($_POST['location'] ?? '');
            if (!in_array($lead, ['target', 'shopify'], true)) {
                $flashMessage = 'Bitte wähle, wo du deine Lagerbestände änderst.';
                break;
            }
            // Der Standort wird in BEIDE Richtungen gebraucht: Shopify liefert den Bestand
            // je Standort, ohne Standort kennt oView den Shopify-Bestand nicht.
            if ($locations === []) {
                // Direkt nach der Installation sind die Standorte noch nicht geladen -- nicht ohne Standort weiter
                $flashMessage = 'Die Shopify-Standorte werden gerade geladen. Bitte gleich noch einmal auf „Weiter“ klicken.';
                break;
            }
            if (count($locations) === 1) {
                $location = $locations[0]['id'];
            } elseif (!in_array($location, array_column($locations, 'id'), true)) {
                $flashMessage = 'Bitte wähle den Shopify-Standort.';
                break;
            }
            $wizard->remember($shop, ['lead' => $lead, 'location' => $location]);
            $redirect($stepUrl($nextStepAfter('stock_lead')));

        case 'invoice':
            $invoice = (string) ($_POST['invoice'] ?? '');
            $allowed = $wizard->supportsStockOnly($shop) ? ['invoice', 'stockonly', 'none'] : ['invoice', 'none'];
            if (!in_array($invoice, $allowed, true)) {
                $flashMessage = 'Bitte wähle, was bei einer bezahlten Bestellung passieren soll.';
                break;
            }
            $wizard->remember($shop, ['invoice' => $invoice, 'mail' => $invoice === 'invoice' && isset($_POST['mail']) ? 1 : 0]);
            $redirect($stepUrl($nextStepAfter('invoice')));

        case 'prices':
            $prices = (string) ($_POST['prices'] ?? '');
            if (!in_array($prices, ['0', '1'], true)) {
                $flashMessage = 'Bitte wähle Brutto oder Netto.';
                break;
            }
            $wizard->remember($shop, ['prices' => $prices]);
            $redirect($stepUrl($nextStepAfter('prices')));

        case 'done':
            if ($wizard->firstOpenStep($shop) !== 'done') {
                $redirect($stepUrl($wizard->firstOpenStep($shop)));
            }
            $notes = $wizard->complete($shop, new ShopActivations($pdo, $app->crypto, $app->config));
            $okText = $rerun ? 'Deine Änderungen sind übernommen.' : 'oView ist gestartet. Der erste Abgleich läuft in den nächsten Minuten.';
            $_SESSION['connect_flash'] = $notes === [] ? ['success', $okText] : ['warning', $okText . ' Aber: ' . implode(' ', $notes)];
            unset($_SESSION[$rerunKey]);
            $redirect($dashboardUrl);
    }
}

// -----------------------------------------------------------------------
// Anzeige: welcher Schritt?
// -----------------------------------------------------------------------
$step = $legalOnly ? 'welcome' : (string) ($_GET['step'] ?? '');
if (!$legalOnly && (!in_array($step, SetupWizard::STEPS, true) || !$wizard->isReachable($shop, $step))) {
    $step = $wizard->firstOpenStep($shop);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($postedStep) && in_array($postedStep, SetupWizard::STEPS, true)) {
    $step = $postedStep; // Fehler beim Speichern: auf demselben Schritt bleiben
}

$answers = $wizard->answers($shop);
$shopRow = $wizard->shop($shop);
$types = TargetSystemSetup::availableTypes($pdo, $shop);
$typeById = [];
foreach ($types as $type) {
    $typeById[(int) $type['id']] = $type;
}
$chosenType = $typeById[(int) ($answers['type_id'] ?? $shopRow['target_type_id'] ?? 0)] ?? null;
$targetName = (string) ($shopRow['target_name'] ?? ($chosenType['name'] ?? 'deiner Software'));

$visible = $wizard->visibleSteps($shop);
$position = array_search($step, $visible, true);
$backUrl = !$legalOnly && $position !== false && $position > 0 && !($rerun && $visible[$position - 1] === 'welcome')
    ? $stepUrl($visible[$position - 1]) : null;
if ($rerun && $step === 'welcome') {
    $step = 'software';
}
$phases = array_values(array_unique(SetupWizard::PHASES));
$phaseIndex = (int) array_search(SetupWizard::PHASES[$step], $phases, true);

// Auswahl-Kachel (Radio) im Stil der Einrichtung
$choice = static function (string $name, string $value, ?string $current, string $icon, string $title, string $desc, ?string $tag = null, bool $required = true): string {
    return '<label class="wz-choice">'
        . '<input type="radio" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '"' . ($current === $value ? ' checked' : '') . ($required ? ' required' : '') . '>'
        . '<span class="wz-choice-body"><span class="wz-ic">' . $icon . '</span><span>'
        . '<span class="wz-t">' . htmlspecialchars($title) . ($tag !== null ? ' <span class="badge bg-green-lt">' . htmlspecialchars($tag) . '</span>' : '') . '</span>'
        . '<span class="wz-d">' . htmlspecialchars($desc) . '</span></span></span></label>';
};

$pageTitle = 'Willkommen';
$navItems = [];
require __DIR__ . '/_header.php';
?>

<style>
    .page-header { display: none; }
    .wz { max-width: 640px; margin: 1rem auto 3rem; }
    .wz-card { background: var(--tblr-bg-surface, #fff); border: 1px solid var(--tblr-border-color, #e6e7e9); border-radius: 14px; padding: 2rem; display: flex; flex-direction: column; gap: 1.4rem; }
    .wz-steps { display: flex; gap: 6px; }
    .wz-steps span { flex: 1; height: 4px; border-radius: 2px; background: var(--tblr-border-color, #e6e7e9); }
    .wz-steps span.on { background: var(--tblr-primary, #206bc4); }
    .wz-label { font-size: .75rem; letter-spacing: .05em; text-transform: uppercase; color: var(--tblr-secondary, #667382); margin-top: .5rem; }
    .wz h2 { font-size: 1.5rem; font-weight: 700; margin: 0; text-wrap: balance; }
    .wz .lead { color: var(--tblr-secondary, #667382); font-size: 1rem; margin: .5rem 0 0; }
    .wz-choices { display: grid; gap: .6rem; }
    .wz-choice { display: block; cursor: pointer; margin: 0; }
    .wz-choice input { position: absolute; opacity: 0; pointer-events: none; }
    .wz-choice-body { display: flex; gap: .9rem; align-items: flex-start; border: 1.5px solid var(--tblr-border-color, #e6e7e9); border-radius: 10px; padding: .85rem 1rem; }
    .wz-choice:hover .wz-choice-body { border-color: var(--tblr-primary, #206bc4); }
    .wz-choice input:checked + .wz-choice-body { border-color: var(--tblr-primary, #206bc4); background: var(--tblr-primary-lt, #e9f0f9); }
    .wz-choice input:focus-visible + .wz-choice-body { outline: 2px solid var(--tblr-primary, #206bc4); outline-offset: 2px; }
    .wz-ic { flex: none; width: 40px; height: 40px; border-radius: 9px; background: var(--tblr-bg-surface-secondary, #f6f8fb); display: grid; place-items: center; font-weight: 700; color: var(--tblr-primary, #206bc4); overflow: hidden; }
    .wz-ic img { max-width: 34px; max-height: 34px; object-fit: contain; }
    .wz-t { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; font-weight: 600; }
    .wz-d { display: block; color: var(--tblr-secondary, #667382); font-size: .875rem; }
    .wz-legal { border: 1px solid var(--tblr-border-color, #e6e7e9); border-radius: 10px; padding: .8rem 1rem; }
    .wz-legal .legal-text { max-height: 16rem; overflow-y: auto; }
    .wz-nav { display: flex; justify-content: space-between; align-items: center; gap: .6rem; }
    .wz-summary { border: 1px solid var(--tblr-border-color, #e6e7e9); border-radius: 10px; }
    .wz-summary > div { display: flex; justify-content: space-between; gap: .8rem; padding: .7rem 1rem; border-top: 1px solid var(--tblr-border-color, #e6e7e9); flex-wrap: wrap; }
    .wz-summary > div:first-child { border-top: 0; }
    .wz-summary span:first-child { color: var(--tblr-secondary, #667382); }
    @media (max-width: 480px) { .wz-card { padding: 1.25rem; } .wz h2 { font-size: 1.3rem; } }
</style>

<div class="wz">
<form method="post" autocomplete="off" class="wz-card">
    <input type="hidden" name="action" value="wizard">
    <input type="hidden" name="step" value="<?php echo htmlspecialchars($step); ?>">

    <div class="d-flex justify-content-between align-items-center gap-2">
        <span class="fw-bold d-flex align-items-center"><?php echo $brand->logoHtml(28); ?></span>
        <?php if ($rerun): ?>
            <a href="welcome.php<?php echo htmlspecialchars($q); ?>&amp;cancel=1" class="small">Abbrechen, nichts ändern</a>
        <?php endif; ?>
    </div>
    <?php if (!$legalOnly): ?>
        <div>
            <div class="wz-steps" aria-hidden="true">
                <?php foreach ($phases as $i => $phase): ?><span class="<?php echo $i <= $phaseIndex ? 'on' : ''; ?>"></span><?php endforeach; ?>
            </div>
            <div class="wz-label">Schritt <?php echo $phaseIndex + 1; ?> von <?php echo count($phases); ?> · <?php echo htmlspecialchars($phases[$phaseIndex]); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($flashMessage !== null): ?>
        <div class="alert alert-<?php echo $connectFailed ? 'warning' : 'danger'; ?> mb-0"><?php echo htmlspecialchars($flashMessage); ?></div>
    <?php endif; ?>

    <?php if ($step === 'welcome'): ?>
        <div>
            <?php if ($legalOnly): ?>
                <h2>Unsere Bedingungen wurden aktualisiert</h2>
                <p class="lead">Bitte lies die geänderten Texte und stimme zu, dann geht es weiter.</p>
            <?php else: ?>
                <h2>Willkommen bei <?php echo htmlspecialchars($brand->name); ?></h2>
                <p class="lead">oView hält deinen Shopify-Shop und deine Kasse oder Warenwirtschaft automatisch abgestimmt: Lagerbestände, Rechnungen und Produkte. Die Einrichtung dauert etwa drei Minuten, du beantwortest nur ein paar Fragen.</p>
            <?php endif; ?>
        </div>
        <?php if ($pending !== []): ?>
            <div class="d-grid gap-2">
                <?php foreach ($pending as $docKey => $doc): ?>
                    <div class="wz-legal">
                        <label class="form-check mb-1">
                            <input type="checkbox" name="accept[<?php echo htmlspecialchars($docKey); ?>]" class="form-check-input" required>
                            <span class="form-check-label"><?php echo htmlspecialchars(LegalTexts::DOCS[$docKey][1]); ?></span>
                        </label>
                        <?php if (($doc['summary'] ?? '') !== ''): ?>
                            <div class="text-secondary small ms-4"><?php echo htmlspecialchars((string) $doc['summary']); ?></div>
                        <?php endif; ?>
                        <details class="ms-4 mt-1">
                            <summary class="text-primary small" style="cursor:pointer;"><?php echo htmlspecialchars((string) $doc['title']); ?> lesen</summary>
                            <div class="legal-text mt-2 small"><?php echo LegalTexts::render((string) $doc['body']); ?></div>
                        </details>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($step === 'software'): ?>
        <div>
            <h2>Welche Software nutzt du neben Shopify?</h2>
            <p class="lead">Mit dieser Software gleicht oView deinen Shop ab.</p>
        </div>
        <?php if ($types === []): ?>
            <div class="alert alert-info mb-0">Noch keine Software verfügbar. Bitte schreib uns an office@oview.at.</div>
        <?php else: ?>
            <div class="wz-choices">
                <?php foreach ($types as $type): ?>
                    <?php
                    $logoSrc = \Connect\Connector\ConnectorLogo::src($type);
                    $icon = $logoSrc !== null
                        ? '<img src="' . htmlspecialchars($logoSrc) . '" alt="">'
                        : htmlspecialchars(mb_substr((string) $type['name'], 0, 1));
                    $current = (string) ($answers['type_id'] ?? $shopRow['target_type_id'] ?? '');
                    echo $choice('connector_type_id', (string) $type['id'], $current, $icon, (string) $type['name'], (string) ($type['description'] ?? ''));
                    ?>
                <?php endforeach; ?>
            </div>
            <p class="text-secondary small mb-0">Deine Software ist nicht dabei? Schreib uns an <strong>office@oview.at</strong>, welche du nutzt.</p>
        <?php endif; ?>

    <?php elseif ($step === 'connect'): ?>
        <?php
        $authFields = $chosenType !== null ? (json_decode((string) $chosenType['auth_field_schema'], true) ?: []) : [];
        $alreadyConnected = ($shopRow['target_connection_id'] ?? null) !== null && $chosenType !== null
            && (int) $shopRow['target_type_id'] === (int) $chosenType['id'] && ($shopRow['target_status'] ?? '') === 'active';
        ?>
        <div>
            <h2><?php echo htmlspecialchars($chosenType['name'] ?? 'Software'); ?> verbinden</h2>
            <p class="lead">oView braucht deine Zugangsdaten, um Artikel und Lagerstände aus <?php echo htmlspecialchars($chosenType['name'] ?? 'deiner Software'); ?> zu lesen<?php echo $chosenType !== null ? ' und Rechnungen anzulegen' : ''; ?>.</p>
        </div>
        <?php if ($chosenType === null): ?>
            <div class="alert alert-info mb-0">Bitte wähle zuerst deine Software. <a href="<?php echo htmlspecialchars($stepUrl('software')); ?>">Zur Auswahl</a></div>
        <?php else: ?>
            <?php if ($alreadyConnected): ?>
                <div class="alert alert-success mb-0">&#10003; Verbunden mit <?php echo htmlspecialchars((string) $chosenType['name']); ?>. Neue Zugangsdaten kannst du unten eintragen, sonst einfach weiter.</div>
            <?php endif; ?>
            <?php // Anleitung je Connector Type (Admin -> Connector Type, Migration 0099) ?>
            <?php echo \Connect\Setup\SetupHelp::details($chosenType['setup_help'] ?? null, 'Wo finde ich die Zugangsdaten für ' . (string) $chosenType['name'] . '?'); ?>
            <div class="d-grid gap-3">
                <?php foreach ($authFields as $field): ?>
                    <?php if (($field['type'] ?? 'text') === 'hidden'): ?>
                        <input type="hidden" name="credentials[<?php echo htmlspecialchars((string) $field['key']); ?>]" value="<?php echo htmlspecialchars((string) ($field['value'] ?? '')); ?>">
                    <?php else: ?>
                        <div>
                            <label class="form-label" for="cred-<?php echo htmlspecialchars((string) $field['key']); ?>"><?php echo htmlspecialchars((string) $field['label']); ?></label>
                            <input id="cred-<?php echo htmlspecialchars((string) $field['key']); ?>"
                                type="<?php echo htmlspecialchars((string) ($field['type'] ?? 'text')); ?>"
                                name="credentials[<?php echo htmlspecialchars((string) $field['key']); ?>]"
                                class="form-control"
                                placeholder="<?php echo htmlspecialchars((string) ($field['placeholder'] ?? '')); ?>"
                                autocomplete="<?php echo ($field['type'] ?? '') === 'password' ? 'new-password' : 'off'; ?>"
                                <?php echo !empty($field['required']) && !$alreadyConnected ? 'required' : ''; ?>>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php if ($connectFailed || (($shopRow['target_connection_id'] ?? null) !== null && !$alreadyConnected)): ?>
                <p class="text-secondary small mb-0">Klappt es nicht? Du kannst auch <button type="submit" name="skip" value="1" formnovalidate class="btn btn-link p-0 align-baseline small">ohne erfolgreichen Test weitermachen</button> und die Verbindung später unter Status prüfen.</p>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($step === 'compare'): ?>
        <?php
        $counts = $wizard->matchCounts($shop);
        $current = (string) ($answers['compare'] ?? $wizard->recommendedCompare($counts));
        $recommended = $wizard->recommendedCompare($counts);
        $icons = ['sku' => 'SKU', 'barcode' => '|||', 'title' => 'Aa'];
        ?>
        <div>
            <h2>Woran erkennen wir, welche Produkte zusammengehören?</h2>
            <?php if ($counts !== null): ?>
                <p class="lead">Wir haben deine Shopify-Produkte mit den Artikeln in <?php echo htmlspecialchars($targetName); ?> verglichen. So viele passen jeweils zusammen:</p>
            <?php else: ?>
                <p class="lead">Wir laden gerade deine Artikel aus <?php echo htmlspecialchars($targetName); ?>. Sobald sie da sind, siehst du hier, wie viele zusammenpassen. Du kannst aber schon weitermachen.</p>
            <?php endif; ?>
        </div>
        <div class="wz-choices">
            <?php foreach (SetupWizard::COMPARE_LABELS as $role => $label): ?>
                <?php
                if ($counts !== null && !isset($counts[$role])) {
                    continue; // dieses System kennt den Vergleichswert nicht
                }
                $desc = $counts !== null
                    ? $counts[$role]['matched'] . ' von ' . $counts[$role]['total'] . ' Artikeln gefunden'
                    : ['sku' => 'Die Artikelnummer, die du in beiden Systemen pflegst.', 'barcode' => 'Der Strichcode auf der Verpackung.', 'title' => 'Nur wenn die Namen überall exakt gleich sind.'][$role];
                echo $choice('compare', $role, $current, $icons[$role], $label, $desc, $role === $recommended ? 'Empfohlen' : null);
                ?>
            <?php endforeach; ?>
        </div>
        <p class="text-secondary small mb-0">Artikel ohne Gegenstück siehst du später unter „Produkte“ und kannst sie dort anlegen.</p>
        <?php if ($counts === null): ?>
            <script>
                // Artikel werden noch geladen: alle 20 s neu laden, solange nichts angeklickt wurde
                (function () {
                    var touched = false;
                    document.querySelectorAll('input[name="compare"]').forEach(function (r) { r.addEventListener('change', function () { touched = true; }); });
                    setTimeout(function () { if (!touched) { (window.connectReload || function () { location.reload(); })(); } }, 20000);
                })();
            </script>
        <?php endif; ?>

    <?php elseif ($step === 'stock'): ?>
        <div>
            <h2>Sollen die Lagerbestände automatisch abgeglichen werden?</h2>
            <p class="lead">Dann stimmen die Stückzahlen in Shopify und <?php echo htmlspecialchars($targetName); ?> überein, ohne dass du sie doppelt pflegst.</p>
        </div>
        <div class="wz-choices">
            <?php
            $stockMinutes = \Connect\Billing\Plans::stockMinutes(\Connect\Billing\Plans::forShop($pdo, $shop));
            echo $choice('stock', 'yes', $answers['stock'] ?? null, '&#10003;', 'Ja, automatisch abgleichen', $stockMinutes === 60 ? 'oView prüft die Bestände jede Stunde.' : 'oView prüft die Bestände alle ' . $stockMinutes . ' Minuten.');
            ?>
            <?php echo $choice('stock', 'no', $answers['stock'] ?? null, '&ndash;', 'Nein, jetzt nicht', 'Du kannst das jederzeit in den Einstellungen einschalten.'); ?>
        </div>

    <?php elseif ($step === 'stock_lead'): ?>
        <?php $locations = $wizard->shopifyLocations($shop); ?>
        <div>
            <h2>Wo änderst du deine Lagerbestände?</h2>
            <p class="lead">Dort, wo du Wareneingänge und Korrekturen einträgst. Das andere System übernimmt die Zahlen.</p>
        </div>
        <div class="wz-choices">
            <?php echo $choice('lead', 'target', $answers['lead'] ?? null, htmlspecialchars(mb_substr($targetName, 0, 2)), 'In ' . $targetName, 'Shopify übernimmt die Bestände aus ' . $targetName . '.'); ?>
            <?php echo $choice('lead', 'shopify', $answers['lead'] ?? null, 'S', 'In Shopify', $targetName . ' übernimmt die Bestände aus Shopify.'); ?>
        </div>
        <?php if ($locations === []): ?>
            <div class="alert alert-info mb-0">
                Die Standorte deines Shops werden gerade aus Shopify geladen. Das dauert nur einen Moment, danach kannst du hier den Standort wählen.
            </div>
            <script>setTimeout(function () { (window.connectReload || function () { location.reload(); })(); }, 6000);</script>
        <?php elseif (count($locations) === 1): ?>
            <div class="text-secondary small">Dein Shop hat einen Standort: <strong><?php echo htmlspecialchars($locations[0]['name']); ?></strong>. Abgeglichen wird dessen Bestand.</div>
        <?php else: ?>
            <div id="wz-location">
                <label class="form-label" for="location">Für welchen Shopify-Standort?</label>
                <select id="location" name="location" class="form-select" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo htmlspecialchars($location['id']); ?>" <?php echo ($answers['location'] ?? '') === $location['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($location['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="text-secondary small mt-1">Dein Shop hat <?php echo count($locations); ?> Standorte. Abgeglichen wird der Bestand dieses Standorts.</div>
            </div>
        <?php endif; ?>

    <?php elseif ($step === 'invoice'): ?>
        <?php $mailChecked = !empty($answers['mail']); ?>
        <div>
            <h2>Was soll passieren, wenn eine Bestellung bezahlt ist?</h2>
        </div>
        <div class="wz-choices">
            <?php echo $choice('invoice', 'invoice', $answers['invoice'] ?? null, '&euro;', 'Rechnung in ' . $targetName . ' erstellen', 'Die Rechnung erscheint in ' . $targetName . ', der Bestand wird dort abgebucht.', 'Häufigste Wahl'); ?>
            <?php if ($wizard->supportsStockOnly($shop)): ?>
                <?php echo $choice('invoice', 'stockonly', $answers['invoice'] ?? null, '&minus;', 'Nur den Lagerbestand abziehen', 'Keine Rechnung in ' . $targetName . ', nur die verkauften Stück werden abgebucht.'); ?>
            <?php endif; ?>
            <?php echo $choice('invoice', 'none', $answers['invoice'] ?? null, '&ndash;', 'Nichts', 'Bestellungen bleiben nur in Shopify.'); ?>
        </div>
        <label class="wz-legal form-check mb-0 ps-5" id="wz-mail" <?php echo ($answers['invoice'] ?? null) === 'invoice' ? '' : 'hidden'; ?>>
            <input type="checkbox" name="mail" class="form-check-input" <?php echo $mailChecked ? 'checked' : ''; ?>>
            <span class="form-check-label">Rechnung als PDF auch per E-Mail an den Kunden schicken
                <span class="d-block text-secondary small">Absender, Betreff und Text kannst du danach in den Einstellungen anpassen.</span></span>
        </label>
        <script>
            document.querySelectorAll('input[name="invoice"]').forEach(function (radio) {
                radio.addEventListener('change', function () { document.getElementById('wz-mail').hidden = radio.value !== 'invoice'; });
            });
        </script>

    <?php elseif ($step === 'prices'): ?>
        <?php
        $fromShopify = $shopRow['shopify_prices_include_tax'] ?? null;
        $current = (string) ($answers['prices'] ?? ($fromShopify !== null ? (string) (int) $fromShopify : ''));
        ?>
        <div>
            <h2>Sind deine Shopify-Preise inklusive Mehrwertsteuer?</h2>
            <p class="lead">
                <?php if ($fromShopify !== null): ?>
                    Laut deinem Shop: <strong><?php echo (int) $fromShopify === 1 ? 'ja, inklusive MwSt.' : 'nein, ohne MwSt.'; ?></strong>
                <?php endif; ?>
                Das ist wichtig, damit die Beträge auf der Rechnung stimmen.
            </p>
        </div>
        <div class="wz-choices">
            <?php echo $choice('prices', '1', $current, '&#10003;', 'Ja, inklusive MwSt. (Brutto)', 'So rechnen die meisten Shops.', $fromShopify !== null && (int) $fromShopify === 1 ? 'Aus Shopify' : null); ?>
            <?php echo $choice('prices', '0', $current, '%', 'Nein, ohne MwSt. (Netto)', 'Die Mehrwertsteuer kommt an der Kasse dazu.', $fromShopify !== null && (int) $fromShopify === 0 ? 'Aus Shopify' : null); ?>
        </div>

    <?php elseif ($step === 'done'): ?>
        <?php
        $lead = ($answers['lead'] ?? 'target') === 'shopify' ? 'shopify' : 'target';
        $locationName = ' · Standort fehlt';
        foreach ($wizard->shopifyLocations($shop) as $location) {
            if ($location['id'] === ($answers['location'] ?? '')) {
                $locationName = ' · ' . $location['name'];
            }
        }
        $rows = [
            ['Verbunden mit', $targetName, 'connect'],
            ['Produkte erkennen an', SetupWizard::COMPARE_LABELS[$answers['compare'] ?? 'sku'] ?? 'Artikelnummer (SKU)', 'compare'],
            ['Lagerbestand', ($answers['stock'] ?? 'no') === 'yes' ? ('wird in ' . ($lead === 'target' ? $targetName : 'Shopify') . ' gepflegt' . $locationName) : 'kein Abgleich', 'stock'],
            ['Bezahlte Bestellung', ['invoice' => 'Rechnung in ' . $targetName . (!empty($answers['mail']) ? ' + E-Mail an Kunden' : ''), 'stockonly' => 'nur Bestand abziehen', 'none' => 'nichts'][$answers['invoice'] ?? 'none'] ?? 'nichts', 'invoice'],
        ];
        if (($answers['invoice'] ?? null) === 'invoice') {
            $rows[] = ['Preise in Shopify', ($answers['prices'] ?? '1') === '1' ? 'inkl. MwSt.' : 'ohne MwSt.', 'prices'];
        }
        ?>
        <div>
            <h2><?php echo $rerun ? 'Deine Einstellungen' : 'Alles bereit'; ?></h2>
            <p class="lead"><?php echo $rerun ? 'So arbeitet oView nach dem Übernehmen für dich.' : 'So arbeitet oView ab jetzt für dich. Du kannst alles später in den Einstellungen ändern.'; ?></p>
        </div>
        <div class="wz-summary">
            <?php foreach ($rows as [$label, $value, $editStep]): ?>
                <div><span><?php echo htmlspecialchars($label); ?></span><span><strong><?php echo htmlspecialchars($value); ?></strong> · <a href="<?php echo htmlspecialchars($stepUrl($editStep)); ?>">ändern</a></span></div>
            <?php endforeach; ?>
        </div>
        <?php if (!$rerun): ?>
            <div class="alert alert-info mb-0">Der erste Abgleich startet sofort und dauert ein paar Minuten. Bis dahin zeigt dir die Übersicht, was gerade passiert.</div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="wz-nav">
        <?php if ($backUrl !== null): ?>
            <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn">Zurück</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <?php
        $nextLabel = match (true) {
            $legalOnly => 'Zustimmen und weiter',
            $step === 'welcome' => 'Los geht’s',
            $step === 'connect' => 'Verbindung prüfen',
            $step === 'done' => $rerun ? 'Übernehmen' : 'oView starten',
            default => 'Weiter',
        };
        ?>
        <?php if ($step === 'connect' && ($alreadyConnected ?? false)): ?>
            <span class="d-flex gap-2">
                <a href="<?php echo htmlspecialchars($stepUrl($nextStepAfter('connect'))); ?>" class="btn btn-primary">Weiter</a>
                <button type="submit" class="btn">Neu verbinden</button>
            </span>
        <?php elseif (!($step === 'connect' && $chosenType === null)): ?>
            <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars($nextLabel); ?></button>
        <?php endif; ?>
    </div>
</form>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
