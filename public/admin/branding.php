<?php

declare(strict_types=1);

/**
 * connect/public/admin/branding.php
 *
 * Betrieb -> Branding: Name, Logo, Favicon, Hauptfarbe, Schrift und Mail-Fusszeile von oView
 * (Connect\Setup\Branding, Migration 0083). Wirkt auf App, Admin-Login, Einrichtungs-
 * Assistent und alle Mails an Merchants.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Setup\Branding;
use Connect\Setup\Settings;

$config = Config::load();
$pdo = Database::connect($config)->pdo();
$auth = new AdminAuth($pdo);
$auth->requireLogin();
$adminEmail = (string) $auth->currentEmail();
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);

$flashMessage = null;
$flashType = 'info';

$migrationReady = true;
try {
    $pdo->query('SELECT 1 FROM connect_brand_assets LIMIT 1');
} catch (\PDOException) {
    $migrationReady = false;
}

/** Hochgeladene Datei pruefen. @return array{0: ?string, 1: ?string, 2: ?string} [mime, data, Fehler] */
$readUpload = static function (string $field): array {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null, null];
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        return [null, null, 'Upload fehlgeschlagen (Fehler ' . (int) $file['error'] . ').'];
    }
    if ((int) $file['size'] > Branding::ASSET_MAX_BYTES) {
        return [null, null, 'Die Datei ist größer als ' . (int) (Branding::ASSET_MAX_BYTES / 1000) . ' KB.'];
    }
    $data = (string) file_get_contents((string) $file['tmp_name']);
    $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
    if (in_array($mime, ['image/svg', 'text/xml', 'application/xml', 'text/plain', 'text/html'], true) && preg_match('/<svg[\s>]/i', substr($data, 0, 4096)) === 1) {
        $mime = 'image/svg+xml';
    }
    if (!isset(Branding::ASSET_MIME[$mime])) {
        return [null, null, 'Nur PNG, SVG, JPG, WebP oder ICO (erkannt: ' . $mime . ').'];
    }
    if ($mime === 'image/svg+xml' && preg_match('/<script|on\w+\s*=|javascript:/i', $data) === 1) {
        return [null, null, 'Das SVG enthält Skripte oder Ereignis-Attribute – bitte ohne Skripte exportieren.'];
    }

    return [$mime, $data, null];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $migrationReady) {
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'reset') {
        $pdo->exec("DELETE FROM connect_settings WHERE setting_key LIKE 'brand\\_%'");
        $pdo->exec('DELETE FROM connect_brand_assets');
        $flashMessage = 'Branding auf Standard zurückgesetzt.';
        $flashType = 'success';
    } elseif (str_starts_with($action, 'remove_') && isset(Branding::ASSETS[substr($action, 7)])) {
        $pdo->prepare('DELETE FROM connect_brand_assets WHERE asset_key = :key')->execute(['key' => substr($action, 7)]);
        $flashMessage = Branding::ASSETS[substr($action, 7)] . ' entfernt.';
        $flashType = 'success';
    } else {
        $errors = [];
        $primary = Branding::normalizeColor((string) ($_POST['primary'] ?? ''));
        if ($primary === null) {
            $errors[] = 'Die Farbe muss ein Hex-Wert sein, z. B. #066fd1.';
        }
        $font = (string) ($_POST['font'] ?? 'system');
        if (!isset(Branding::FONTS[$font])) {
            $font = 'system';
        }
        $uploads = [];
        foreach (array_keys(Branding::ASSETS) as $key) {
            [$mime, $data, $error] = $readUpload($key);
            if ($error !== null) {
                $errors[] = Branding::ASSETS[$key] . ': ' . $error;
            } elseif ($data !== null) {
                $uploads[$key] = [$mime, $data];
            }
        }

        if ($errors !== []) {
            $flashMessage = implode(' ', $errors);
            $flashType = 'danger';
        } else {
            $values = [
                'brand_name' => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 60),
                'brand_short' => mb_substr(trim((string) ($_POST['short'] ?? '')), 0, 30),
                'brand_primary' => $primary,
                'brand_font' => $font,
                'brand_mail_footer' => mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST['mail_footer'] ?? ''))), 0, 500),
                'brand_show_name' => isset($_POST['show_name']) ? '1' : '0',
            ];
            foreach ($values as $key => $value) {
                Settings::set($pdo, $key, $value);
            }
            foreach ($uploads as $key => [$mime, $data]) {
                $stmt = $pdo->prepare(
                    'INSERT INTO connect_brand_assets (asset_key, mime, data, hash) VALUES (:key, :mime, :data, :hash)
                     ON DUPLICATE KEY UPDATE mime = VALUES(mime), data = VALUES(data), hash = VALUES(hash)'
                );
                $stmt->bindValue('key', $key);
                $stmt->bindValue('mime', $mime);
                $stmt->bindValue('data', $data, \PDO::PARAM_LOB);
                $stmt->bindValue('hash', sha1($data));
                $stmt->execute();
            }
            $flashMessage = 'Branding gespeichert.' . ($uploads !== [] ? ' Neu hochgeladen: ' . implode(', ', array_map(static fn (string $k): string => Branding::ASSETS[$k], array_keys($uploads))) . '.' : '');
            $flashType = 'success';
        }
    }
    Branding::forget();
}

