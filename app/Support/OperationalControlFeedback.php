<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Shared feedback and authorization state for the operational control modules.
 *
 * Every control action checks the permission server-side (the Gate is
 * authoritative; button visibility is cosmetic) and maps the control
 * service result to the component's message state.
 */
trait OperationalControlFeedback
{
    public ?string $actionMessage = null;

    public ?string $actionError = null;

    /**
     * Server-side permission gate for a control action.
     */
    private function authorizeAction(string $permission): bool
    {
        $user = Auth::guard('admin')->user() ?? Auth::guard('web')->user();

        return $user !== null && $user->hasPermission($permission);
    }

    /**
     * Map a control result to the component feedback state.
     *
     * @param  array{success: bool, message: string}  $result
     */
    private function applyResult(array $result): void
    {
        if ($result['success']) {
            $this->actionMessage = $result['message'];
        } else {
            $this->actionError = $result['message'];
        }
    }
}
