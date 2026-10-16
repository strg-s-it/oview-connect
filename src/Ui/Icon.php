<?php

declare(strict_types=1);

namespace Connect\Ui;

/**
 * Icons fuer Navigation und Uebersichten -- Auswahl aus Tabler Icons 3.49.0 (outline),
 * MIT-Lizenz, Copyright (c) 2020-2026 Pawel Kuna, https://tabler.io/icons.
 * Bewusst als Inline-SVG im Code (keine Webfont/CDN): keine Anfrage an einen weiteren Anbieter,
 * nichts zusaetzlich zu laden. Neue Icons: scratchpad-Generator bzw. SVG-Inhalt aus dem Paket
 * @tabler/icons (icons/outline/<name>.svg) hier eintragen.
 *
 * Verwendung: Icon::svg('packages'), Icon::forPage('stock.php')
 */
final class Icon
{
    private const PATHS = [
        'home' => '<path d="M5 12l-2 0l9 -9l9 9l-2 0" /><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7" /><path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6" />',
        'building-store' => '<path d="M3 21l18 0" /><path d="M3 7v1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1h-18l2 -4h14l2 4" /><path d="M5 21l0 -10.15" /><path d="M19 21l0 -10.15" /><path d="M9 21v-4a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v4" />',
        'users' => '<path d="M5 7a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /><path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />',
        'transfer' => '<path d="M20 10h-16l5.5 -6" /><path d="M4 14h16l-5.5 6" />',
        'mail' => '<path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10" /><path d="M3 7l9 6l9 -6" />',
        'shield-lock' => '<path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /><path d="M11 11a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M12 12l0 2.5" />',
        'activity' => '<path d="M3 12h4l3 8l4 -16l3 8h4" />',
        'list-check' => '<path d="M3.5 5.5l1.5 1.5l2.5 -2.5" /><path d="M3.5 11.5l1.5 1.5l2.5 -2.5" /><path d="M3.5 17.5l1.5 1.5l2.5 -2.5" /><path d="M11 6l9 0" /><path d="M11 12l9 0" /><path d="M11 18l9 0" />',
        'server' => '<path d="M3 7a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-2" /><path d="M3 15a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3l0 -2" /><path d="M7 8l0 .01" /><path d="M7 16l0 .01" />',
        'webhook' => '<path d="M4.876 13.61a4 4 0 1 0 6.124 3.39h6" /><path d="M15.066 20.502a4 4 0 1 0 1.934 -7.502c-.706 0 -1.424 .179 -2 .5l-3 -5.5" /><path d="M16 8a4 4 0 1 0 -8 0c0 1.506 .77 2.818 2 3.5l-3 5.5" />',
        'history' => '<path d="M12 8l0 4l2 2" /><path d="M3.05 11a9 9 0 1 1 .5 4m-.5 5v-5h5" />',
        'file-invoice' => '<path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2" /><path d="M9 7l1 0" /><path d="M9 13l6 0" /><path d="M13 17l2 0" />',
        'packages' => '<path d="M7 16.5l-5 -3l5 -3l5 3v5.5l-5 3l0 -5.5" /><path d="M2 13.5v5.5l5 3" /><path d="M7 16.545l5 -3.03" /><path d="M17 16.5l-5 -3l5 -3l5 3v5.5l-5 3l0 -5.5" /><path d="M12 19l5 3" /><path d="M17 16.5l5 -3" /><path d="M12 13.5v-5.5l-5 -3l5 -3l5 3v5.5" /><path d="M7 5.03v5.455" /><path d="M12 8l5 -3" />',
        'receipt-tax' => '<path d="M9 14l6 -6" /><path d="M9 8.5a.5 .5 0 1 0 1 0a.5 .5 0 1 0 -1 0" fill="currentColor" /><path d="M14 13.5a.5 .5 0 1 0 1 0a.5 .5 0 1 0 -1 0" fill="currentColor" /><path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16l-3 -2l-2 2l-2 -2l-2 2l-2 -2l-3 2" />',
        'database' => '<path d="M4 6a8 3 0 1 0 16 0a8 3 0 1 0 -16 0" /><path d="M4 6v6a8 3 0 0 0 16 0v-6" /><path d="M4 12v6a8 3 0 0 0 16 0v-6" />',
        'settings' => '<path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065" /><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" />',
        'map' => '<path d="M3 7l6 -3l6 3l6 -3v13l-6 3l-6 -3l-6 3v-13" /><path d="M9 4v13" /><path d="M15 7v13" />',
        'palette' => '<path d="M12 21a9 9 0 0 1 0 -18c4.97 0 9 3.582 9 8c0 1.06 -.474 2.078 -1.318 2.828c-.844 .75 -1.989 1.172 -3.182 1.172h-2.5a2 2 0 0 0 -1 3.75a1.3 1.3 0 0 1 -1 2.25" /><path d="M7.5 10.5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M11.5 7.5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M15.5 10.5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />',
        'license' => '<path d="M15 21h-9a3 3 0 0 1 -3 -3v-1h10v2a2 2 0 0 0 4 0v-14a2 2 0 1 1 2 2h-2m2 -4h-11a3 3 0 0 0 -3 3v11" /><path d="M9 7l4 0" /><path d="M9 11l4 0" />',
        'box' => '<path d="M12 3l8 4.5l0 9l-8 4.5l-8 -4.5l0 -9l8 -4.5" /><path d="M12 12l8 -4.5" /><path d="M12 12l0 9" /><path d="M12 12l-8 -4.5" />',
        'credit-card' => '<path d="M3 8a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3l0 -8" /><path d="M3 10l18 0" /><path d="M7 15l.01 0" /><path d="M11 15l2 0" />',
        'heartbeat' => '<path d="M19.5 13.572l-7.5 7.428l-2.896 -2.868m-6.117 -8.104a5 5 0 0 1 9.013 -3.022a5 5 0 1 1 7.5 6.572" /><path d="M3 13h2l2 3l2 -6l1 3h3" />',
        'adjustments' => '<path d="M4 10a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M6 4v4" /><path d="M6 12v8" /><path d="M10 16a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M12 4v10" /><path d="M12 18v2" /><path d="M16 7a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M18 4v1" /><path d="M18 9v11" />',
        'route' => '<path d="M3 19a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M19 7a2 2 0 1 0 0 -4a2 2 0 0 0 0 4" /><path d="M11 19h5.5a3.5 3.5 0 0 0 0 -7h-8a3.5 3.5 0 0 1 0 -7h4.5" />',
        'truck' => '<path d="M5 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M15 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M5 17h-2v-11a1 1 0 0 1 1 -1h9v12m-4 0h6m4 0h2v-6h-8m0 -5h5l3 5" />',
        'report' => '<path d="M8 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h5.697" /><path d="M18 14v4h4" /><path d="M18 11v-4a2 2 0 0 0 -2 -2h-2" /><path d="M8 5a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2" /><path d="M14 18a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M8 11h4" /><path d="M8 15h3" />',
        'plug-connected' => '<path d="M7 12l5 5l-1.5 1.5a3.536 3.536 0 1 1 -5 -5l1.5 -1.5" /><path d="M17 12l-5 -5l1.5 -1.5a3.536 3.536 0 1 1 5 5l-1.5 1.5" /><path d="M3 21l2.5 -2.5" /><path d="M18.5 5.5l2.5 -2.5" /><path d="M10 11l-2 2" /><path d="M13 14l-2 2" />',
        'eye' => '<path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />',
        'clock' => '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 7v5l3 3" />',
        'alert-triangle' => '<path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0" /><path d="M12 16h.01" />',
        'circle-check' => '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" />',
        'info-circle' => '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 9h.01" /><path d="M11 12h1v4h1" />',
        'refresh' => '<path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />',
        'tool' => '<path d="M7 10h3v-3l-3.5 -3.5a6 6 0 0 1 8 8l6 6a2 2 0 0 1 -3 3l-6 -6a6 6 0 0 1 -8 -8l3.5 3.5" />',
        'git-commit' => '<path d="M9 12a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /><path d="M12 3l0 6" /><path d="M12 15l0 6" />',
        'arrows-exchange' => '<path d="M7 10h14l-4 -4" /><path d="M17 14h-14l4 4" />',
        'cash-register' => '<path d="M21 15h-2.5c-.398 0 -.779 .158 -1.061 .439c-.281 .281 -.439 .663 -.439 1.061c0 .398 .158 .779 .439 1.061c.281 .281 .663 .439 1.061 .439h1c.398 0 .779 .158 1.061 .439c.281 .281 .439 .663 .439 1.061c0 .398 -.158 .779 -.439 1.061c-.281 .281 -.663 .439 -1.061 .439h-2.5" /><path d="M19 21v1m0 -8v1" /><path d="M13 21h-7c-.53 0 -1.039 -.211 -1.414 -.586c-.375 -.375 -.586 -.884 -.586 -1.414v-10c0 -.53 .211 -1.039 .586 -1.414c.375 -.375 .884 -.586 1.414 -.586h2m12 3.12v-1.12c0 -.53 -.211 -1.039 -.586 -1.414c-.375 -.375 -.884 -.586 -1.414 -.586h-2" /><path d="M16 10v-6c0 -.53 -.211 -1.039 -.586 -1.414c-.375 -.375 -.884 -.586 -1.414 -.586h-4c-.53 0 -1.039 .211 -1.414 .586c-.375 .375 -.586 .884 -.586 1.414v6m8 0h-8m8 0h1m-9 0h-1" /><path d="M8 14v.01" /><path d="M8 17v.01" /><path d="M12 13.99v.01" /><path d="M12 17v.01" />',
        'logout' => '<path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" /><path d="M9 12h12l-3 -3" /><path d="M18 15l3 -3" />',
        'player-play' => '<path d="M7 4v16l13 -8l-13 -8" />',
        'chevron-right' => '<path d="M9 6l6 6l-6 6" />',
        'trash' => '<path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" />',
        'calendar-event' => '<path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2l0 -12" /><path d="M16 3l0 4" /><path d="M8 3l0 4" /><path d="M4 11l16 0" /><path d="M8 15h2v2h-2l0 -2" />',
        'files' => '<path d="M15 3v4a1 1 0 0 0 1 1h4" /><path d="M18 17h-7a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h4l5 5v7a2 2 0 0 1 -2 2" /><path d="M16 17v2a2 2 0 0 1 -2 2h-7a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h2" />',
        'table' => '<path d="M3 5a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-14" /><path d="M3 10h18" /><path d="M10 3v18" />',
        'mail-forward' => '<path d="M12 18h-7a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v7.5" /><path d="M3 6l9 6l9 -6" /><path d="M15 18h6" /><path d="M18 15l3 3l-3 3" />',
        'tag' => '<path d="M6.5 7.5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M3 6v5.172a2 2 0 0 0 .586 1.414l7.71 7.71a2.41 2.41 0 0 0 3.408 0l5.592 -5.592a2.41 2.41 0 0 0 0 -3.408l-7.71 -7.71a2 2 0 0 0 -1.414 -.586h-5.172a3 3 0 0 0 -3 3" />',
        'help-circle' => '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 17l0 .01" /><path d="M12 13.5a1.5 1.5 0 0 1 1 -1.5a2.6 2.6 0 1 0 -3 -4" />',
        'bulb' => '<path d="M3 12h1m8 -9v1m8 8h1m-15.4 -6.4l.7 .7m12.1 -.7l-.7 .7" /><path d="M9 16a5 5 0 1 1 6 0a3.5 3.5 0 0 0 -1 3a2 2 0 0 1 -4 0a3.5 3.5 0 0 0 -1 -3" /><path d="M9.7 17l4.6 0" />',
        'lock' => '<path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6" /><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /><path d="M8 11v-4a4 4 0 1 1 8 0v4" />',
        'thumb-up' => '<path d="M7 11v8a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1v-7a1 1 0 0 1 1 -1h3a4 4 0 0 0 4 -4v-1a2 2 0 0 1 4 0v5h3a2 2 0 0 1 2 2l-1 5a2 3 0 0 1 -2 2h-7a3 3 0 0 1 -3 -3" />',
    ];

