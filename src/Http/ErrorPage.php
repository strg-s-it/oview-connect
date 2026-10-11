<?php

declare(strict_types=1);

namespace Connect\Http;

/**
 * Gestaltete Fehlerseite fuer alle Faelle, in denen ein MENSCH vor dem Browser
 * sitzt (abgelehnte Anfrage, fehlende Berechtigung, unbekannter Datensatz).
 * Nicht gedacht fuer maschinelle Empfaenger wie die Shopify-Webhooks unter
 * public/webhooks/ (dort bleibt bewusst eine knappe Klartext-Antwort) und nicht
 * fuer CLI-Skripte.
 *
 * Bewusst eigenstaendig: keine Abhaengigkeit von _header.php/_footer.php, weil
 * genau die Faelle, in denen diese Seite erscheint, VOR dem normalen
 * Seitenaufbau abbrechen (z.B. in bootstrap.php, noch vor jeder Shop-Pruefung).
 * Nutzt nur das bereits ausgelieferte Tabler-CSS (Stylesheet-Pfad absolut, weil
 * der Dokumentenstamm von connect.oview.at auf public/ zeigt und die Seite je
 * nach Aufrufer unter app/ oder admin/ liegt).
 */
final class ErrorPage
{
    /**
     * Gibt die Fehlerseite aus und beendet die Ausfuehrung.
     *
     * @param int                                  $status  HTTP-Statuscode (z.B. 401, 403, 404)
     * @param string                               $title   Kurze Ueberschrift
     * @param string                               $message Erklaerung, was passiert ist und was zu tun ist
     * @param array{label: string, url: string}|null $action Optionaler Button (z.B. "Seite neu laden")
     */
    public static function show(int $status, string $title, string $message, ?array $action = null): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('Referrer-Policy: no-referrer');
            header('X-Content-Type-Options: nosniff');
        }

        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $button = '';
        if ($action !== null) {
            $button = '<div class="empty-action"><a href="' . $e($action['url']) . '" class="btn btn-primary">'
                . $e($action['label']) . '</a></div>';
        }

        echo '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . $e($title) . ' · oView Connect</title>
    <link rel="stylesheet" href="/assets/vendor/tabler/css/tabler.min.css">
</head>
<body class="d-flex flex-column">
    <div class="page page-center">
        <div class="container container-tight py-4">
            <div class="text-center mb-4"><h1>oView Connect</h1></div>
            <div class="card card-md">
                <div class="card-body">
                    <div class="empty">
                        <div class="empty-header">' . $status . '</div>
                        <p class="empty-title">' . $e($title) . '</p>
                        <p class="empty-subtitle text-secondary">' . $e($message) . '</p>
                        ' . $button . '
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>';

        exit;
    }

    /**
     * Relative URL der aktuellen Seite (Pfad + Query) fuer einen "Neu laden"-
     * Button. Immer genau ein fuehrender "/", damit ein manipulierter
     * Request-Pfad wie "//fremde-domain" nie zu einem externen Ziel wird.
     * Ein Link ist immer ein GET -- das Neuladen nach einem abgelehnten POST
     * schickt also nichts erneut ab, sondern holt die Seite mit frischem Token.
     */
    public static function selfUrl(): string
    {
        return '/' . ltrim((string) ($_SERVER['REQUEST_URI'] ?? ''), "/\\");
    }
}
