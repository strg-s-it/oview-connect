<?php

declare(strict_types=1);

namespace Connect\Setup;

use PDO;

/**
 * Einrichtung (app/welcome.php) vor dem Dashboard: der Merchant muss allen veroeffentlichten
 * Rechtstexten (aktuelle Version) zugestimmt und die Einrichtung mit "oView starten"
 * abgeschlossen haben (connect_shops.setup_completed_at, Migration 0078). Ohne 0078 gilt
 * die fruehere Regel: Software verbunden. Geprueft in bootstrap.php fuer jede
 * Merchant-Seite. Fehlen die Tabellen noch (Migration 0076), gibt es keine Sperre.
 */
final class WelcomeGate
{
    public static function isRequired(PDO $pdo, string $shop): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT target_connection_id FROM connect_shops WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);
            $target = $stmt->fetchColumn();
            if ($target === false) {
                return false; // Shop unbekannt -- darum kuemmert sich der Einstieg, nicht dieses Fenster
            }

            $wizard = new SetupWizard($pdo);
            $setupOpen = $wizard->isAvailable() ? !$wizard->isCompleted($shop) : $target === null;

            return $setupOpen || (new LegalTexts($pdo))->pendingForShop($shop) !== [];
        } catch (\Throwable) {
            return false;
        }
    }
}
