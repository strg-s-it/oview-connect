<?php

declare(strict_types=1);

namespace Connect\Sync;

/**
 * Geworfen von ProductMatchContext::resolve(), wenn der gemeinsame Objekt-/
 * Feld-Kontext (noch) nicht aufgeloest werden kann -- $status uebernimmt die
 * bisherige skipped/error-Unterscheidung aus StockSyncRunner::runForShop(),
 * damit Aufrufer (StockSyncRunner, ProductComparisonService) sie ohne
 * String-Vergleich der Meldung weiterreichen koennen.
 */
final class ProductMatchUnresolvedException extends \RuntimeException
{
    /**
     * @param string $status skipped | error
     */
    public function __construct(public readonly string $status, string $message)
    {
        parent::__construct($message);
    }
}
