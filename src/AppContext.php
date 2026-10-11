<?php

declare(strict_types=1);

namespace Connect;

use Connect\Security\Crypto;
use PDO;

/**
 * Buendelt das, was fast jede Connect-Seite braucht (DB-Verbindung,
 * Verschluesselung, Shop-Kontext) in einem einzigen Objekt, statt lose
 * globale Variablen zu verwenden. Wird von bootstrap.php einmal pro Request
 * aufgebaut und an die aufrufende Seite zurueckgegeben.
 */
final class AppContext
{
    public function __construct(
        public readonly PDO $pdo,
        public readonly Crypto $crypto,
        public readonly string $shop,
        public readonly Config $config,
        public readonly string $host = ''
    ) {
    }
}
