<?php

declare(strict_types=1);

namespace Tests\Browser\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Provides fast authentication helpers for Pest 4 browser tests.
 *
 * Rather than repeatedly filling out and submitting the web panel's login form,
 * this trait uses the internal test authentication bridge to establish an authenticated
 * session directly in sub-10ms.
 */
trait InteractsWithAuthentication
{
    /**
     * Authenticate an Admin or User directly into the browser session via the test bridge.
     *
     * @param Model $user An Admin or User Eloquent model instance
     * @param string $guard The authentication guard to log into ('admin' or 'web')
     */
    public function loginAs(Model $user, string $guard = 'admin'): void
    {
        visit("/_testing/login/{$guard}/{$user->getKey()}")
            ->assertPathBeginsWith('/panel');
    }
}
