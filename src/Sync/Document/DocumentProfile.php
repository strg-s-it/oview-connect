<?php

declare(strict_types=1);

namespace Connect\Sync\Document;

/**
 * Eigenheiten eines Zielsystems beim Anlegen von Rechnungen/Belegen -- alles,
 * was bisher fest im Code fuer HelloCash stand und fuer ein anderes System
 * anders sein kann. Wird im Connector Builder am Ziel-Object gepflegt
 * (connect_objects.document_profile, JSON) und von OrderPreparer::render()
 * sowie Connect\Sync\Document\DocumentPipeline ausgewertet.
 */
final class DocumentProfile
{
    public const DISCOUNT_NONE = 'none';
    public const DISCOUNT_PERCENT = 'percent';
    public const DISCOUNT_ABSOLUTE = 'absolute';
    public const DISCOUNT_AUTO = 'auto';

    public function __construct(
        /** Rabatt als eigene Angabe an der Position: none|percent|absolute|auto (auto = Prozent wenn glatt, sonst Betrag). */
        public readonly string $discountMode = self::DISCOUNT_NONE,
        /** Betragsrabatt gilt pro Stueck (true) oder fuer die ganze Position (false). */
        public readonly bool $absoluteDiscountPerUnit = false,
        /** Erlaubte Nachkommastellen beim Prozentrabatt (HelloCash: 0, rundet sonst selbst). */
        public readonly int $percentDecimals = 2,
        /** Pfad zur Belegnummer in der Antwort (z.B. "data.invoice_number"). */
        public readonly ?string $documentNumberPath = null,
        /** Pfad zur Belegsumme in der Antwort, fuer die Nach-Kontrolle (z.B. "data.gross_total"). */
        public readonly ?string $grossTotalPath = null,
        /** Pfad zum Beleg-PDF (Base64) in der Antwort, fuer den Mailversand (z.B. "output.pdf"). */
        public readonly ?string $pdfPath = null,
        /** Kunden-Vorschritt: object_key des Kunden-Objects im Zielsystem (null = kein Vorschritt). */
        public readonly ?string $customerObjectKey = null,
        /** Kunden-Vorschritt: Name des Such-Parameters der Lesen-Operation (z.B. "search" fuer {{search}}). */
        public readonly string $customerSearchParam = 'search',
        /** Kunden-Vorschritt: Feld mit der Kunden-ID in den Suchergebnissen. */
        public readonly string $customerIdField = 'id',
        /** Kunden-Vorschritt: Feld mit der E-Mail in den Suchergebnissen (null = keine Suche per E-Mail). */
        public readonly ?string $customerEmailField = null,
        /** Kunden-Vorschritt: Felder mit Vor-/Nachname in den Suchergebnissen (beide null = keine Suche per Name). */
        public readonly ?string $customerFirstNameField = null,
        public readonly ?string $customerLastNameField = null,
        /** Kunden-Vorschritt: Mapping-Vorlage "Bestellung (aufbereitet) -> Kunde" fuer die Neuanlage (null = nie anlegen). */
        public readonly ?int $customerCreateTemplateId = null,
        /** Kunden-Vorschritt: Pfad zur ID des neu angelegten Kunden in der Antwort. */
        public readonly string $customerCreatedIdPath = 'id',
        /** Kunden-Vorschritt: Adressfelder im Suchergebnis -- bei der Suche per Name muessen sie
         *  zusaetzlich passen (wie alte Welt). Alle null = nur per Name. */
        public readonly ?string $customerZipField = null,
        public readonly ?string $customerCityField = null,
        public readonly ?string $customerStreetField = null,
        /** Artikelnummer/-ID des Versand-Artikels im Zielsystem (z.B. Crystalp/MGM "9900"), null = Versand ohne Artikel. */
        public readonly ?string $shippingArticleId = null,
    ) {
    }

    /**
     * Profil aus connect_objects.document_profile (JSON); unbekannte oder
     * fehlende Werte fallen auf die sichere Voreinstellung zurueck (kein Rabatt
     * als eigene Angabe = immer rabattierter Preis, Summe stimmt trotzdem).
     */
    public static function fromJson(?string $json): self
    {
        $data = $json !== null && $json !== '' ? (json_decode($json, true) ?: []) : [];

        $mode = (string) ($data['discount_mode'] ?? self::DISCOUNT_NONE);
        if (!in_array($mode, [self::DISCOUNT_NONE, self::DISCOUNT_PERCENT, self::DISCOUNT_ABSOLUTE, self::DISCOUNT_AUTO], true)) {
            $mode = self::DISCOUNT_NONE;
        }

        $text = static fn (string $key): ?string => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        return new self(
            $mode,
            (bool) ($data['absolute_discount_per_unit'] ?? false),
            max(0, min(4, (int) ($data['percent_decimals'] ?? 2))),
            $text('document_number_path'),
            $text('gross_total_path'),
            $text('pdf_path'),
            $text('customer_object_key'),
            $text('customer_search_param') ?? 'search',
            $text('customer_id_field') ?? 'id',
            $text('customer_email_field'),
            $text('customer_first_name_field'),
            $text('customer_last_name_field'),
            isset($data['customer_create_template_id']) && (int) $data['customer_create_template_id'] > 0 ? (int) $data['customer_create_template_id'] : null,
            $text('customer_created_id_path') ?? 'id',
            $text('customer_zip_field'),
            $text('customer_city_field'),
            $text('customer_street_field'),
            $text('shipping_article_id'),
        );
    }

    /**
     * Belegprofil eines Ziel-Objects -- null, wenn das Object im Connector
     * Builder (noch) nicht als Beleg markiert ist.
     */
    public static function forObjectId(\PDO $pdo, int $objectId): ?self
    {
        $stmt = $pdo->prepare('SELECT document_profile FROM connect_objects WHERE id = :id');
        $stmt->execute(['id' => $objectId]);
        $json = $stmt->fetchColumn();

        return is_string($json) && $json !== '' ? self::fromJson($json) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'discount_mode' => $this->discountMode,
            'absolute_discount_per_unit' => $this->absoluteDiscountPerUnit,
            'percent_decimals' => $this->percentDecimals,
            'document_number_path' => $this->documentNumberPath,
            'gross_total_path' => $this->grossTotalPath,
            'pdf_path' => $this->pdfPath,
            'customer_object_key' => $this->customerObjectKey,
            'customer_search_param' => $this->customerSearchParam,
            'customer_id_field' => $this->customerIdField,
            'customer_email_field' => $this->customerEmailField,
            'customer_first_name_field' => $this->customerFirstNameField,
            'customer_last_name_field' => $this->customerLastNameField,
            'customer_create_template_id' => $this->customerCreateTemplateId,
            'customer_created_id_path' => $this->customerCreatedIdPath,
            'customer_zip_field' => $this->customerZipField,
            'customer_city_field' => $this->customerCityField,
            'customer_street_field' => $this->customerStreetField,
            'shipping_article_id' => $this->shippingArticleId,
        ];
    }

    /**
     * Bisher fest im Code verdrahtetes HelloCash-Verhalten (InvoiceService) --
     * live getestet 2026-10-01: Betragsrabatt gilt fuer die ganze Position,
     * Prozentrabatt nur ganzzahlig.
     */
    public static function helloCashDefaults(): self
    {
        return new self(self::DISCOUNT_AUTO, false, 0, 'data.invoice_number', 'data.gross_total', 'output.pdf');
    }
}
