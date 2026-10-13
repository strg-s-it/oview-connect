<?php

declare(strict_types=1);

namespace Connect\Setup;

/**
 * Anleitung "Wo finde ich die Zugangsdaten?" je Connector Type (connect_connector_types.setup_help,
 * Migration 0099, im Admin am Connector Type pflegbar). Ein Schritt je Zeile, **fett** ist erlaubt,
 * sonst nur Text -- alles wird escaped.
 */
final class SetupHelp
{
    /** Schritte als Liste fuer die Ausgabe. @return list<string> HTML je Schritt */
    public static function steps(?string $text): array
    {
        $steps = [];
        foreach (preg_split('/\R/', (string) $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $html = htmlspecialchars($line, ENT_QUOTES);
            $steps[] = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html);
        }

        return $steps;
    }

    /** Aufklappbarer Block, leer wenn keine Anleitung hinterlegt ist. */
    public static function details(?string $text, string $summary = 'Wo finde ich die Zugangsdaten?'): string
    {
        $steps = self::steps($text);
        if ($steps === []) {
            return '';
        }

        return '<details class="border rounded p-3"><summary class="fw-bold" style="cursor:pointer">' . htmlspecialchars($summary, ENT_QUOTES)
            . '</summary><ol class="mt-2 mb-0 d-grid gap-1"><li>' . implode('</li><li>', $steps) . '</li></ol></details>';
    }
}
