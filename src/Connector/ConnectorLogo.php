<?php

declare(strict_types=1);

namespace Connect\Connector;

use PDO;

/**
 * Logo eines Connector Types (Shopify, HelloCash, MGM, ...) fuer Assistent,
 * Connector Builder und Objekt-Ansichten. Gespeichert als data-URI in
 * connect_connector_types.logo_data (Migration 0097) -- nicht als Datei, weil
 * das Live-Verzeichnis per rsync --delete aus httpdocs/connect ueberschrieben
 * wird. Rueckfall auf eine alte Logo-Datei (logo_path, Migration 0076), wenn sie
 * noch existiert. Fehlen beide Spalten, gibt es einfach kein Logo.
 */
final class ConnectorLogo
{
    /** Max. Bildgroesse -- base64 (+33 %) muss unter MariaDB max_allowed_packet (5.5: 1 MB) bleiben. */
    public const MAX_BYTES = 300 * 1024;

    private const MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /** @var array<int, array<string, mixed>>|null alle Typen je ID, einmal pro Request */
    private static ?array $types = null;

    /**
     * Bildquelle fuer <img src> oder null (kein Logo).
     *
     * @param array<string, mixed> $typeRow Zeile aus connect_connector_types (SELECT *)
     */
    public static function src(array $typeRow, string $assetsBase = '../assets'): ?string
    {
        $data = (string) ($typeRow['logo_data'] ?? '');
        if (str_starts_with($data, 'data:image/')) {
            return $data;
        }

        $path = basename((string) ($typeRow['logo_path'] ?? ''));
        if ($path !== '' && is_file(dirname(__DIR__, 2) . '/public/assets/logos/' . $path)) {
            return $assetsBase . '/logos/' . rawurlencode($path);
        }

        return null;
    }

    /**
     * Fertiges <img>-Tag oder '' (kein Logo).
     *
     * @param array<string, mixed>|null $typeRow
     */
    public static function img(?array $typeRow, int $height = 20, string $class = 'me-2'): string
    {
        $src = $typeRow !== null ? self::src($typeRow) : null;
        if ($src === null) {
            return '';
        }

        return '<img src="' . htmlspecialchars($src) . '" alt="" class="' . htmlspecialchars($class) . '" style="height: ' . $height . 'px; width: auto; max-width: ' . ($height * 4) . 'px; object-fit: contain; vertical-align: middle;">';
    }

    /**
     * <img>-Tag zum Connector Type mit dieser ID (fuer Seiten, die nur die ID kennen).
     */
    public static function imgForTypeId(PDO $pdo, int $typeId, int $height = 20, string $class = 'me-2'): string
    {
        if (self::$types === null) {
            self::$types = [];
            foreach ($pdo->query('SELECT * FROM connect_connector_types')->fetchAll() as $row) {
                self::$types[(int) $row['id']] = $row;
            }
        }

        return self::img(self::$types[$typeId] ?? null, $height, $class);
    }

    /**
     * Hochgeladene Datei pruefen und als data-URI liefern. Nur Rasterbilder --
     * SVG bewusst nicht (kann Script enthalten).
     *
     * @param mixed $file Eintrag aus $_FILES
     * @return array{data: ?string, error: ?string}
     */
    public static function fromUpload(mixed $file): array
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['data' => null, 'error' => 'Bitte eine Bilddatei auswählen (max. 300 KB).'];
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            return ['data' => null, 'error' => 'Das Bild ist größer als 300 KB – bitte verkleinern.'];
        }

        $info = @getimagesize((string) $file['tmp_name']);
        $mime = is_array($info) ? $info['mime'] : '';
        if (!in_array($mime, self::MIME_TYPES, true)) {
            return ['data' => null, 'error' => 'Nur PNG, JPG, WebP oder GIF.'];
        }

        $bytes = @file_get_contents((string) $file['tmp_name']);
        if ($bytes === false || $bytes === '') {
            return ['data' => null, 'error' => 'Bild konnte nicht gelesen werden.'];
        }

        return ['data' => 'data:' . $mime . ';base64,' . base64_encode($bytes), 'error' => null];
    }
}
