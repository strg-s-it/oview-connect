<?php

declare(strict_types=1);

/**
 * connect/public/admin/_nav.php
 *
 * Zentrale Liste der allgemeinen Admin-Navigation (siehe $adminNavItems in
 * _header.php) -- an EINER Stelle gepflegt statt in jeder Admin-Seite
 * dupliziert, damit ein neuer Menuepunkt nicht mehr in einem Dutzend
 * Dateien einzeln nachgezogen werden muss.
 *
 * Verwendung:
 *   $adminNavItems = require __DIR__ . '/_nav.php';                (admin/*.php)
 *   $adminNavItems = require dirname(__DIR__) . '/admin/_nav.php';  (app/_*_body.php)
 *
 * Struktur (Usability-Ueberarbeitung, 2026-09-26 -- vorher eine flache
 * 10-Punkte-Leiste): jeder Eintrag ist entweder
 *   ['link', url, label]              ein direkter Link in der Leiste
 *   ['group', label, [[url,label],...]] ein Dropdown mit mehreren Links
 * _header.php rendert beides nebeneinander. "Shops" bleibt bewusst ein
 * direkter Link (haeufigster Einstiegspunkt, ein Klick), der Rest ist nach
 * Aufgabe gruppiert: "Konfiguration" (Aufbau/Einrichtung, selten geaendert)
 * und "Betrieb" (laufender Betrieb/Beobachtung, oefter besucht).
 *
 * "Konfiguration" ist seit 2026-10-10 wieder ein Dropdown (Branding, Landkarte, Rechtstexte);
 * die Listen (Connector Types, Job-/Mapping-Vorlagen, Funktionen, Lookup-Tabellen) sind
 * weiterhin von der Landkarte (config_map.php) aus verlinkt.
 */

// Neuordnung 2026-10-10 (vorher 13 Punkte unter "Betrieb"): nach Aufgabe getrennt, innerhalb
// eines Abschnitts alphabetisch. [null, 'Titel'] = Zwischenueberschrift im Dropdown.
// Icons je Seite/Gruppe: Connect\Ui\Icon::PAGES / GROUPS.
// Wuensche: Zahl der noch nicht freigegebenen hinter dem Namen (Migration 0093)
$pendingWishes = isset($pdo) && $pdo instanceof \PDO ? (new \Connect\Wishes\WishBoard($pdo))->pendingCount() : 0;

return [
    ['link', 'shops.php', 'Shops'],
    ['group', 'Händler', [
        ['privacy_requests.php', 'Datenschutz'],
        ['messages.php', 'Nachrichten'],
        ['transition.php', 'Umstieg'],
        ['wishes.php', 'Wünsche' . ($pendingWishes > 0 ? ' (' . $pendingWishes . ' zu prüfen)' : '')],
    ]],
    ['group', 'Betrieb', [
        [null, 'Überwachung'],
        ['jobs.php', 'Jobs'],
        ['load.php', 'Lastverteilung'],
        ['activity_log.php', 'Protokoll'],
        ['webhooks.php', 'Webhooks'],
        [null, 'Kontrolle'],
        ['stock_overview.php', 'Lagerbestand'],
        ['invoices_overview.php', 'Rechnungen'],
        ['tax_monitoring.php', 'Steuer-Kontrolle'],
        [null, 'System'],
        ['db_health.php', 'Datenbank'],
    ]],
    ['group', 'Konfiguration', [
        ['branding.php', 'Branding'],
        ['config_map.php', 'Landkarte'],
        ['legal_texts.php', 'Rechtstexte'],
    ]],
];
