<?php

declare(strict_types=1);

namespace Connect\Connector\Generic;

/**
 * Parst XML-Antworten im MGM/AGILA-typischen Stil:
 *
 *   <dataset>
 *     <FieldDefs>
 *       <Field Nr="1" Name="Artikelnummer" type="String" size="15"/>
 *       <Field Nr="2" Name="EAN" type="String" size="13"/>
 *       ...
 *     </FieldDefs>
 *     <data>
 *       <row nr="1"><F1>11025A</F1><F2>9000000000001</F2></row>
 *       ...
 *     </data>
 *   </dataset>
 *
 * Die Spaltennamen stehen nur in FieldDefs, in den Zeilen selbst wird nur
 * ueber F1/F2/... referenziert -- diese Klasse loest das per Nr-Mapping auf.
 * Fehlende Spalten in einer Zeile (siehe MGM-Handbuch: "ist ein Wert leer
 * oder 0, wird die gesamte Spalte weggelassen") werden als fehlend
 * behandelt, nicht als Fehler.
 *
 * Ist die Antwort NICHT im dataset/FieldDefs-Schema (z.B. eine einfache
 * Kommando-Antwort wie bei GefallAdd), liefert parseFlat() eine flache
 * Elementname -> Text-Abbildung.
 */
final class XmlDatasetParser
{
    /**
     * @return array<int, array<string, string>> je Zeile: Spaltenname => Wert
     */
    public static function parseRows(string $xml): array
    {
        $root = self::loadXml($xml);

        if (!isset($root->FieldDefs, $root->data)) {
            throw new \RuntimeException('XML enthaelt kein dataset/FieldDefs-Schema -- parseRows() ist dafuer nicht geeignet.');
        }

        // Nr -> Name Mapping aus FieldDefs aufbauen.
        $columnNames = [];
        foreach ($root->FieldDefs->Field as $field) {
            $nr = (string) $field['Nr'];
            $name = (string) $field['Name'];
            $columnNames[$nr] = $name;
        }

        $rows = [];
        foreach ($root->data->row as $rowElement) {
            $row = [];
            foreach ($rowElement->children() as $child) {
                // Tag-Name ist "F1", "F2", ... -- Ziffern extrahieren, um
                // im columnNames-Mapping nachzuschlagen.
                $tagName = $child->getName();
                if (preg_match('/^F(\d+)$/', $tagName, $matches) !== 1) {
                    continue;
                }

                $columnName = $columnNames[$matches[1]] ?? $tagName;
                $row[$columnName] = (string) $child;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Liest wiederholte Zeilen aus einem beliebig verschachtelten XML, dessen
     * Zeilen-Elemente (anders als bei parseRows()) direkt benannte Kind-Tags
     * tragen statt eines F1/F2/...-Schemas mit separatem FieldDefs-Mapping --
     * z.B. MGMs "CustomCmd"-Antworten (getMpArtikel):
     *
     *   <mgmDaten><Result status="OK"><DataObject><data>
     *     <row><ArtNr>5020.CRY.R</ArtNr><EAN>...</EAN><Lagermenge>6</Lagermenge></row>
     *     ...
     *   </data></DataObject></Result></mgmDaten>
     *
     * $recordsPath navigiert punktgetrennt vom Wurzelelement zum
     * wiederholten Zeilen-Element (hier "Result.DataObject.data.row"),
     * analog zu response_records_path bei JSON-Antworten.
     *
     * @return array<int, array<string, string>> je Zeile: Tag-Name => Wert
     */
    public static function parseNamedRows(string $xml, string $recordsPath): array
    {
        $current = self::loadXml($xml);
        foreach (explode('.', $recordsPath) as $segment) {
            if (!isset($current->{$segment})) {
                return [];
            }
            $current = $current->{$segment};
        }

        // Wie bei parseRows(): der letzte Pfad-Zugriff liefert zwar nur das
        // erste gleichnamige Element, ein foreach darueber iteriert aber
        // ueber alle Geschwister mit demselben Tag-Namen (SimpleXML-Eigenheit).
        $rows = [];
        foreach ($current as $rowElement) {
            $row = [];
            foreach ($rowElement->children() as $child) {
                $row[$child->getName()] = (string) $child;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Flache Elementname -> Text-Abbildung fuer einfache Antwort-XMLs ohne
     * dataset-Schema (z.B. die GefallAdd-Response mit Result/GfNr/GfIdNr/...).
     *
     * @return array<string, string>
     */
    public static function parseFlat(string $xml): array
    {
        $root = self::loadXml($xml);
        $flat = [];
        self::flattenInto($root, $flat);

        return $flat;
    }

    /**
     * @param array<string, string> $flat
     */
    private static function flattenInto(\SimpleXMLElement $element, array &$flat): void
    {
        foreach ($element->attributes() as $name => $value) {
            $flat[(string) $name] = (string) $value;
        }

        if ($element->count() === 0) {
            $text = trim((string) $element);
            if ($text !== '') {
                $flat[$element->getName()] = $text;
            }
            return;
        }

        foreach ($element->children() as $child) {
            self::flattenInto($child, $flat);
        }
    }

    private static function loadXml(string $xml): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($root === false) {
            $messages = array_map(static fn ($e) => trim($e->message), $errors);
            throw new \RuntimeException('XML konnte nicht geparst werden: ' . implode('; ', $messages));
        }

        return $root;
    }
}
