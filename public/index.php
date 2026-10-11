<?php

declare(strict_types=1);

/**
 * connect/public/index.php
 *
 * Merchant-Einstiegspunkt (Ziel des Handoff-Links aus connect_beta.php der
 * alten Welt). Bewusst ein duenner Redirect auf app/dashboard.php -- so bleibt
 * "/" als stabiler, einfacher Einstiegspunkt bestehen, waehrend das eigentliche
 * Dashboard wie jede andere Merchant-Seite unter app/ liegt.
 *
 * Umstellung auf ablaufende Shopify-Offline-Tokens: gehoert NICHT hierher (der
 * Tausch laeuft serverseitig ohne Merchant), siehe CLAUDE.md "Offene Themen".
 */

// Kompletten Query-String weiterreichen (shop, host, expires, sig) --
// bootstrap.php auf app/dashboard.php prueft die Signatur, die hier sonst
// verloren ginge.
header('Location: app/dashboard.php?' . $_SERVER['QUERY_STRING']);
exit;
