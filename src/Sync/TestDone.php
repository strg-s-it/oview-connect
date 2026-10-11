<?php

declare(strict_types=1);

namespace Connect\Sync;

/**
 * Kein Fehler: beendet einen Test-Zweig vorzeitig (admin/operation_edit.php, Zusatzliste),
 * damit der gemeinsame try/catch der Testausgabe nicht doppelt laeuft.
 */
final class TestDone extends \RuntimeException
{
}
