<?php

declare(strict_types=1);

/**
 * Kurzform fuer Connect\I18n\Lang::t() -- in den Seiten: t('Übersicht'), t('Schritt {n}', ['n' => 2]).
 * Wird von bootstrap.php und public/app/_header.php geladen (require_once).
 */
if (!function_exists('t')) {
    /**
     * @param array<string, scalar> $vars
     */
    function t(string $german, array $vars = []): string
    {
        return \Connect\I18n\Lang::t($german, $vars);
    }
}