    /** Seite (Dateiname) => Icon, fuer die Menues (app/_header.php). */
    public const PAGES = [
        // Merchant
        'dashboard.php' => 'home',
        'stock.php' => 'packages',
        'invoices.php' => 'file-invoice',
        'products.php' => 'box',
        'sync_mappings.php' => 'adjustments',
        'status.php' => 'heartbeat',
        'billing.php' => 'credit-card',
        'prices.php' => 'tag',
        'stock_history.php' => 'history',
        'product_check.php' => 'list-check',
        'bundles.php' => 'packages',
        'wishes.php' => 'bulb',
        // Admin, je Shop
        'flows.php' => 'route',
        'objects.php' => 'table',
        'connections.php' => 'plug-connected',
        'mappings.php' => 'arrows-exchange',
        'shop_fields.php' => 'tag',
        'weekly_report.php' => 'calendar-event',
        'migrate.php' => 'truck',
        // Admin, allgemein
        'shops.php' => 'building-store',
        'transition.php' => 'transfer',
        'messages.php' => 'mail',
        'privacy_requests.php' => 'shield-lock',
        'jobs.php' => 'list-check',
        'load.php' => 'server',
        'webhooks.php' => 'webhook',
        'activity_log.php' => 'history',
        'invoices_overview.php' => 'file-invoice',
        'stock_overview.php' => 'packages',
        'tax_monitoring.php' => 'receipt-tax',
        'db_health.php' => 'database',
        'config_map.php' => 'map',
        'branding.php' => 'palette',
        'legal_texts.php' => 'license',
        'faq.php' => 'help-circle',
    ];

    /** Menue-Gruppe (Bezeichnung) => Icon */
    public const GROUPS = [
        'Händler' => 'users',
        'Betrieb' => 'activity',
        'Konfiguration' => 'settings',
        'Daten' => 'database',
        'Einrichtung' => 'tool',
    ];

    public static function svg(string $name, int $size = 18, string $class = ''): string
    {
        $inner = self::PATHS[$name] ?? null;
        if ($inner === null) {
            return '';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES) : '') . '" aria-hidden="true" focusable="false">'
            . $inner . '</svg>';
    }

    /** Icon zur Seite einer URL (z.B. "stock.php?shop=x"), leer wenn keins hinterlegt. */
    public static function forPage(string $url, int $size = 18): string
    {
        $page = basename((string) parse_url($url, PHP_URL_PATH));

        return isset(self::PAGES[$page]) ? self::svg(self::PAGES[$page], $size) : '';
    }

    public static function forGroup(string $label, int $size = 18): string
    {
        return isset(self::GROUPS[$label]) ? self::svg(self::GROUPS[$label], $size) : '';
    }
}
