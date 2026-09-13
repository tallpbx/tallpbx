<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Validates tenant switches against the authenticated tenant user's memberships.
 */
class SwitchTenantRequest extends FormRequest
{
    /**
     * Determine whether the web-guard user may activate the requested tenant.
     */
    public function authorize(): bool
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->tenants()
            ->whereKey($this->integer('tenant_id'))
            ->where('tenants.enabled', true)
            ->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'integer'],
        ];
    }

    /**
     * Return the enabled tenant already authorized for this user.
     */
    public function authorizedTenant(): Tenant
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user->tenants()
            ->whereKey($this->integer('tenant_id'))
            ->where('tenants.enabled', true)
            ->firstOrFail();
    }
}
