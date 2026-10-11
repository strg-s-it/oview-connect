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
