<?php

declare(strict_types=1);

namespace App\Listeners\Octane;

use Illuminate\Support\Facades\Log;
use Throwable;

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
                $containers[] = $event->sandbox;
            }
            if (isset($event->app)) {
                $containers[] = $event->app;
            }
        }

        $containers[] = app();

        foreach (array_unique($containers, SORT_REGULAR) as $container) {
            if (! $container->resolved('db')) {
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
                }
            }
        }
    }
}
