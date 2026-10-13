<?php

declare(strict_types=1);

/**
 * connect/public/app/_loading_overlay_body.php
 *
 * Teil 2 des Lade-Overlays (Teil 1: _loading_overlay_head.php): das Markup, direkt nach <body>.
 * Das Logo ist hier als SVG nachgebaut (Kreisdiagramm mit abgesetztem Stueck), unabhaengig vom
 * hochgeladenen Branding-Logo, das quer und fuer die Kopfzeile gedacht ist.
 */
?>
    <div id="cx-overlay" role="status" aria-live="polite" aria-label="Seite wird geladen">
        <svg class="cx-logo" viewBox="0 0 1024 1024" aria-hidden="true">
            <g class="cx-ring">
                <path d="M560 190 A320 320 0 1 0 832 458 L512 512 Z" fill="none" stroke="#0f1b2d" stroke-width="112" stroke-linejoin="round" stroke-linecap="round"/>
            </g>
            <path class="cx-slice" d="M685 345 L708 138 A207 207 0 0 1 888 312 Z" fill="#18897b" stroke="#18897b" stroke-width="36" stroke-linejoin="round"/>
        </svg>
        <div class="cx-shadow"></div>
        <div class="cx-msg" id="cx-msg">Einen Moment …</div>
    </div>
