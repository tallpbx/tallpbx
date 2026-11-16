<?php

declare(strict_types=1);

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Invalidates issued remember-me cookies when an account is disabled.
 *
 * Laravel's SessionGuard restores a session from the recaller cookie without
 * consulting account state, so a cookie issued while the account was enabled
 * would otherwise keep working after an administrator disables it. Rotating
 * the token makes every previously issued recaller unusable.
 */
final class AccountEnablementObserver
{
    /**
     * Rotate the remember token when the account transitions to disabled.
     */
    public function updated(Model $model): void
    {
        if (! $model->wasChanged('enabled') || $model->enabled) {
            return;
        }

        // `saveQuietly()` prevents recursion into this observer; `forceFill()`
        // writes the token even though it is not mass assignable.
        $model->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }
}
