<?php

declare(strict_types=1);

/**
 * connect/public/app/_loading_overlay_head.php
 *
 * Teil 1 des Lade-Overlays (Teil 2: _loading_overlay_body.php), eingebunden in _header.php
 * (gilt damit fuer Merchant-, Admin- und Assistenten-Seiten). Zweck: waehrend die Seite laedt
 * oder nach einem Klick/Absenden eine neue Seite kommt, sperrt ein Overlay die Bedienung --
 * kein Doppelklick auf "Importieren", kein zweites Absenden. Das Overlay erscheint erst nach
 * 0,35 s (schnelle Seiten flackern nicht), faengt aber von Anfang an die Klicks ab.
 *
 * Ohne JavaScript bleibt die Seite normal bedienbar (die Klasse "cx-loading" setzt erst das Script).
 */
?>
    <style>
        #cx-overlay { position: fixed; inset: 0; z-index: 99999; display: none; flex-direction: column; align-items: center; justify-content: center; gap: .5rem;
            background: rgba(255, 255, 255, .9); -webkit-backdrop-filter: blur(3px); backdrop-filter: blur(3px); opacity: 0; cursor: progress; }
        html.cx-loading #cx-overlay { display: flex; animation: cx-fade .25s ease .35s forwards; }
        .cx-logo { width: 132px; height: 132px; overflow: visible; }
        .cx-ring { transform-origin: 512px 512px; animation: cx-roll 2.4s cubic-bezier(.6, 0, .35, 1) infinite; }
        .cx-slice { animation: cx-hop 1.2s ease-in-out infinite; }
        .cx-shadow { width: 84px; height: 10px; border-radius: 50%; background: rgba(15, 27, 45, .16); animation: cx-shadow 1.2s ease-in-out infinite; margin-top: -4px; }
        .cx-msg { margin-top: 1rem; min-height: 1.5em; font-size: 1rem; color: #4b5b70; text-align: center; padding: 0 1rem; }
        @keyframes cx-fade { to { opacity: 1; } }
        /* Ring rollt wie ein Rad, steht dann kurz still */
        @keyframes cx-roll { 0% { transform: rotate(0); } 70%, 100% { transform: rotate(360deg); } }
        /* Das Stueck huepft mit Quetschen und Strecken, Landung im Takt des Schattens */
        @keyframes cx-hop {
            0%   { transform: translate(0, 0) scale(1.08, .86); transform-origin: 780px 330px; }
            18%  { transform: translate(14px, -70px) scale(.92, 1.12) rotate(8deg); transform-origin: 780px 330px; }
            50%  { transform: translate(24px, -150px) scale(1) rotate(-10deg); transform-origin: 780px 330px; }
            82%  { transform: translate(8px, -40px) scale(.95, 1.08) rotate(4deg); transform-origin: 780px 330px; }
            100% { transform: translate(0, 0) scale(1.08, .86); transform-origin: 780px 330px; }
        }
        @keyframes cx-shadow { 0%, 100% { transform: scaleX(1); opacity: 1; } 50% { transform: scaleX(.55); opacity: .5; } }
        @media (prefers-reduced-motion: reduce) {
            .cx-ring, .cx-slice, .cx-shadow { animation: none; }
        }
    </style>
    <script>
        (function () {
            var root = document.documentElement;
            var hideTimer = null;

            function show(maxMs) {
                root.classList.add('cx-loading');
                clearTimeout(hideTimer);
                // Sicherheitsnetz: bleibt die Seite stehen (Datei-Download, abgebrochene Navigation), geht das Overlay wieder weg
                hideTimer = setTimeout(hide, maxMs);
            }
            function hide() {
                root.classList.remove('cx-loading');
                clearTimeout(hideTimer);
            }

            // Seite laedt gerade
            root.classList.add('cx-loading');
            hideTimer = setTimeout(hide, 30000);
            window.addEventListener('load', hide);
            // Zurueck-Navigation aus dem Browser-Cache: Seite ist sofort da
            window.addEventListener('pageshow', function (event) { if (event.persisted) { hide(); } });

            // Neue Seite per Link
            document.addEventListener('click', function (event) {
                if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
                var link = event.target.closest ? event.target.closest('a[href]') : null;
                if (!link || (link.target && link.target !== '_self') || link.hasAttribute('download') || link.hasAttribute('data-bs-toggle')) { return; }
                var href = link.getAttribute('href') || '';
                if (href === '' || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) { return; }
                if (link.origin !== window.location.origin) { return; }
                if (link.pathname === window.location.pathname && link.search === window.location.search && link.hash !== '') { return; }
                show(30000);
            });

            // Formular wird abgesendet
            document.addEventListener('submit', function (event) {
                var form = event.target;
                if (event.defaultPrevented || !form || (form.target && form.target !== '_self') || form.hasAttribute('data-no-overlay')) { return; }
                show(60000);
            });

            // Lustige Statuszeilen im Wechsel
            var messages = [
                'Einen Moment …',
                'Zähle Lagerbestände …',
                'Frage die Kasse nach dem Weg …',
                'Sortiere Produkte nach Farbe …',
                'Wecke den Server auf …',
                'Poliere das Logo …'
            ];
            var index = 0;
            setInterval(function () {
                var element = document.getElementById('cx-msg');
                if (!element || !root.classList.contains('cx-loading')) { return; }
                index = (index + 1) % messages.length;
                element.textContent = messages[index];
            }, 2400);
        })();
    </script>
