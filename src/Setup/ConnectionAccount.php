<?php

declare(strict_types=1);

namespace Connect\Setup;

use Connect\Connector\ConnectorFactory;
use PDO;

/**
 * Konto- bzw. Firmendaten einer Connection aus dem verbundenen System lesen, statt sie
 * beim Merchant abzufragen (die alte Welt fragte zusaetzlich die helloCash-E-Mail ab, die
 * nirgends geprueft wurde). Ergebnis: Bezeichnung der Connection "HelloCash – Musterfirma
 * GmbH" (eindeutig im Support), Firmendaten in connect_connections.config.account.
 *
 * Generisch: genutzt wird ein Object mit dem Schluessel "account_info" am Connector Type,
 * angelegt im Connector Builder (Lese-Operation, Feld "name", optional "email",
 * "uid_number"). Fuer HelloCash v2: GET company, Antwortpfad data -- braucht beim Token die
 * Berechtigung company:read. Fehlt das Object oder die Berechtigung, passiert nichts.
 */
final class ConnectionAccount
{
    public const OBJECT_KEY = 'account_info';

    /**
     * @return string|null neue Bezeichnung, null wenn nichts gelesen werden konnte
     */
    public static function refresh(PDO $pdo, ConnectorFactory $factory, int $connectionId): ?string
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT c.label, c.config, t.name AS type_name, o.id AS object_id
                 FROM connect_connections c
                 JOIN connect_connector_types t ON t.id = c.connector_type_id
                 JOIN connect_objects o ON o.connector_type_id = t.id AND o.object_key = :object_key
                 WHERE c.id = :id'
            );
            $stmt->execute(['id' => $connectionId, 'object_key' => self::OBJECT_KEY]);
            $row = $stmt->fetch();
            if ($row === false) {
                return null; // kein Object "account_info" an diesem System
            }

            $config = json_decode((string) ($row['config'] ?? ''), true);
            $config = is_array($config) ? $config : [];

            $account = null;
            try {
                foreach ($factory->forConnection($connectionId)->read(self::OBJECT_KEY) as $record) {
                    $account = $record;
                    break;
                }
            } catch (\Throwable $e) {
                self::rememberError($pdo, $connectionId, $config, 'Firmendaten nicht lesbar: ' . $e->getMessage());

                return null;
            }
            $name = trim((string) ($account['name'] ?? ''));
            if ($name === '') {
                $keys = is_array($account) ? implode(', ', array_keys($account)) : 'kein Datensatz';
                self::rememberError($pdo, $connectionId, $config, 'Kein Firmenname gefunden (Feld „name“ leer oder fehlt; gelesene Felder: ' . $keys . ').');

                return null;
            }

            unset($config['account_error']);
            $config['account'] = [
                'name' => $name,
                'email' => isset($account['email']) ? (string) $account['email'] : null,
                'uid_number' => isset($account['uid_number']) ? (string) $account['uid_number'] : null,
                'checked_at' => date('Y-m-d H:i:s'),
            ];

            // Bezeichnung nur ersetzen, wenn sie noch automatisch ist (Systemname bzw. eine
            // fruehere automatische Bezeichnung) -- eine von Hand vergebene bleibt.
            $typeName = (string) $row['type_name'];
            $label = (string) $row['label'];
            $auto = $label === $typeName || str_starts_with($label, $typeName . ' – ') || $label === '';
            $newLabel = $auto ? mb_substr($typeName . ' – ' . $name, 0, 190) : $label;

            $pdo->prepare('UPDATE connect_connections SET label = :label, config = :config WHERE id = :id')
                ->execute(['label' => $newLabel, 'config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'id' => $connectionId]);

            return $newLabel;
        } catch (\Throwable $e) {
            // reine Komfortfunktion -- darf den Verbindungstest nie stoeren, aber im PHP-Protokoll auffindbar sein
            error_log('[connect] ConnectionAccount::refresh(' . $connectionId . '): ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Firmendaten zur Anzeige (Connections, Status, Admin -> Shops).
     *
     * @return array{name: ?string, email: ?string, uid_number: ?string, error: ?string}
     */
    public static function fromConfig(?string $configJson): array
    {
        $config = json_decode((string) $configJson, true);
        $account = is_array($config) && is_array($config['account'] ?? null) ? $config['account'] : [];

        return [
            'name' => ($account['name'] ?? '') !== '' ? (string) $account['name'] : null,
            'email' => ($account['email'] ?? '') !== '' ? (string) $account['email'] : null,
            'uid_number' => ($account['uid_number'] ?? '') !== '' ? (string) $account['uid_number'] : null,
            'error' => is_array($config) && ($config['account_error'] ?? '') !== '' ? (string) $config['account_error'] : null,
        ];
    }

    /** Eine Zeile "Musterfirma GmbH · UID ATU… · office@…" (leer, wenn nichts bekannt). */
    public static function summary(?string $configJson): string
    {
        $a = self::fromConfig($configJson);

        return implode(' · ', array_filter([$a['name'], $a['uid_number'] !== null ? 'UID ' . $a['uid_number'] : null, $a['email']]));
    }

    /**
     * Grund merken, warum keine Firmendaten gelesen werden konnten (z.B. Token ohne
     * company:read) -- sichtbar unter Connections, statt still zu verschwinden.
     *
     * @param array<string, mixed> $config
     */
    private static function rememberError(PDO $pdo, int $connectionId, array $config, string $message): void
    {
        $config['account_error'] = mb_substr($message, 0, 300) . ' (' . date('d.m.Y H:i') . ')';
        $pdo->prepare('UPDATE connect_connections SET config = :config WHERE id = :id')
            ->execute(['config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'id' => $connectionId]);
    }
}
