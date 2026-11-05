<?php

declare(strict_types=1);

namespace App\Listeners\Octane;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verify no database work survives an Octane request.
 *
 * Runs after every request termination and rolls back or disconnects any
 * transaction the request left behind, so one request's uncommitted writes
 * can never surface in the next request the same worker serves.
 */
class VerifyCleanDatabaseState
{
    /**
     * Handle the Octane event to ensure all active database transactions are rolled back.
     */
    public function handle(mixed $event = null): void
    {
        $containers = [];

        if (is_object($event)) {
            if (isset($event->sandbox)) {
                $containers[spl_object_id($event->sandbox)] = $event->sandbox;
            }
            if (isset($event->app)) {
                $containers[spl_object_id($event->app)] = $event->app;
            }
        }

        $globalApp = app();
        $containers[spl_object_id($globalApp)] = $globalApp;

        foreach ($containers as $container) {
            if (! method_exists($container, 'resolved') || ! $container->resolved('db')) {
                continue;
            }

            $db = $container->make('db');

            foreach ($db->getConnections() as $name => $connection) {
                if ($connection->transactionLevel() > 0) {
                    $levelBefore = $connection->transactionLevel();
                    Log::warning("Octane: Rolling back dangling database transaction on connection [{$name}] at level [{$levelBefore}].");

                    while ($connection->transactionLevel() > 0) {
                        $level = $connection->transactionLevel();

                        try {
                            $connection->rollBack();
                        } catch (Throwable $e) {
                            Log::error("Octane: Exception while rolling back dangling transaction on connection [{$name}]: {$e->getMessage()}", [
                                'exception' => $e,
                            ]);
                            break;
                        }

                        if ($connection->transactionLevel() >= $level) {
                            break;
                        }
                    }

                    if ($connection->transactionLevel() > 0) {
                        Log::warning("Octane: Forcing disconnect on tainted connection [{$name}] due to remaining transaction level [{$connection->transactionLevel()}].");

                        try {
                            $connection->disconnect();
                        } catch (Throwable $e) {
                            Log::error("Octane: Exception while disconnecting tainted connection [{$name}]: {$e->getMessage()}", [
                                'exception' => $e,
                            ]);
                        }
                    }
                }
            }
        }
    }
}
