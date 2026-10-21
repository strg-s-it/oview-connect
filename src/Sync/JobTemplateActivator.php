<?php

declare(strict_types=1);

namespace Connect\Sync;

use PDO;

/**
 * Job-Vorlage (connect_sync_job_templates) fuer alle bestehenden Shops auf einmal aktivieren --
 * statt Shop fuer Shop unter "Jobs". "Automatisch aktivieren" gilt nur fuer NEUE Connections;
 * hiermit werden auch die bereits vorhandenen nachgezogen.
 *
 * Idempotent: eine Connection, die fuer das Object der Vorlage schon einen Job hat (egal wie
 * angelegt), bekommt keinen zweiten. Die Jobs entstehen wie bei der automatischen Aktivierung
 * (managed_by 'system', Standard-Intervall der Vorlage); der Scheduler plant den ersten Lauf
 * beim naechsten Durchgang ein.
 */
final class JobTemplateActivator
{
    private const MISSING_SQL = 'FROM connect_connections c
         JOIN connect_sync_job_templates t ON t.id = :template_id AND t.connector_type_id = c.connector_type_id
         WHERE NOT EXISTS (
             SELECT 1 FROM connect_sync_mappings m WHERE m.source_connection_id = c.id AND m.source_object_id = t.object_id
         )';

    /** Bei wie vielen Connections fehlt der Job dieser Vorlage noch? */
    public static function missingCount(PDO $pdo, int $templateId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) ' . self::MISSING_SQL);
        $stmt->execute(['template_id' => $templateId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return int Anzahl neu angelegter Jobs
     */
    public static function activateForAllShops(PDO $pdo, int $templateId): int
    {
        $stmt = $pdo->prepare(
            'SELECT c.id AS connection_id, c.shop, t.object_id, t.name, t.default_interval_minutes ' . self::MISSING_SQL
        );
        $stmt->execute(['template_id' => $templateId]);

        $insert = $pdo->prepare(
            "INSERT INTO connect_sync_mappings
                (shop, name, source_connection_id, source_object_id, sync_job_template_id, trigger_type, schedule_interval_minutes, enabled, managed_by)
             VALUES (:shop, :name, :source_connection_id, :source_object_id, :sync_job_template_id, 'schedule', :interval, 1, 'system')"
        );
        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            $insert->execute([
                'shop' => $row['shop'],
                'name' => $row['name'],
                'source_connection_id' => (int) $row['connection_id'],
                'source_object_id' => (int) $row['object_id'],
                'sync_job_template_id' => $templateId,
                'interval' => $row['default_interval_minutes'],
            ]);
            $count++;
        }

        return $count;
    }
}