$brand = Branding::load($pdo, $config);
$stored = [];
if ($migrationReady) {
    foreach ($pdo->query("SELECT setting_key, setting_value FROM connect_settings WHERE setting_key LIKE 'brand\\_%'")->fetchAll() as $row) {
        $stored[(string) $row['setting_key']] = (string) $row['setting_value'];
    }
}

$pageTitle = 'Branding';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$topRightHtml = '<span class="text-secondary me-3">' . $e($adminEmail) . '</span><a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
require dirname(__DIR__) . '/app/_header.php';
?>

<?php if (!$migrationReady): ?>
    <div class="alert alert-warning">Migration 0083 fehlt noch – bitte zuerst <code>bin/migrate.php</code> ausführen.</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
<div class="row row-cards">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Name</h3></div>
            <div class="card-body d-grid gap-3">
                <div>
                    <label class="form-label" for="b-name">Produktname</label>
                    <input id="b-name" type="text" name="name" class="form-control" maxlength="60" value="<?php echo $e($stored['brand_name'] ?? ''); ?>" placeholder="<?php echo $e(Branding::DEFAULT_NAME); ?>">
                    <small class="text-secondary">Kopfzeile der App, Browser-Tab, Admin-Login. Leer = „<?php echo $e(Branding::DEFAULT_NAME); ?>“.</small>
                </div>
                <div>
                    <label class="form-label" for="b-short">Kurzname für Mails</label>
                    <input id="b-short" type="text" name="short" class="form-control" maxlength="30" value="<?php echo $e($stored['brand_short'] ?? ''); ?>" placeholder="<?php echo $e(Branding::DEFAULT_SHORT); ?>">
                    <small class="text-secondary">Steht oben in den Mails, wenn kein Logo hochgeladen ist.</small>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Logo und Favicon</h3></div>
            <div class="card-body d-grid gap-3">
                <?php foreach ([
                    'logo' => ['Logo', 'PNG oder SVG, quer, transparent. Angezeigt in 32 px Höhe – am besten etwa 400 × 96 px. Für Mails PNG verwenden: SVG zeigen Gmail und Outlook nicht an (dort erscheint dann der Kurzname).'],
                    'favicon' => ['Favicon', 'Symbol im Browser-Tab. Quadratisch, PNG oder ICO, 64 × 64 px. In der eingebetteten Shopify-App zeigt Shopify sein eigenes Symbol.'],
                ] as $key => [$label, $help]): ?>
                    <div>
                        <label class="form-label" for="b-<?php echo $key; ?>"><?php echo $e($label); ?></label>
                        <?php if ($brand->hasAsset($key)): ?>
                            <div class="d-flex align-items-center gap-3 mb-2 p-2 border rounded">
                                <img src="<?php echo $e($brand->assetUrl($key)); ?>" alt="" style="height: <?php echo $key === 'logo' ? 40 : 32; ?>px; max-width: 260px; object-fit: contain;">
                                <button type="submit" name="action" value="remove_<?php echo $key; ?>" class="btn btn-sm btn-outline-danger ms-auto" formnovalidate onclick="return confirm('<?php echo $e($label); ?> entfernen?')">Entfernen</button>
                            </div>
                        <?php endif; ?>
                        <input id="b-<?php echo $key; ?>" type="file" name="<?php echo $key; ?>" class="form-control" accept="image/png,image/svg+xml,image/jpeg,image/webp,image/x-icon,.ico">
                        <small class="text-secondary"><?php echo $e($help); ?> Höchstens <?php echo (int) (Branding::ASSET_MAX_BYTES / 1000); ?> KB.</small>
                    </div>
                <?php endforeach; ?>
                <label class="form-check mb-0">
                    <input type="checkbox" class="form-check-input" name="show_name" <?php echo $brand->showNameWithLogo ? 'checked' : ''; ?>>
                    <span class="form-check-label">Produktname neben dem Logo anzeigen <span class="text-secondary small">(aus, wenn der Name schon im Logo steht)</span></span>
                </label>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Farbe und Schrift</h3></div>
            <div class="card-body d-grid gap-3">
                <div>
                    <label class="form-label" for="b-primary">Hauptfarbe</label>
                    <div class="d-flex gap-2 align-items-center">
                        <input type="color" id="b-primary-picker" value="<?php echo $e($brand->primary); ?>" class="form-control form-control-color" title="Farbe wählen" aria-label="Farbe wählen">
                        <input id="b-primary" type="text" name="primary" class="form-control" style="max-width: 140px" value="<?php echo $e($brand->primary); ?>" pattern="#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?" required>
                        <button type="button" class="btn btn-sm" onclick="brandSetColor('<?php echo Branding::DEFAULT_PRIMARY; ?>')">Standard</button>
                    </div>
                    <small class="text-secondary">Buttons, Links, aktive Menüpunkte, Assistent und der Balken oben in den Mails.</small>
                    <div id="b-contrast" class="small mt-1"></div>
                </div>
                <div>
                    <label class="form-label" for="b-font">Schrift</label>
                    <select id="b-font" name="font" class="form-select">
                        <?php foreach (Branding::FONTS as $key => [$label, $stack]): ?>
                            <option value="<?php echo $e($key); ?>" data-stack="<?php echo $e($stack ?? ''); ?>" <?php echo $brand->font === $key ? 'selected' : ''; ?>><?php echo $e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-secondary">Nur Schriften, die auf jedem Gerät vorhanden sind – keine nachgeladenen Webfonts (Datenschutz, Ladezeit im Shopify-Admin).</small>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Mails</h3></div>
            <div class="card-body">
                <label class="form-label" for="b-footer">Fußzeile</label>
                <textarea id="b-footer" name="mail_footer" rows="3" class="form-control" maxlength="500" placeholder="<?php echo $e(Branding::DEFAULT_MAIL_FOOTER); ?>"><?php echo $e($stored['brand_mail_footer'] ?? ''); ?></textarea>
                <small class="text-secondary">Unter jeder Mail an Merchants (Nachrichten, Wochenbericht, Hinweise). Z. B. Firma, Adresse, Impressum-Link. Adressen und Links werden klickbar.</small>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" name="action" value="save" class="btn btn-primary" <?php echo $migrationReady ? '' : 'disabled'; ?>>Speichern</button>
            <button type="submit" name="action" value="reset" class="btn btn-outline-danger ms-auto" formnovalidate onclick="return confirm('Alles auf Standard zurücksetzen, auch Logo und Favicon?')" <?php echo $migrationReady ? '' : 'disabled'; ?>>Auf Standard zurücksetzen</button>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3" style="position: sticky; top: 1rem;">
            <div class="card-header"><h3 class="card-title">Vorschau</h3></div>
            <div class="card-body" id="b-preview">
                <div class="small text-secondary mb-1">App</div>
                <div class="border rounded p-3 mb-3">
                    <div class="fw-bold mb-3 d-flex align-items-center"><?php echo $brand->logoHtml(28); ?></div>
                    <p class="mb-2">Lagerbestand wird alle 30 Minuten abgeglichen. <a href="#" onclick="return false">Details ansehen</a></p>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="btn btn-primary btn-sm">Speichern</span>
                        <span class="btn btn-outline-primary btn-sm">Assistent starten</span>
                        <span class="badge bg-primary-lt align-self-center">aktiv</span>
                    </div>
                </div>
                <div class="small text-secondary mb-1">Mail (nach dem Speichern aktualisiert)</div>
                <iframe title="Mail-Vorschau" sandbox="" style="width:100%;height:340px;border:1px solid var(--tblr-border-color);border-radius:8px" srcdoc="<?php echo $e($brand->mailHtml(
                    '<p style="margin:0 0 12px">Hallo Musterfirma,</p><p style="margin:0 0 16px">so sehen Mails von ' . $e($brand->shortName) . ' an deine Merchants aus.</p>'
                    . $brand->mailButton('#', $brand->shortName . ' öffnen')
                )); ?>"></iframe>
            </div>
        </div>
    </div>
