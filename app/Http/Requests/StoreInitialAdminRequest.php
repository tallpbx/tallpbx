<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the one-time browser form that creates TallPBX's first administrator.
 */
class StoreInitialAdminRequest extends FormRequest
{
    /**
     * Allow the form only while no administrator account exists.
     */
    public function authorize(): bool
    {
        return ! Admin::query()->exists();
    }

    /**
     * Return the fields accepted from the browser setup form.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:admins,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'activation_code' => ['nullable', 'string', 'max:128'],
        ];
    }
}
