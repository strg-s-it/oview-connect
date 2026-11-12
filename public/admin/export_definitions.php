<?php

declare(strict_types=1);

/**
 * connect/public/admin/export_definitions.php
 *
 * Export der Connector-Builder-Definitionen als JSON-Datei, damit der aktuelle Stand der Datenbank
 * (der durch Migrationen UND Handpflege im Builder entsteht) weitergegeben werden kann, z.B. an
 * Claude Code -- die Migrationen im Repo allein zeigen ihn nicht (siehe 0110, die eine Abfrage
 * aus 0106 ueberschrieben hat).
 *
 * Enthalten (shop-uebergreifend, rein lesend): Connector Types, Objects (inkl. Belegprofil),
 * Felder, Operationen (inkl. body_template), Mapping-Vorlagen mit Feld-Mappings, Funktionen,
 * Lookup-Tabellen, Job-Vorlagen und die eingespielten Migrationen.
 *
 * NICHT enthalten: Connections (Zugangsdaten), Shops, Aktivierungen, Cache, Protokolle -- alles
 * mit Haendlerbezug. Spalten, deren Name nach Geheimnis klingt (password, secret, token,
 * credential, encrypted, private), werden zusaetzlich bei jeder Tabelle weggelassen.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;

$config = Config::load();
$pdo = Database::connect($config)->pdo();
$auth = new AdminAuth($pdo);
$auth->requireLogin();

/** Tabelle => Sortierung. Jede Tabelle wird einzeln gelesen: fehlt eine, fehlt nur sie im Export. */
const DEFINITION_TABLES = [
    'connect_connector_types' => 'id',
    'connect_objects' => 'id',
    'connect_object_fields' => 'id',
    'connect_object_operations' => 'id',
    'connect_mapping_templates' => 'id',
    'connect_field_mappings' => 'id',
    'connect_functions' => 'id',
    'connect_lookup_tables' => 'id',
    'connect_lookup_table_entries' => 'id',
    'connect_sync_job_templates' => 'id',
    'connect_schema_migrations' => 'migration',
];

const SECRET_COLUMN_PATTERN = '/(password|secret|token|credential|encrypted|private)/i';

/** Laengere Textwerte werden im Export durch einen Platzhalter ersetzt (Logos, Base64-Bilder). */
const MAX_VALUE_LENGTH = 20000;

/**
 * @return array{rows: list<array<string, mixed>>, omitted: list<string>}|null null = Tabelle nicht lesbar
 */
function readDefinitionTable(PDO $pdo, string $table, string $orderBy): ?array
{
    try {
        $rows = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY ' . $orderBy)->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable) {
        return null;
    }

    $omitted = [];
    foreach ($rows as $index => $row) {
        foreach ($row as $column => $value) {
            if (preg_match(SECRET_COLUMN_PATTERN, (string) $column) === 1) {
                $omitted[(string) $column] = true;
                unset($rows[$index][$column]);
                continue;
            }
            // Sehr lange Werte (z.B. Logos als Base64 in logo_data) bringen beim Lesen nichts
            // und blaehen die Datei auf: durch Platzhalter mit der Laenge ersetzen.
            if (is_string($value) && strlen($value) > MAX_VALUE_LENGTH) {
                $rows[$index][$column] = '[gekuerzt: ' . strlen($value) . ' Zeichen]';
            }
        }
    }

    return ['rows' => array_values($rows), 'omitted' => array_keys($omitted)];
}

if (($_GET['download'] ?? '') === '1') {
    $export = [
        'meta' => [
            'exported_at' => gmdate('c'),
            'exported_by' => (string) $auth->currentEmail(),
            'note' => 'Shopuebergreifende Connector-Builder-Definitionen. Keine Connections, Zugangsdaten oder Haendlerdaten.',
        ],
        'tables' => [],
    ];
    foreach (DEFINITION_TABLES as $table => $orderBy) {
        $result = readDefinitionTable($pdo, $table, $orderBy);
        if ($result === null) {
            $export['tables'][$table] = ['missing' => true];
            continue;
        }
        $export['tables'][$table] = [
            'count' => count($result['rows']),
            'omitted_columns' => $result['omitted'],
            'rows' => $result['rows'],
        ];
    }

    $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) {
        http_response_code(500);
        echo 'Export konnte nicht erzeugt werden.';
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="oview-connect-definitionen-' . date('Ymd-His') . '.json"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $json;
    exit;
}

// Uebersicht: Zeilenzahl je Tabelle
$counts = [];
foreach (DEFINITION_TABLES as $table => $orderBy) {
    try {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    } catch (\Throwable) {
        $counts[$table] = null;
    }
}

$pageTitle = 'Definitionen exportieren';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = []; // kein Shop-Bezug auf dieser Seite
$flashMessage = null;
$flashType = 'info';
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row g-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Definitionen exportieren</h3>
            </div>
            <div class="card-body">
                <p class="text-secondary">
                    Lädt den aktuellen Stand der Connector-Builder-Definitionen als JSON-Datei herunter: Connector Types, Objects
                    (mit Belegprofil), Felder, Operationen (mit Abfrage-Text), Mapping-Vorlagen, Funktionen, Lookup-Tabellen,
                    Job-Vorlagen und die eingespielten Migrationen. So lässt sich prüfen, wie die Datenbank wirklich aussieht
                    – auch nach Änderungen von Hand im Builder.
                </p>
                <p class="text-secondary">
                    <strong>Nicht enthalten:</strong> Connections und Zugangsdaten, Shops, Aktivierungen, Cache, Protokolle. Spalten
                    mit „password“, „secret“, „token“, „credential“, „encrypted“ oder „private“ im Namen werden weggelassen.
                </p>
                <a href="export_definitions.php?download=1" class="btn btn-primary">JSON herunterladen</a>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Inhalt</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead><tr><th>Tabelle</th><th class="text-end">Zeilen</th></tr></thead>
                    <tbody>
                    <?php foreach ($counts as $table => $count): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($table); ?></code></td>
                            <td class="text-end"><?php echo $count === null ? '<span class="badge bg-yellow-lt">nicht lesbar</span>' : (int) $count; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
