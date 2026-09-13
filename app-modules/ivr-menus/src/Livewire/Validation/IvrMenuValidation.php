<?php

declare(strict_types=1);

namespace Modules\IvrMenus\Livewire\Validation;

/**
 * Validation rules for IVR menu forms.
 *
 * Encapsulates complex unique-rule logic with tenant scope.
 */
class IvrMenuValidation
{
    /**
     * Get validation rules for the IVR menu form.
     *
     * @param  string|null  $menuUuid  The UUID of the menu being edited (null for create)
     * @param  mixed|null  $tenantId  The selected tenant ID
     */
    public static function rules(?string $menuUuid = null, mixed $tenantId = null): array
    {
        $uniqueRule = 'unique:ivr_menus,name,NULL,id,tenant_id,'.($tenantId ?? 'NULL');

        if ($menuUuid !== null) {
            $uniqueRule = 'unique:ivr_menus,name,'.$menuUuid.',id,tenant_id,'.($tenantId ?? 'NULL');
        }

        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255', $uniqueRule],
            'description' => ['nullable', 'string', 'max:65535'],
            'timeout' => ['required', 'integer', 'min:1', 'max:300'],
            'maxFailures' => ['required', 'integer', 'min:1', 'max:100'],
            'digitLength' => ['required', 'integer', 'min:0', 'max:20'],
        ];
    }
}
