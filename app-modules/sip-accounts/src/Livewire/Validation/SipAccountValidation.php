<?php

declare(strict_types=1);

namespace Modules\SipAccounts\Livewire\Validation;

use Illuminate\Validation\Rule;

/**
 * Validation rules for SIP account forms.
 *
 * Keeps complex conditional validation logic out of the Livewire
 * component, making rules testable and reusable.
 */
class SipAccountValidation
{
    /**
     * Get validation rules for the SIP account form.
     *
     * @param  bool  $isCreate  Whether this is a create (vs edit) operation
     * @param  string  $identityMode  The selected identity mode
     */
    public static function rules(bool $isCreate, string $identityMode = 'global_username'): array
    {
        $rules = [
            'tenantId' => ['required', 'exists:tenants,id'],
            'tenantDomainId' => ['nullable', 'exists:tenant_domains,id'],
            'identityMode' => ['required', Rule::in(['global_username', 'domain_username', 'hybrid'])],
            'authUsername' => ['required', 'string', 'max:255'],
            'authPassword' => $isCreate
                ? ['required', 'string', 'min:4']
                : ['nullable', 'string', 'min:4'],
        ];

        if ($identityMode !== 'global_username') {
            $rules['tenantDomainId'] = ['required', 'exists:tenant_domains,id'];
        }

        return $rules;
    }
}
