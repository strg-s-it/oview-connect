<?php

declare(strict_types=1);

namespace Connect\Auth;

use PDO;

/**
 * Session-basiertes Login fuer den Betreiber-Bereich (connect.oview.at/admin).
 * Bewusst komplett unabhaengig vom Shopify-Kontext und von der alten Welt
 * (admin_accounts) -- eigene Tabelle connect_admin_users, eigenes Passwort-
 * Hashing ueber PHPs eingebaute password_hash()/password_verify().
 */
final class AdminAuth
{
    private const SESSION_KEY = 'connect_admin_email';

    /** Fehlversuche je Konto (E-Mail), nach denen der Login fuer LOCK_MINUTES gesperrt ist. */
    private const MAX_FAILURES_PER_ACCOUNT = 5;

    /** Fehlversuche je IP-Adresse -- grosszuegiger, weil sich hinter einer IP mehrere Personen verbergen koennen. */
    private const MAX_FAILURES_PER_IP = 20;

    /** Sperrdauer in Minuten, gerechnet ab dem letzten Fehlversuch. */
    private const LOCK_MINUTES = 15;

    /**
     * Gueltiger bcrypt-Hash (irgendeines bekannten Textes) NUR fuer die Zeitangleichung:
     * bei einem unbekannten Konto wird gegen ihn geprueft, damit die Antwortzeit nicht
     * verraet, ob die E-Mail-Adresse existiert. Er gibt nie Zugang -- der Login verlangt
     * zusaetzlich, dass das Konto existiert.
     */
    private const TIMING_DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(private readonly PDO $pdo)
    {
        self::ensureSessionStarted();
        self::sendSecurityHeaders();
    }

