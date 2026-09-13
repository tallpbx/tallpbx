<?php

declare(strict_types=1);

namespace Modules\Voicemails\Livewire\Validation;

use Illuminate\Validation\Rule;

/**
 * Validation rules for voicemail mailbox forms.
 *
 * Encapsulates unique-scoped rule generation with tenant awareness.
 */
class VoicemailValidation
{
    /**
     * Get validation rules for the voicemail form.
     *
     * @param  string|null  $voicemailUuid  The UUID of the voicemail being edited (null for create)
     * @param  mixed|null  $tenantId  The selected tenant ID for unique scope
     */
    public static function rules(?string $voicemailUuid = null, mixed $tenantId = null): array
    {
        $uniqueRule = Rule::unique('voicemails', 'voicemail_id')
            ->where('tenant_id', $tenantId);

        if ($voicemailUuid !== null) {
            $uniqueRule->ignore($voicemailUuid);
        }

        return [
            'tenantId' => ['required', 'exists:tenants,id'],
            'voicemailId' => ['required', 'string', 'max:255', $uniqueRule],
            'mailbox' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'requirePassword' => ['boolean'],
            'forwardToEmail' => ['boolean'],
            'deleteAfterEmail' => ['boolean'],
            'enabled' => ['boolean'],
        ];
    }
}
