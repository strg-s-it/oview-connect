<?php
declare(strict_types=1);
/**
 * connect/public/app/_footer.php
 *
 * Gegenstueck zu _header.php -- schliesst die dort geoeffneten Container
 * und bindet das Tabler-JS ein.
 */
?>
                    <?php if (isset($adminNavItems) && $adminNavItems !== []): ?>
                        <?php // Code-Stand (Connect\Version) -- nur im Admin; aendert sich mit jedem Upload ?>
                        <?php $footerVersion = \Connect\Version::current(); ?>
                        <div class="text-secondary small text-end mt-4 mb-n2" title="Fingerabdruck über <?php echo (int) $footerVersion['files']; ?> Programmdateien">
                            <?php echo \Connect\Ui\Icon::svg('git-commit', 14, 'me-1'); ?>Version <span class="font-monospace"><?php echo htmlspecialchars($footerVersion['hash']); ?></span>
                            · Stand <?php echo htmlspecialchars($footerVersion['updated_at'] !== '' ? date('d.m.Y H:i', (int) strtotime($footerVersion['updated_at'])) : '–'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <button type="button" id="connect-scroll-top" class="btn btn-primary btn-icon" title="Nach oben" aria-label="Nach oben" hidden>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5"/><path d="M6 11l6-6 6 6"/></svg>
    </button>
    <style>
        #connect-scroll-top { position: fixed; right: 1.25rem; bottom: 1.25rem; z-index: 1030; border-radius: 50%; width: 2.75rem; height: 2.75rem; box-shadow: 0 2px 8px rgba(0, 0, 0, .2); }
        #connect-scroll-top[hidden] { display: none; }
    </style>
    <script>
        // "Nach oben"-Pfeil rechts unten, erscheint erst nach etwas Scrollen.
        (function () {
            var button = document.getElementById('connect-scroll-top');
            if (!button) { return; }
            function update() { button.hidden = window.scrollY < 300; }
            window.addEventListener('scroll', update, { passive: true });
            button.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
            update();
        })();
    </script>
    <?php if (!empty($GLOBALS['connect_ct'])): ?>
    <script>
        // Cookielose Anmeldung (Connect\Security\PageToken, nur fuer Shops mit aktivem Schalter -- sonst wird dieser
        // Block gar nicht ausgegeben): der Seiten-Token haengt an jedem Link, Formular, fetch-Aufruf und window.open
        // innerhalb der App und ersetzt so das Session-Cookie, das Browser im Shopify-iframe blockieren.
        (function () {
            var ct = <?php echo json_encode((string) $GLOBALS['connect_ct']); ?>;
            if (!ct) { return; }

            function appUrl(href) {
                try {
                    var u = new URL(href, window.location.href);
                    if (u.origin !== window.location.origin) { return null; }
                    // nur Seiten der App, keine Dateien (CSS/JS/Bilder/Downloads)
                    if (/\/assets\//.test(u.pathname) || /\/brand\.php$/.test(u.pathname)) { return null; }
                    if (!/\.php$/.test(u.pathname) && !/\/$/.test(u.pathname)) { return null; }
                    return u;
                } catch (e) { return null; }
            }
            function withCt(href) {
                var u = appUrl(href);
                if (!u) { return href; }
                u.searchParams.set('ct', ct);
                return u.href;
            }
            window.connectWithCt = withCt;
            // fuer Seiten, die sich selbst neu laden (Fortschrittsanzeigen): der Token in der Adresse waere sonst
            // so alt wie die Seite, der neue steckt nur in diesem Skript
            window.connectReload = function () { window.location.href = withCt(window.location.href); };

            function tagLinks(root) {
                Array.prototype.forEach.call((root || document).querySelectorAll('a[href]'), function (a) {
                    var href = a.getAttribute('href') || '';
                    if (href === '' || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href) || a.hasAttribute('download')) { return; }
                    var rewritten = withCt(a.href);
                    if (rewritten !== a.href) { a.href = rewritten; }
                });
            }
            tagLinks(document);
            // Fangnetz fuer spaeter eingefuegte Links
            document.addEventListener('click', function (e) {
                var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
                if (a) { tagLinks(a.parentNode); }
            }, true);

            function tagForm(form) {
                if (!form || form.nodeName !== 'FORM') { return; }
                var input = form.querySelector('input[name="ct"]');
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ct';
                    form.appendChild(input);
                }
                input.value = ct;
            }
            Array.prototype.forEach.call(document.querySelectorAll('form'), tagForm);
            document.addEventListener('submit', function (e) { tagForm(e.target); }, true);

            // fetch innerhalb der App: Token als Header (der Server bevorzugt ihn vor Formular und Adresse)
            var nativeFetch = window.fetch;
            if (nativeFetch) {
                window.fetch = function (input, init) {
                    try {
                        var url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
                        if (appUrl(url)) {
                            init = init || {};
                            var headers = new Headers(init.headers || (typeof input !== 'string' && input ? input.headers : undefined));
                            headers.set('X-Connect-Token', ct);
                            init.headers = headers;
                        }
                    } catch (e) { /* ohne Token weiter, der Server entscheidet */ }
                    return nativeFetch.call(this, input, init);
                };
            }

            var nativeOpen = window.open;
            window.open = function (url) {
                var args = Array.prototype.slice.call(arguments);
                if (typeof url === 'string') { args[0] = withCt(url); }
                return nativeOpen.apply(window, args);
            };
        })();
    </script>
    <?php endif; ?>
    <script>
        // CSRF-Token (siehe bootstrap.php / admin_bootstrap.php) in jedes POST-
        // Formular eintragen. Bewusst per Script statt in jedem der ~60
        // Formulare einzeln: dieser Footer wird von allen Seiten mit Formularen
        // eingebunden (Merchant UND Admin). GET-Formulare (Filter, Seitengroesse)
        // bekommen kein Token, damit es nicht in der URL landet.
        (function () {
            var token = <?php echo json_encode((string) ($_SESSION['connect_csrf'] ?? '')); ?>;
            if (!token) { return; }
            function addToken(form) {
                if (!form || form.nodeName !== 'FORM') { return; }
                // getAttribute statt form.method: ein Feld namens "method" wuerde die Property ueberdecken.
                if ((form.getAttribute('method') || '').toLowerCase() !== 'post') { return; }
                if (form.querySelector('input[name="_csrf"]')) { return; }
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = '_csrf';
                input.value = token;
                form.appendChild(input);
            }
            Array.prototype.forEach.call(document.querySelectorAll('form'), addToken);
            // Fangnetz fuer spaeter eingefuegte Formulare; form.submit() loest dieses Event nicht aus,
            // dafuer sorgt der Durchlauf oben.
            document.addEventListener('submit', function (e) { addToken(e.target); }, true);
        })();
    </script>
    <script src="../assets/vendor/tabler/js/tabler.min.js"></script>
</body>
</html>
