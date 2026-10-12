<?php

declare(strict_types=1);

namespace Connect\Setup;

use Connect\Connector\ConnectorFactory;
use Connect\Connector\TestResult;
use Connect\Security\Crypto;
use PDO;

/**
 * Zielsystem (Software, z.B. HelloCash) fuer einen Shop verbinden: Connection anlegen
 * bzw. Zugangsdaten erneuern, testen, als Ziel des Shops setzen. Gemeinsam genutzt vom
 * ersten Fenster (app/welcome.php) und dem Verbindungs-Assistenten der Einstellungen
 * (app/_sync_mappings_body.php, Aktion connect_target_system).
 */
final class TargetSystemSetup
{
    /**
     * Auswaehlbare Systeme fuer den Haendler (alles ausser Shopify als feste Quelle und
     * dem internen Typ). Private Typen (is_public = 0, Migration 0096, z.B. MGM nur fuer
     * Crystalp) nur fuer Shops, die schon eine Connection dieses Typs haben. Ohne $shop
     * nur oeffentliche. Fehlt die Spalte noch, gilt alles als oeffentlich.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function availableTypes(PDO $pdo, ?string $shop = null): array
    {
        $types = $pdo->query("SELECT * FROM connect_connector_types WHERE type_key NOT IN ('shopify', 'connect_internal') ORDER BY name")->fetchAll();

        $ownTypeIds = [];
        if ($shop !== null) {
            $stmt = $pdo->prepare('SELECT DISTINCT connector_type_id FROM connect_connections WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);
            $ownTypeIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        return array_values(array_filter(
            $types,
            static fn (array $type): bool => (int) ($type['is_public'] ?? 1) === 1 || in_array((int) $type['id'], $ownTypeIds, true)
        ));
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array{ok: bool, connected: bool, message: string} ok = angelegt, connected = Test erfolgreich
     */
    public static function connect(PDO $pdo, Crypto $crypto, ConnectorFactory $factory, string $shop, int $connectorTypeId, array $credentials): array
    {
        // Nur Systeme, die diesem Shop auch angeboten werden (private Typen, Migration 0096) --
        // serverseitig, nicht nur durch Verstecken in der Auswahl.
        $typeName = false;
        foreach (self::availableTypes($pdo, $shop) as $type) {
            if ((int) $type['id'] === $connectorTypeId) {
                $typeName = (string) $type['name'];
            }
        }

        if ($connectorTypeId <= 0 || $typeName === false) {
            return ['ok' => false, 'connected' => false, 'message' => 'Bitte ein System auswählen.'];
        }

        // Zugangsdaten bereinigen (wie in _connections_body.php): Token ohne Leerraum
        // (aus Mails kopierte JWTs enthalten Zeilenumbrueche), sonst getrimmt.
        $clean = [];
        foreach ($credentials as $key => $value) {
            $value = (string) $value;
            $clean[(string) $key] = in_array($key, ['token', 'header_value'], true) ? (string) preg_replace('/\s+/', '', $value) : trim($value);
        }
        $encrypted = $crypto->encryptArray($clean);

        // Bestehende Connection desselben Shops+Typs aktualisieren statt bei jedem
        // Versuch (z.B. falsche Zugangsdaten) eine weitere anzulegen.
        $existingStmt = $pdo->prepare('SELECT id FROM connect_connections WHERE shop = :shop AND connector_type_id = :type');
        $existingStmt->execute(['shop' => $shop, 'type' => $connectorTypeId]);
        $existingId = $existingStmt->fetchColumn();

        if ($existingId !== false) {
            $connectionId = (int) $existingId;
            $pdo->prepare("UPDATE connect_connections SET credentials = :credentials, status = 'pending', updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute(['credentials' => $encrypted, 'id' => $connectionId]);
        } else {
            $pdo->prepare(
                "INSERT INTO connect_connections (shop, connector_type_id, label, credentials, config, status, created_at)
                 VALUES (:shop, :type, :label, :credentials, :config, 'pending', CURRENT_TIMESTAMP)"
            )->execute(['shop' => $shop, 'type' => $connectorTypeId, 'label' => $typeName, 'credentials' => $encrypted, 'config' => json_encode([])]);
            $connectionId = (int) $pdo->lastInsertId();
        }

        try {
            $result = $factory->forConnection($connectionId)->testConnection();
        } catch (\Throwable $e) {
            $result = TestResult::failure($e->getMessage());
        }

        $pdo->prepare('UPDATE connect_connections SET status = :status, last_test_at = CURRENT_TIMESTAMP, last_test_result = :result WHERE id = :id')
            ->execute(['status' => $result->success ? 'active' : 'error', 'result' => $result->message, 'id' => $connectionId]);
        if ($result->success) {
            \Connect\Sync\JobScheduler::afterSuccessfulTest($pdo, $connectionId, 'pending'); // neue Zugangsdaten
        }

        // Als Ziel setzen UNABHAENGIG vom Test: manche Systeme (z.B. MGM ohne Lese-Operation)
        // koennen strukturell nicht getestet werden.
        $pdo->prepare('UPDATE connect_shops SET target_connection_id = :id WHERE shop = :shop')
            ->execute(['id' => $connectionId, 'shop' => $shop]);

        self::activateJobTemplates($pdo, $shop, $connectionId, $connectorTypeId);

        // Firmenname aus dem System als Bezeichnung (z.B. "HelloCash – Musterfirma GmbH")
        if ($result->success) {
            $label = ConnectionAccount::refresh($pdo, $factory, $connectionId);
            if ($label !== null) {
                $typeName = $label;
            }
        }

        return $result->success
            ? ['ok' => true, 'connected' => true, 'message' => "Verbindung zu \"{$typeName}\" erfolgreich eingerichtet und getestet."]
            : ['ok' => true, 'connected' => false, 'message' => "Verbindung zu \"{$typeName}\" eingerichtet, der automatische Test war aber nicht erfolgreich ({$result->message}). Bitte Zugangsdaten unter Connections prüfen."];
    }

    /**
     * Daten-Jobs aller Job-Vorlagen mit auto_activate (z.B. HelloCash Artikel, Mitarbeiter),
     * wie beim Anlegen einer Connection unter Connections. Idempotent: bei erneutem
     * Verbinden (neue Zugangsdaten) entsteht kein zweiter Job.
     */
    private static function activateJobTemplates(PDO $pdo, string $shop, int $connectionId, int $connectorTypeId): void
    {
        $templates = $pdo->prepare('SELECT id, object_id, name, default_interval_minutes FROM connect_sync_job_templates WHERE connector_type_id = :type_id AND auto_activate = 1');
        $templates->execute(['type_id' => $connectorTypeId]);
        foreach ($templates->fetchAll() as $template) {
            $existing = $pdo->prepare('SELECT id FROM connect_sync_mappings WHERE source_connection_id = :cid AND source_object_id = :oid');
            $existing->execute(['cid' => $connectionId, 'oid' => $template['object_id']]);
            if ($existing->fetchColumn() !== false) {
                continue;
            }
            $pdo->prepare(
                "INSERT INTO connect_sync_mappings
                    (shop, name, source_connection_id, source_object_id, sync_job_template_id, trigger_type, schedule_interval_minutes, enabled, managed_by)
                 VALUES (:shop, :name, :source_connection_id, :source_object_id, :sync_job_template_id, 'schedule', :interval, 1, 'system')"
            )->execute([
                'shop' => $shop,
                'name' => $template['name'],
                'source_connection_id' => $connectionId,
                'source_object_id' => $template['object_id'],
                'sync_job_template_id' => $template['id'],
                'interval' => $template['default_interval_minutes'],
            ]);
        }
    }
}
