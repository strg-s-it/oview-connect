<?php

declare(strict_types=1);

namespace Connect\Mail;

use Connect\Billing\Plans;
use PDO;

/**
 * Zusaetzliche Anhaenge an der Rechnungsmail je Shop (Migration 0089): z.B. Widerrufsbelehrung
 * (im Fernabsatz in Textform beim Kunden), AGB, Pflegehinweise. Nur PDF, hoechstens MAX_FILES
 * Dateien zu je MAX_BYTES. Freigabe je Abo: Plans::FEATURES['attachments'].
 */
final class ShopAttachments
{
    public const MAX_FILES = 3;
    public const MAX_BYTES = 2_000_000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id: int, filename: string, size: int, enabled: bool, created_at: string}> */
    public function list(string $shop): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT id, filename, size, enabled, created_at FROM connect_shop_attachments WHERE shop = :shop ORDER BY id');
            $stmt->execute(['shop' => $shop]);

            return array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'filename' => (string) $r['filename'], 'size' => (int) $r['size'],
                'enabled' => (int) $r['enabled'] === 1, 'created_at' => (string) $r['created_at'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\PDOException) {
            return []; // Migration 0089 fehlt
        }
    }

    /**
     * Hochgeladene Datei ($_FILES-Eintrag) speichern.
     *
     * @param array<string, mixed>|null $file
     * @return ?string Fehler, null = gespeichert
     */
    public function upload(string $shop, ?array $file): ?string
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Bitte eine PDF-Datei auswählen.';
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return 'Upload fehlgeschlagen (Fehler ' . (int) $file['error'] . ').';
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            return 'Die Datei ist größer als ' . round(self::MAX_BYTES / 1_000_000, 1) . ' MB.';
        }
        if (count($this->list($shop)) >= self::MAX_FILES) {
            return 'Höchstens ' . self::MAX_FILES . ' Anhänge – bitte zuerst einen entfernen.';
        }
        $data = (string) file_get_contents((string) $file['tmp_name']);
        if (!str_starts_with($data, '%PDF') || (new \finfo(FILEINFO_MIME_TYPE))->buffer($data) !== 'application/pdf') {
            return 'Nur PDF-Dateien sind erlaubt.';
        }
        // Dateiname fuer den Kunden: lesbar, ohne Pfad und Sonderzeichen
        $name = preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '', basename((string) ($file['name'] ?? 'Anhang.pdf'))) ?: 'Anhang.pdf';
        if (!str_ends_with(strtolower($name), '.pdf')) {
            $name .= '.pdf';
        }

        $stmt = $this->pdo->prepare('INSERT INTO connect_shop_attachments (shop, filename, mime, data, size) VALUES (:shop, :name, :mime, :data, :size)');
        $stmt->bindValue('shop', $shop);
        $stmt->bindValue('name', mb_substr($name, 0, 190));
        $stmt->bindValue('mime', 'application/pdf');
        $stmt->bindValue('data', $data, PDO::PARAM_LOB);
        $stmt->bindValue('size', strlen($data), PDO::PARAM_INT);
        $stmt->execute();

        return null;
    }

    public function remove(string $shop, int $id): void
    {
        $this->pdo->prepare('DELETE FROM connect_shop_attachments WHERE id = :id AND shop = :shop')->execute(['id' => $id, 'shop' => $shop]);
    }

    public function setEnabled(string $shop, int $id, bool $enabled): void
    {
        $this->pdo->prepare('UPDATE connect_shop_attachments SET enabled = :e WHERE id = :id AND shop = :shop')->execute(['e' => $enabled ? 1 : 0, 'id' => $id, 'shop' => $shop]);
    }

    /** @return ?array{filename: string, data: string} fuer die Vorschau/den Download im Admin */
    public function get(string $shop, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT filename, data FROM connect_shop_attachments WHERE id = :id AND shop = :shop');
        $stmt->execute(['id' => $id, 'shop' => $shop]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? ['filename' => (string) $row['filename'], 'data' => (string) $row['data']] : null;
    }

    /**
     * Anhaenge fuer den Versand -- leer, wenn das Abo sie nicht enthaelt.
     *
     * @return list<array{content: string, filename: string, mime: string}>
     */
    public function forMail(string $shop): array
    {
        if (!Plans::allowsFeature(Plans::forShop($this->pdo, $shop), 'attachments')) {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare('SELECT filename, mime, data FROM connect_shop_attachments WHERE shop = :shop AND enabled = 1 ORDER BY id LIMIT ' . self::MAX_FILES);
            $stmt->execute(['shop' => $shop]);

            return array_map(static fn (array $r): array => ['content' => (string) $r['data'], 'filename' => (string) $r['filename'], 'mime' => (string) $r['mime']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\PDOException) {
            return [];
        }
    }
}
