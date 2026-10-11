<?php

declare(strict_types=1);

/**
 * connect/public/index.php
 *
 * Merchant-Einstiegspunkt (Ziel des kuenftigen "Neue Welt (Beta)"-Buttons
 * in der alten Welt). Bewusst ein duenner Redirect auf app/dashboard.php --
 * so bleibt "/" als stabiler, einfacher Einstiegspunkt bestehen, waehrend
 * das eigentliche Dashboard wie jede andere Merchant-Seite unter app/ liegt.
 *
 * TODO (siehe Diskussion "Migrationsbruecke"): hier wird spaeter zusaetzlich
 * einmalig der Shopify-Token uebernommen/erneuert (Token-Exchange gegen das
 * neue ablaufende Token-Modell), Scopes geprueft, Webhooks registriert und
 * eine Erstbefuellung (Produkte/Kategorien) angestossen -- aktuell nur der
 * reine Redirect.
 */

// Kompletten Query-String weiterreichen (shop, host, expires, sig) --
// bootstrap.php auf app/dashboard.php prueft die Signatur, die hier sonst
// verloren ginge.
header('Location: app/dashboard.php?' . $_SERVER['QUERY_STRING']);
exit;
