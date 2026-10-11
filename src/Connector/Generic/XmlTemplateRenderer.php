<?php

declare(strict_types=1);

namespace Connect\Connector\Generic;

/**
 * Rendert ein XML-Body-Template (siehe connect_object_operations.body_template)
 * zu einem sendefertigen XML-String. Unterstuetzt zwei Platzhalter-Arten:
 *
 *   {{feld}}                     - einfacher Wert aus $data['feld']
 *   {{#each positionen}}...{{/each}}  - wiederholt den Block einmal pro
 *                                       Eintrag in $data['positionen'],
 *                                       Platzhalter darin beziehen sich auf
 *                                       den jeweiligen Eintrag
 *
 * Beispiel (analog zum MGM GefallAdd-Beispiel im Handbuch):
 *   <Positionen>
 *     {{#each positionen}}
 *     <zl ArtNr="{{ArtNr}}" Menge="{{Menge}}" Brutto="{{Brutto}}" Ust="{{Ust}}"/>
 *     {{/each}}
 *   </Positionen>
 *
 * Bewusst eine kleine Eigenimplementierung statt einer Template-Engine --
 * die beiden obigen Konstrukte reichen fuer die bekannten XML-Connector-
 * Faelle (MGM); mehr Komplexitaet (verschachtelte each-Bloecke, Bedingungen)
 * ist aktuell nicht noetig.
 */
final class XmlTemplateRenderer
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $staticParams Fixe Werte (z.B. GfKreis="001"), als Fallback wenn $data das Feld nicht hat
     */
    public static function render(string $template, array $data, array $staticParams = []): string
    {
        $merged = array_merge($staticParams, $data);

        $withEachBlocksResolved = preg_replace_callback(
            '/\{\{#each\s+(\w+)\}\}(.*?)\{\{\/each\}\}/s',
            static function (array $matches) use ($merged): string {
                [, $collectionKey, $innerTemplate] = $matches;
                $items = $merged[$collectionKey] ?? [];

                if (!is_array($items)) {
                    return '';
                }

                $rendered = '';
                foreach ($items as $item) {
                    $rendered .= self::replacePlaceholders($innerTemplate, is_array($item) ? $item : []);
                }

                return $rendered;
            },
            $template
        );

        if ($withEachBlocksResolved === null) {
            throw new \RuntimeException('XML-Template konnte nicht verarbeitet werden (each-Bloecke).');
        }

        return self::replacePlaceholders($withEachBlocksResolved, $merged);
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function replacePlaceholders(string $template, array $values): string
    {
        $result = preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            static function (array $matches) use ($values): string {
                $value = $values[$matches[1]] ?? '';
                // XML-Escaping (& < > ' ") -- Werte koennen aus externen
                // Quellen (z.B. Kundennamen) stammen und duerfen kein
                // ungueltiges/injizierbares XML erzeugen.
                return htmlspecialchars((string) $value, ENT_XML1, 'UTF-8');
            },
            $template
        );

        if ($result === null) {
            throw new \RuntimeException('XML-Template konnte nicht verarbeitet werden (Platzhalter).');
        }

        return $result;
    }
}
