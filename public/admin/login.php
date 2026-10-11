<?php

declare(strict_types=1);

/**
 * connect/public/admin/login.php
 *
 * Login-Formular fuer den Admin-Bereich. Nutzt bewusst NICHT
 * admin_bootstrap.php (das wuerde selbst ein Login verlangen -> Zirkel),
 * sondern baut Config/DB/AdminAuth direkt auf.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;

$config = Config::load();
$db = Database::connect($config);
$auth = new AdminAuth($db->pdo());

// Security-Header setzt AdminAuth bereits im Konstruktor (siehe oben).
$error = null;

// Nur interne Ziele nach dem Login (Open-Redirect-Schutz): entweder ein
// relativer Dateiname ("shops.php?...") oder ein absoluter Pfad mit genau einem
// fuehrenden "/" -- kein "//host", kein Schema, kein Backslash, keine
// Steuerzeichen (Browser entfernen z.B. Tabs aus URLs, "/<TAB>/host" wuerde
// sonst zu "//host").
$redirect = (string) ($_GET['redirect'] ?? $_POST['redirect'] ?? '');
if (!preg_match('#^(?:/(?!/)|[A-Za-z0-9_\-]+\.php)[^\\\\\x00-\x1f\x7f]*$#', $redirect)) {
    $redirect = 'shops.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // Gesperrt? Gilt fuer bekannte und unbekannte Adressen gleich (siehe AdminAuth).
    $blockedSeconds = $auth->loginBlockedSeconds($email);

    if ($blockedSeconds > 0) {
        http_response_code(429);
        $error = 'Zu viele Fehlversuche. Bitte in ' . max(1, (int) ceil($blockedSeconds / 60)) . ' Minute(n) erneut versuchen.';
    } elseif ($auth->attemptLogin($email, $password)) {
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = 'E-Mail oder Passwort falsch.';
    }
}
$brand = \Connect\Setup\Branding::load($db->pdo(), $config);
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login · <?php echo htmlspecialchars($brand->name); ?></title>
    <link rel="stylesheet" href="../assets/vendor/tabler/css/tabler.min.css">
    <?php echo $brand->headHtml(); ?>
</head>

<body class="d-flex flex-column">
    <div class="page page-center">
        <div class="container container-tight py-4">
            <div class="text-center mb-4">
                <h1 class="d-flex align-items-center justify-content-center"><?php echo $brand->logoHtml(40); ?></h1>
                <div class="text-secondary">Admin-Bereich</div>
            </div>
            <div class="card card-md">
                <div class="card-body">
                    <?php if ($error !== null): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
                        <div class="mb-3">
                            <label class="form-label">E-Mail</label>
                            <input type="email" name="email" class="form-control" autofocus required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Passwort</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary w-100">Anmelden</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>

</html>