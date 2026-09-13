<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Dashboard\DashboardStatsUpdated;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer that broadcasts a DashboardStatsUpdated event whenever key PBX models change.
 *
 * This ensures counts on the dashboard (such as total users, tenants, etc.) remain
 * reactive and update in real-time across connected browser clients without polling.
 */
class DashboardStatsObserver
{
    /**
     * Handle the Model "created" event.
     */
    public function created(Model $model): void
    {
        $this->broadcastUpdate($model);
    }

    /**
     * Handle the Model "deleted" event.
     */
    public function deleted(Model $model): void
    {
        $this->broadcastUpdate($model);
    }

    /**
     * Handle the Model "restored" event.
     */
    public function restored(Model $model): void
    {
        $this->broadcastUpdate($model);
    }

    /**
     * Broadcast the stats update.
     */
    protected function broadcastUpdate(Model $model): void
    {
        // Avoid broadcasting during tests unless specifically testing broadcasts
        if (app()->runningUnitTests() && ! config('broadcasting.test_broadcasts', false)) {
            return;
        }

        try {
            DashboardStatsUpdated::dispatch(class_basename($model));
        } catch (\Throwable $e) {
            // Silently ignore broadcast transport errors so model persistence is unaffected
        }
    }
}
