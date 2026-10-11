<?php

declare(strict_types=1);

namespace Connect\Connector;

/**
 * Gemeinsamer Vertrag fuer JEDEN Connector Type -- Shopify, HelloCash, MGM
 * und jedes kuenftige, per No-Code-Builder definierte System. Es gibt
 * bewusst nur EINE Implementierung dieses Interfaces (GenericHttpConnector);
 * Mapping- und Sync-Engine kennen ausschliesslich dieses Interface, nie eine
 * systemspezifische Klasse (siehe Plan, Abschnitt "Architektur-Konzepte").
 */
interface ConnectorInterface
{
    /**
     * Prueft, ob die hinterlegten Zugangsdaten funktionieren (z.B. ein
     * einfacher GET-Aufruf gegen die API). Wird von der "Verbindung
     * testen"-Funktion in der Connections-UI aufgerufen.
     *
     * @return TestResult
     */
    public function testConnection(): TestResult;

    /**
     * Liefert die fuer diesen Connector Type definierten Objects (z.B.
     * "product", "stock", "order") inkl. ihrer Felder -- Grundlage fuer den
     * Feld-Mapping-Editor.
     *
     * @return ObjectDefinition[]
     */
    public function getObjects(): array;

    /**
     * Liest Datensaetze eines Objects. Gibt ein Iterable zurueck (nicht ein
     * fertiges Array), damit der Aufrufer (SyncRunner) Datensaetze
     * verarbeiten kann, sobald sie eintreffen, statt auf eine komplette,
     * ggf. sehr grosse Ergebnismenge warten zu muessen.
     *
     * @param string $objectKey z.B. "product"
     * @param array<string, mixed> $params z.B. Pagination-Cursor, Filter
     * @return iterable<array<string, mixed>> je Zeile ein assoziatives Array (field_key => Wert)
     */
    public function read(string $objectKey, array $params = []): iterable;

    /**
     * Liest GENAU EINEN Datensatz gezielt (Operationstyp "read_one"), z.B.
     * fuer ein Webhook-getriggertes Einzel-Update statt eines kompletten
     * Listen-Refreshs. Nicht jedes Object hat notwendigerweise eine
     * "read_one"-Operation definiert -- in dem Fall wirft die Implementierung
     * (siehe requireOperation()).
     *
     * @param string $objectKey z.B. "product"
     * @param array<string, mixed> $params z.B. ["id" => "gid://shopify/Product/123"]
     * @return array<string, mixed>|null field_key => Wert, oder null falls nicht gefunden (z.B. geloescht)
     */
    public function readOne(string $objectKey, array $params): ?array;

    /**
     * Schreibt einen Datensatz (create oder update) fuer ein Object.
     *
     * @param string $objectKey z.B. "order"
     * @param string $operationType "create" | "update"
     * @param array<string, mixed> $data field_key => Wert, bereits durch die MappingEngine transformiert
     */
    public function write(string $objectKey, string $operationType, array $data): WriteResult;
}
