<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use PDO;

/**
 * Preis-Aufgaben im Scheduler (bin/scheduler.php): faellige Preissynchronisationen einreihen und
 * offene Sammelaenderungen abarbeiten. Genau ein Scheduler gleichzeitig (GET_LOCK), je Aufruf ein
 * Zeitbudget -- grosse Aenderungen laufen ueber mehrere Minuten.
 */
final class PriceTasks
{
    /** @return array{synced_shops: int, batches: int, written: int, failed: int}|null null = gesperrt oder nichts zu tun */
    public static function run(PDO $pdo, ConnectorFactory $factory, int $timeBudgetSeconds = 15): ?array
    {
        if ((int) $pdo->query("SELECT GET_LOCK('connect_prices', 0)")->fetchColumn() !== 1) {
            return null;
        }
        try {
            $queued = (new PriceSyncRunner($pdo, $factory))->queueDue();
            try {
                $result = (new BatchEditService($pdo, $factory))->runPending($timeBudgetSeconds);
            } catch (\PDOException) {
                $result = null; // Migration 0088 fehlt
            }
            if ($queued === 0 && $result === null) {
                return null;
            }

            return ['synced_shops' => $queued] + ($result ?? ['batches' => 0, 'written' => 0, 'failed' => 0]);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('connect_prices')");
        }
    }
}
