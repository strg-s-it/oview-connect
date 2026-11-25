<?php

declare(strict_types=1);

namespace Connect\Shopify;

/**
 * Erkennt Meldungen, mit denen Shopify einen ganzen Shop sperrt, obwohl die Zugangsdaten gueltig sind:
 * "Access denied for ... field. Shop is under review." (Shop in Pruefung) und HTTP 403 "Unavailable Shop"
 * (Shop pausiert/gekuendigt/eingefroren). Daran kann weder der Merchant noch oView etwas aendern -- die
 * Sperre muss Shopify aufheben. Haeufiges Wiederholen bringt nichts und erzeugt nur Fehlermails.
 *
 * Genutzt vom Scheduler (bin/scheduler.php): solche Laeufe werden selten wiederholt und melden nicht
 * mehr jede Minute per Cron-Mail, sowie von der Shopliste (admin/shops.php) fuer den Hinweis.
 */
final class ShopBlock
{
    /** So lange wartet der Scheduler nach einem solchen Fehler, bevor er das Mapping erneut einreiht. */
    public const RETRY_HOURS = 6;

    public static function matches(?string $message): bool
    {
        if ($message === null || $message === '') {
            return false;
        }

        return stripos($message, 'Shop is under review') !== false
            || stripos($message, 'Unavailable Shop') !== false;
    }

    /** Klartext fuer die Shopliste. */
    public static function label(string $message): string
    {
        return stripos($message, 'under review') !== false
            ? 'Von Shopify gesperrt: Shop in Prüfung (under review)'
            : 'Von Shopify gesperrt: Shop nicht verfügbar (pausiert oder gekündigt)';
    }
}
