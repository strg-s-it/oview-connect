<?php

declare(strict_types=1);

namespace Connect\Sync;

use PDO;

/**
 * Dauerhafte Protokollierung fuer Schreibvorgaenge, die sonst nirgends
 * festgehalten werden -- aktuell nur Connect\Sync\ProductImportService
 * (manueller Produkt-Import nach Shopify: "create_product"/"create_variant").
 * Andere Schreibvorgaenge haben bereits eigene, dediziert modellierte Logs
 * (Lagerbestand: connect_stock_sync_log, Uebersetzungen: connect_translation_log)
 * -- diese Klasse dupliziert die NICHT, siehe admin/activity_log.php (fasst
 * alle drei per UNION zusammen).
 *
 * Bewusst eine eigene, sehr kleine Klasse statt in GenericHttpConnector::write()
 * einzuhaengen: die HTTP-Connector-Ebene kennt weder Shop noch fachlichen
 * Vorgang (nur Connection-ID), das waere an der falschen Stelle -- die
 * aufrufende Fachlogik (ProductImportService) hat den noetigen Kontext bereits
 * griffbereit.
 *
 * requestBody/responseBody (Migration 0043): die ROHEN Texte (z.B. der
 * GraphQL-Body inkl. Variablen bzw. die rohe JSON-Antwort, siehe
 * Connect\Connector\WriteResult::$requestBody/$responseBody) -- fuer echtes
 * Debugging im Protokoll (admin/activity_log.php), nicht nur eine
 * zusammengefasste Meldung.
 *
 * syncRunId (Migration 0044): verknuepft mit dem Sync-Lauf (connect_sync_runs),
 * der diesen Schreibvorgang ausgeloest hat -- NULL fuer Vorgaenge ohne
 * Lauf-Kontext (der manuelle Produkt-Import ueber den "Importieren"-Button
 * laeuft nie als Hintergrund-Job). Damit kann admin/job_log.php (Detailansicht
 * EINES Laufs) neben dem Meilenstein-Log auch alle einzelnen Schreibvorgaenge
 * dieses Laufs zeigen -- vorher zwei unverbundene Ansichten.
 */
final class ActivityLog
{
    /**
     * Scheitert NIE laut auffallend -- ein fehlgeschlagenes Log-INSERT darf
     * niemals den eigentlichen (bereits abgeschlossenen) Vorgang nachtraeglich
     * als Fehler erscheinen lassen.
     */
    public static function log(
        PDO $pdo,
        string $shop,
        ?string $connectorType,
        ?string $objectKey,
        string $operation,
        bool $success,
        string $message,
        ?string $externalId = null,
        ?string $requestBody = null,
        ?string $responseBody = null,
        ?int $syncRunId = null
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO connect_activity_log (shop, sync_run_id, connector_type, object_key, operation, status, message, request_body, response_body, external_id)
                 VALUES (:shop, :sync_run_id, :connector_type, :object_key, :operation, :status, :message, :request_body, :response_body, :external_id)'
            );
            $stmt->execute([
                'shop' => $shop,
                'sync_run_id' => $syncRunId,
                'connector_type' => $connectorType,
                'object_key' => $objectKey,
                'operation' => $operation,
                'status' => $success ? 'success' : 'error',
                'message' => mb_substr($message, 0, 2000),
                'request_body' => $requestBody !== null ? mb_substr($requestBody, 0, 50000) : null,
                'response_body' => $responseBody !== null ? mb_substr($responseBody, 0, 50000) : null,
                'external_id' => $externalId,
            ]);
        } catch (\Throwable) {
            // Siehe Klassenkommentar -- bewusst verschluckt.
        }
    }
}
