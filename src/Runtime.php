<?php

declare(strict_types=1);

namespace Connect;

/**
 * Wer bin ich? Name dieses Prozesses fuer Statistik und Sperren:
 *   - CLI (Scheduler): CONNECT_WORKER_NAME aus connect/.env, sonst der Rechnername
 *   - Web (Webhooks, Admin-/Merchant-Seiten): "web"
 * Gesetzt in Config::load(), gelesen z.B. von Http\ApiUsageRecorder.
 */
final class Runtime
{
    private static string $configuredWorker = '';

    public static function configure(string $workerName): void
    {
        self::$configuredWorker = trim($workerName);
    }

    public static function workerName(): string
    {
        if (PHP_SAPI !== 'cli') {
            return 'web';
        }

        return self::$configuredWorker !== '' ? self::$configuredWorker : (string) gethostname();
    }
}
