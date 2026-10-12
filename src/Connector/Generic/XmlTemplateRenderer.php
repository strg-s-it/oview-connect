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
 *   {{#if feld}}...{{/if}}       - Block nur, wenn feld einen Wert hat (nicht
 *                                  leer, nicht 0) -- z.B. ein optionales
 *                                  Attribut wie MGM Rabatt="..." (Crystalp,
 *                                  Handbuch 1.0.3), das ohne Rabatt ganz
 *                                  fehlen soll statt leer gesendet zu werden.
 *                                  Innerhalb von each bezogen auf den Eintrag.
 *
 * Beispiel (analog zum MGM GefallAdd-Beispiel im Handbuch):
 *   <Positionen>
 *     {{#each positionen}}
 *     <zl ArtNr="{{ArtNr}}" Menge="{{Menge}}" Brutto="{{Brutto}}" Ust="{{Ust}}"{{#if Rabatt}} Rabatt="{{Rabatt}}"{{/if}}/>
 *     {{/each}}
 *   </Positionen>
 *
 * Bewusst eine kleine Eigenimplementierung statt einer Template-Engine --
 * die obigen Konstrukte reichen fuer die bekannten XML-Connector-Faelle
 * (MGM); verschachtelte each-/if-Bloecke und else gibt es nicht.
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
        $template = preg_replace_callback(
            '/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s',
            static function (array $matches) use ($values): string {
                $value = $values[$matches[1]] ?? null;
                $hasValue = $value !== null && $value !== '' && $value !== false
                    && !is_array($value) && !(is_numeric($value) && (float) $value == 0.0);

                return $hasValue ? $matches[2] : '';
            },
            $template
        );

        if ($template === null) {
            throw new \RuntimeException('XML-Template konnte nicht verarbeitet werden (if-Bloecke).');
        }

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