    public function attemptLogin(string $email, string $password): bool
    {
        $stmt = $this->pdo->prepare('SELECT id, password_hash FROM connect_admin_users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        // Immer genau eine Passwortpruefung rechnen (bei unbekanntem Konto gegen einen
        // Dummy-Hash): gleiche Antwortzeit fuer bekannte und unbekannte Adressen.
        $passwordOk = password_verify($password, $user !== false ? (string) $user['password_hash'] : self::TIMING_DUMMY_HASH);

        if ($user === false || !$passwordOk) {
            $this->recordLoginAttempt($email, false);
            usleep(random_int(150_000, 400_000)); // kleine Bremse gegen automatisiertes Durchprobieren

            return false;
        }

        session_regenerate_id(true); // neue Session-ID nach erfolgreichem Login (Session-Fixation)
        $_SESSION[self::SESSION_KEY] = $email;

        $update = $this->pdo->prepare('UPDATE connect_admin_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
        $update->execute(['id' => $user['id']]);

        $this->recordLoginAttempt($email, true);

        return true;
    }

    /**
     * Wie viele Sekunden der Login fuer diese E-Mail-Adresse (bzw. die anfragende IP)
     * noch gesperrt ist -- 0, wenn er frei ist. Gilt fuer bekannte UND unbekannte
     * Adressen gleichermassen, damit eine Sperre nichts ueber die Existenz eines Kontos
     * verraet. Die Sperre laeuft LOCK_MINUTES ab dem letzten Fehlversuch; waehrend sie
     * aktiv ist, zaehlen weitere Versuche nicht (login.php ruft attemptLogin() dann gar
     * nicht auf), sie verlaengert sich also nicht endlos.
     *
     * Ist die Tabelle noch nicht da (Migration 0052 nicht eingespielt), gilt der Login
     * als frei -- er bleibt benutzbar, ist aber bis dahin ungeschuetzt.
     */
    public function loginBlockedSeconds(string $email): int
    {
        try {
            $window = self::LOCK_MINUTES * 60;
            $blocked = 0;

            $account = $this->failureStats('email_hash', self::emailHash($email));
            if ($account['failures'] >= self::MAX_FAILURES_PER_ACCOUNT) {
                $blocked = max($blocked, $window - $account['since_last']);
            }

            $ip = self::clientIp();
            if ($ip !== '') {
                $byIp = $this->failureStats('ip', $ip);
                if ($byIp['failures'] >= self::MAX_FAILURES_PER_IP) {
                    $blocked = max($blocked, $window - $byIp['since_last']);
                }
            }

            return max(0, $blocked);
        } catch (\Throwable $e) {
            error_log('connect: Login-Sperre nicht pruefbar: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Fehlversuche im Zeitfenster und Sekunden seit dem letzten.
     *
     * @param 'email_hash'|'ip' $column ausschliesslich die zwei festen Werte der Aufrufe oben, nie eine Eingabe
     * @return array{failures: int, since_last: int}
     */
    private function failureStats(string $column, string $value): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS failures, TIMESTAMPDIFF(SECOND, MAX(attempted_at), NOW()) AS since_last
             FROM connect_admin_login_attempts
             WHERE ' . $column . ' = :value AND success = 0
               AND attempted_at > NOW() - INTERVAL ' . self::LOCK_MINUTES . ' MINUTE'
        );
        $stmt->execute(['value' => $value]);
        $row = $stmt->fetch();

        return [
            'failures' => (int) ($row['failures'] ?? 0),
            'since_last' => (int) ($row['since_last'] ?? 0),
        ];
    }

    /**
     * Protokolliert einen Versuch. Bei Erfolg werden die Fehlversuche dieses Kontos
     * zurueckgesetzt. Scheitert das Protokollieren (z.B. fehlt die Tabelle), bleibt der
     * Login selbst unberuehrt.
     */
    private function recordLoginAttempt(string $email, bool $success): void
    {
        try {
            $hash = self::emailHash($email);

            if ($success) {
                $clear = $this->pdo->prepare('DELETE FROM connect_admin_login_attempts WHERE email_hash = :hash AND success = 0');
                $clear->execute(['hash' => $hash]);
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO connect_admin_login_attempts (email_hash, ip, success) VALUES (:hash, :ip, :success)'
            );
            $insert->execute(['hash' => $hash, 'ip' => self::clientIp(), 'success' => $success ? 1 : 0]);
        } catch (\Throwable $e) {
            error_log('connect: Login-Versuch nicht protokolliert: ' . $e->getMessage());
        }
    }

    private static function emailHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /**
     * Die IP des Besuchers -- oder leer, wenn keine verlaessliche vorliegt. Aus REMOTE_ADDR,
     * nie aus X-Forwarded-For (das kann jeder selbst setzen). Loopback-, private und die
     * eigene Server-Adresse gelten als "kommt vom Proxy": sonst haetten alle Besucher
     * dieselbe IP und einer koennte mit Fehlversuchen alle aussperren. Dann greift nur
     * die Grenze je Konto.
     */
    private static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return '';
        }

        if ($ip === (string) ($_SERVER['SERVER_ADDR'] ?? '')) {
            return '';
        }

        return $ip;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public function currentEmail(): ?string
    {
        $email = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($email) ? $email : null;
    }

    public function isLoggedIn(): bool
    {
        return $this->currentEmail() !== null;
    }

    /**
     * Erzwingt ein Login -- leitet bei fehlender Session nach login.php um
     * und beendet die Ausfuehrung. Nimmt den aktuellen Request-Pfad relativ
     * zu admin/ mit, damit login.php nach erfolgreichem Login dorthin
     * zurueckleiten kann.
     */
    public function requireLogin(): string
    {
        $email = $this->currentEmail();
        if ($email !== null) {
            self::enforceCsrf();

            return $email;
        }

        $redirectTo = urlencode($_SERVER['REQUEST_URI'] ?? 'shops.php');
        header('Location: login.php?redirect=' . $redirectTo);
        exit;
    }

    /**
     * Header fuer JEDE Admin-Seite (der Konstruktor laeuft auf allen, auch auf
     * Seiten, die admin_bootstrap.php nicht nutzen und AdminAuth selbst
     * aufbauen). Der Admin-Bereich wird nie eingebettet (auch ?preview=1
     * rendert die Merchant-Ansicht innerhalb der Seite, nicht als iframe) und
     * braucht keine Referrer.
     */
    private static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: frame-ancestors 'none'");
    }

    /**
     * CSRF-Schutz fuer alle eingeloggten Admin-Seiten -- zentral hier statt in
     * admin_bootstrap.php, weil ein Teil der Seiten AdminAuth selbst aufbaut
     * und requireLogin() direkt aufruft; durch diese Methode laufen beide Wege.
     * Das Token steckt in der Session "connect_admin" und wird per Script aus
     * public/app/_footer.php in jedes POST-Formular eingetragen. Laeuft nach dem
     * Login-Check -- ohne Login gibt es nichts zu schuetzen.
     */
    private static function enforceCsrf(): void
    {
        $_SESSION['connect_csrf'] ??= bin2hex(random_bytes(32));

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($sent) || $sent === '' || !hash_equals((string) $_SESSION['connect_csrf'], $sent)) {
            \Connect\Http\ErrorPage::show(
                403,
                'Anfrage abgelehnt',
                'Aus Sicherheitsgründen wurde die Anfrage nicht ausgeführt (Sicherheitstoken fehlt oder ist abgelaufen). '
                . 'Bitte lade die Seite neu und versuche es noch einmal.',
                ['label' => 'Seite neu laden', 'url' => \Connect\Http\ErrorPage::selfUrl()]
            );
        }
    }

    private static function ensureSessionStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Eigener Session-Name, getrennt von der Merchant-Session
            // (bootstrap.php nutzt "connect_app"). Der Admin-Bereich laeuft nie
            // im Shopify-iframe -- deshalb kein SameSite=None wie dort, sondern Lax.
            session_name('connect_admin');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            session_set_cookie_params([
                'samesite' => 'Lax',
                'secure' => $https,
                'httponly' => true,
                'path' => '/',
            ]);
            session_start();
        }
    }
}
