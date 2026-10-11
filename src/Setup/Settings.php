<?php

declare(strict_types=1);

namespace Connect\Setup;

use PDO;

/**
 * Globale Schalter (connect_settings, Migration 0076).
 *   new_installs_to_connect  "1" = Neuinstallationen ohne Einstellungen in der alten Welt
 *                            werden direkt in Connect angelegt (siehe public/internal/
 *                            provision_shop.php und index.php der alten Welt)
 */
final class Settings
{
    public const NEW_INSTALLS_TO_CONNECT = 'new_installs_to_connect';

    public static function get(PDO $pdo, string $key, ?string $default = null): ?string
    {
        try {
            $stmt = $pdo->prepare('SELECT setting_value FROM connect_settings WHERE setting_key = :key');
            $stmt->execute(['key' => $key]);
            $value = $stmt->fetchColumn();

            return $value !== false ? ($value !== null ? (string) $value : null) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function set(PDO $pdo, string $key, ?string $value): void
    {
        $pdo->prepare(
            'INSERT INTO connect_settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = :value2'
        )->execute(['key' => $key, 'value' => $value, 'value2' => $value]);
    }
}