</div>
</form>

<script>
    (function () {
        var text = document.getElementById('b-primary');
        var picker = document.getElementById('b-primary-picker');
        var font = document.getElementById('b-font');
        var preview = document.getElementById('b-preview');
        var info = document.getElementById('b-contrast');

        function hex(v) {
            v = (v || '').trim().toLowerCase().replace(/^#/, '');
            if (/^[0-9a-f]{3}$/.test(v)) { v = v[0] + v[0] + v[1] + v[1] + v[2] + v[2]; }
            return /^[0-9a-f]{6}$/.test(v) ? '#' + v : null;
        }
        function lum(h) {
            return [1, 3, 5].reduce(function (sum, i, k) {
                var c = parseInt(h.substr(i, 2), 16) / 255;
                c = c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
                return sum + [0.2126, 0.7152, 0.0722][k] * c;
            }, 0);
        }
        function apply() {
            var c = hex(text.value);
            if (!c) { info.innerHTML = '<span class="text-danger">Kein gültiger Hex-Wert.</span>'; return; }
            picker.value = c;
            var onWhite = (1.05) / (lum(c) + 0.05);
            preview.style.setProperty('--tblr-primary', c);
            preview.style.setProperty('--tblr-primary-rgb', [1, 3, 5].map(function (i) { return parseInt(c.substr(i, 2), 16); }).join(','));
            preview.style.setProperty('--tblr-primary-darken', c);
            preview.style.setProperty('--tblr-primary-fg', onWhite >= 3 ? '#ffffff' : '#1d2733');
            preview.style.setProperty('--tblr-link-color', c);
            preview.style.setProperty('--tblr-link-color-rgb', [1, 3, 5].map(function (i) { return parseInt(c.substr(i, 2), 16); }).join(','));
            info.innerHTML = onWhite >= 4.5
                ? '<span class="text-success">Kontrast zu Weiß ' + onWhite.toFixed(1) + ':1 – gut lesbar.</span>'
                : (onWhite >= 3
                    ? '<span class="text-warning">Kontrast zu Weiß ' + onWhite.toFixed(1) + ':1 – für Buttons ok, Links auf weißem Grund etwas schwach.</span>'
                    : '<span class="text-danger">Kontrast zu Weiß nur ' + onWhite.toFixed(1) + ':1 – zu hell für Links und Text, bitte dunkler wählen.</span>');
        }
        window.brandSetColor = function (c) { text.value = c; apply(); };
        picker.addEventListener('input', function () { text.value = picker.value; apply(); });
        text.addEventListener('input', apply);
        font.addEventListener('change', function () {
            preview.style.fontFamily = font.options[font.selectedIndex].dataset.stack || '';
        });
        apply();
    })();
</script>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
