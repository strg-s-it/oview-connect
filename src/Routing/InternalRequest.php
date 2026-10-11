<?php

declare(strict_types=1);

namespace Connect\Routing;

/**
 * Pruefung signierter Server-zu-Server-Aufrufe der alten Welt an Connect
 * (public/internal/*.php). Signatur: Header X-Oview-Signature =
 * base64(hmac_sha256(rawBody, App-Secret)) -- dasselbe Secret, mit dem beide
 * Welten schon Shopify-Webhooks pruefen. Der Body muss einen Zeitstempel "ts"
 * (Unix-Sekunden) enthalten, der hoechstens MAX_AGE_SECONDS alt ist, damit ein
 * mitgeschnittener Aufruf nicht spaeter wiederholt werden kann.
 */
final class InternalRequest
{
    private const MAX_AGE_SECONDS = 300;

    /**
     * @return array<string, mixed> der gepruefte, dekodierte Body
     */
    public static function verifyOrDie(string $secret): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::fail(405, 'POST erwartet.');
        }

        $rawBody = file_get_contents('php://input') ?: '';
        $signature = (string) ($_SERVER['HTTP_X_OVIEW_SIGNATURE'] ?? '');
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        if ($signature === '' || !hash_equals($expected, $signature)) {
            self::fail(401, 'Signatur ungültig.');
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            self::fail(400, 'Ungültiger Body.');
        }

        $ts = (int) ($data['ts'] ?? 0);
        if (abs(time() - $ts) > self::MAX_AGE_SECONDS) {
            self::fail(401, 'Zeitstempel abgelaufen.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function respond(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function fail(int $status, string $message): never
    {
        self::respond($status, ['ok' => false, 'error' => $message]);
    }
}
