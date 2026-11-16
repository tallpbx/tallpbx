<?php

declare(strict_types=1);

namespace Modules\Extensions\Livewire;

use App\Support\BaseEditComponent;
use App\Support\PanelActor;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Modules\Extensions\Models\Extension;
use Modules\Extensions\Services\ExtensionSaveService;
use Modules\Extensions\Services\ExtensionServiceInterface;
use Modules\Voicemails\Models\Voicemail;

/**
 * Livewire component for creating and editing extensions.
 */
class ExtensionsEdit extends BaseEditComponent
{
    public string $extensionNumber = '';

    public string $numberAlias = '';

    public string $displayName = '';

    public string $password = '';

    public bool $voicemailEnabled = false;

    public string $voicemailPassword = '';

    public string $accountcode = '';

    public string $effectiveCallerIdName = '';

    public string $effectiveCallerIdNumber = '';

    public string $outboundCallerIdName = '';

    public string $outboundCallerIdNumber = '';

    public string $emergencyCallerIdName = '';

    public string $emergencyCallerIdNumber = '';

    public string $directoryFirstName = '';

    public string $directoryLastName = '';

    public bool $directoryVisible = true;

    public bool $directoryExtenVisible = true;

    public ?int $maxRegistrations = null;

    public ?int $limitMax = null;

    public string $limitDestination = '';

    public string $missedCallApp = '';

    public string $missedCallData = '';

    public string $userContext = '';

    public string $tollAllow = '';

    public ?int $callTimeout = null;

    public string $callGroup = '';

    public bool $callScreenEnabled = false;

    public string $userRecord = '';

    public string $holdMusic = '';

    public string $authAcl = '';

    public string $cidr = '';

    public string $sipForceContact = '';

    public ?int $sipForceExpires = null;

    public string $nibbleAccount = '';

    public string $mwiAccount = '';

    public string $sipBypassMedia = '';

    public string $absoluteCodecString = '';

    public bool $forcePing = false;

    public string $dialString = '';

    public string $extensionLanguage = '';

    public string $extensionDialect = '';

    public string $extensionVoice = '';

    public string $extensionType = 'default';

    public string $description = '';

    #[Locked]
    public ?string $extensionId = null;

    /** @var array<int, int> */
    public array $selectedUserIds = [];

    private ExtensionServiceInterface $extensionService;

    /**
     * Boot the component with the extension service.
     */
    public function boot(ExtensionServiceInterface $extensionService): void
    {
        $this->extensionService = $extensionService;
    }

    /**
     * Mount the component in create or edit mode.
     */
    public function mount(?string $extensionId = null): void
    {
        $this->loadTenants();

        if ($extensionId !== null) {
            $this->extensionId = $extensionId;
            $extension = Extension::withoutGlobalScope('tenant')->with('users')->findOrFail($extensionId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($extension);
            $this->tenantId = $extension->tenant_id;
            $this->extensionNumber = $extension->extension_number;
            $this->numberAlias = $extension->number_alias ?? '';
            $this->displayName = $extension->display_name ?? '';
            $this->voicemailEnabled = $extension->voicemail_enabled;
            $voicemail = Voicemail::withoutGlobalScope('tenant')
                ->where('tenant_id', $extension->tenant_id)
                ->where('voicemail_id', $extension->extension_number)
                ->first();
            $this->voicemailPassword = $voicemail->password ?? '';
            $this->accountcode = $extension->accountcode ?? '';
            $this->effectiveCallerIdName = $extension->effective_caller_id_name ?? '';
            $this->effectiveCallerIdNumber = $extension->effective_caller_id_number ?? '';
            $this->outboundCallerIdName = $extension->outbound_caller_id_name ?? '';
            $this->outboundCallerIdNumber = $extension->outbound_caller_id_number ?? '';
            $this->emergencyCallerIdName = $extension->emergency_caller_id_name ?? '';
            $this->emergencyCallerIdNumber = $extension->emergency_caller_id_number ?? '';
            $this->directoryFirstName = $extension->directory_first_name ?? '';
            $this->directoryLastName = $extension->directory_last_name ?? '';
            $this->directoryVisible = $extension->directory_visible;
            $this->directoryExtenVisible = $extension->directory_exten_visible;
            $this->maxRegistrations = $extension->max_registrations;
            $this->limitMax = $extension->limit_max;
            $this->limitDestination = $extension->limit_destination ?? '';
            $this->missedCallApp = $extension->missed_call_app ?? '';
            $this->missedCallData = $extension->missed_call_data ?? '';
            $this->userContext = $extension->user_context ?? '';
            $this->tollAllow = $extension->toll_allow ?? '';
            $this->callTimeout = $extension->call_timeout;
            $this->callGroup = $extension->call_group ?? '';
            $this->callScreenEnabled = $extension->call_screen_enabled;
            $this->userRecord = $extension->user_record ?? '';
            $this->holdMusic = $extension->hold_music ?? '';
            $this->authAcl = $extension->auth_acl ?? '';
            $this->cidr = $extension->cidr ?? '';
            $this->sipForceContact = $extension->sip_force_contact ?? '';
            $this->sipForceExpires = $extension->sip_force_expires;
            $this->nibbleAccount = $extension->nibble_account ?? '';
            $this->mwiAccount = $extension->mwi_account ?? '';
            $this->sipBypassMedia = $extension->sip_bypass_media ?? '';
            $this->absoluteCodecString = $extension->absolute_codec_string ?? '';
            $this->forcePing = $extension->force_ping;
            $this->dialString = $extension->dial_string ?? '';
            $this->extensionLanguage = $extension->extension_language ?? '';
            $this->extensionDialect = $extension->extension_dialect ?? '';
            $this->extensionVoice = $extension->extension_voice ?? '';
            $this->extensionType = $extension->extension_type ?? 'default';
            $this->description = $extension->description ?? '';
            $this->enabled = $extension->enabled;
            $this->selectedUserIds = $extension->users->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }
    }

    /**
     * Return whether we're in edit mode.
     */
    public function getIsEditProperty(): bool
    {
        return $this->extensionId !== null;
    }

    /**
     * Validate the form, create or update the extension, and sync its
     * SIP account and voicemail settings.
     */
    public function save(): void
    {
        if ($this->isAdminGuard()) {

            abort_unless(PanelActor::can($this->extensionId === null ? 'extensions.create' : 'extensions.edit'), 403);

        }

        // The write boundary ignores the public property entirely: a tenant
        // user's tenant comes from the authorized context or not at all. An
        // admin's missing selection is a validation error, not a 403.
        if (! $this->isAdminGuard()) {
            abort_if($this->resolveTenantId() === null, 403);
        }

        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->resolveTenantId(),
            'extension_number' => $this->extensionNumber,
            'number_alias' => $this->nullableString($this->numberAlias),
            'display_name' => $this->displayName ?: null,
            'voicemail_enabled' => $this->voicemailEnabled,
            'accountcode' => $this->nullableString($this->accountcode),
            'effective_caller_id_name' => $this->nullableString($this->effectiveCallerIdName),
            'effective_caller_id_number' => $this->nullableString($this->effectiveCallerIdNumber),
            'outbound_caller_id_name' => $this->nullableString($this->outboundCallerIdName),
            'outbound_caller_id_number' => $this->nullableString($this->outboundCallerIdNumber),
            'emergency_caller_id_name' => $this->nullableString($this->emergencyCallerIdName),
            'emergency_caller_id_number' => $this->nullableString($this->emergencyCallerIdNumber),
            'directory_first_name' => $this->nullableString($this->directoryFirstName),
            'directory_last_name' => $this->nullableString($this->directoryLastName),
            'directory_visible' => $this->directoryVisible,
            'directory_exten_visible' => $this->directoryExtenVisible,
            'max_registrations' => $this->maxRegistrations,
            'limit_max' => $this->limitMax,
            'limit_destination' => $this->nullableString($this->limitDestination),
            'missed_call_app' => $this->nullableString($this->missedCallApp),
            'missed_call_data' => $this->nullableString($this->missedCallData),
            'user_context' => $this->nullableString($this->userContext),
            'toll_allow' => $this->nullableString($this->tollAllow),
            'call_timeout' => $this->callTimeout,
            'call_group' => $this->nullableString($this->callGroup),
            'call_screen_enabled' => $this->callScreenEnabled,
            'user_record' => $this->nullableString($this->userRecord),
            'hold_music' => $this->nullableString($this->holdMusic),
            'auth_acl' => $this->nullableString($this->authAcl),
            'cidr' => $this->nullableString($this->cidr),
            'sip_force_contact' => $this->nullableString($this->sipForceContact),
            'sip_force_expires' => $this->sipForceExpires,
            'nibble_account' => $this->nullableString($this->nibbleAccount),
            'mwi_account' => $this->nullableString($this->mwiAccount),
            'sip_bypass_media' => $this->nullableString($this->sipBypassMedia),
            'absolute_codec_string' => $this->nullableString($this->absoluteCodecString),
            'force_ping' => $this->forcePing,
            'dial_string' => $this->nullableString($this->dialString),
            'extension_language' => $this->nullableString($this->extensionLanguage),
            'extension_dialect' => $this->nullableString($this->extensionDialect),
            'extension_voice' => $this->nullableString($this->extensionVoice),
            'extension_type' => $this->extensionType,
            'description' => $this->nullableString($this->description),
            'enabled' => $this->enabled,
        ];

        $existing = null;

        if ($this->extensionId !== null) {
            $existing = Extension::withoutGlobalScope('tenant')->findOrFail($this->extensionId);
            $this->assertCanAccessTenantRecord($existing);
        }

        // One transaction owner writes the extension, its users, its SIP
        // account, and its voicemail; a failure in any step leaves no orphan.
        app(ExtensionSaveService::class)->save(
            data: $data,
            userIds: $this->selectedUserIds,
            password: $this->password,
            voicemailEnabled: $this->voicemailEnabled,
            voicemailPassword: $this->voicemailPassword,
            existing: $existing,
        );

        $this->redirect(route('panel.extensions.index'));
    }

    /**
     * Validation rules for the extension form, keeping the extension
     * number unique within the tenant.
     */
    protected function rules(): array
    {
        $tenantId = $this->resolveTenantId();

        $uniqueRule = Rule::unique('extensions', 'extension_number')
            ->where('tenant_id', $tenantId);

        if ($this->extensionId !== null) {
            $uniqueRule->ignore($this->extensionId);
        }

        return [
            'tenantId' => $this->isAdminGuard() ? ['required', 'exists:tenants,id'] : ['nullable'],
            'extensionNumber' => ['required', 'string', 'max:50', $uniqueRule],
            'numberAlias' => ['nullable', 'string', 'max:50'],
            'displayName' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:4'],
            'voicemailPassword' => ['nullable', 'string', 'max:50', 'regex:/^[0-9]*$/'],
            'accountcode' => ['nullable', 'string', 'max:255'],
            'effectiveCallerIdName' => ['nullable', 'string', 'max:255'],
            'effectiveCallerIdNumber' => ['nullable', 'string', 'max:255'],
            'outboundCallerIdName' => ['nullable', 'string', 'max:255'],
            'outboundCallerIdNumber' => ['nullable', 'string', 'max:255'],
            'emergencyCallerIdName' => ['nullable', 'string', 'max:255'],
            'emergencyCallerIdNumber' => ['nullable', 'string', 'max:255'],
            'directoryFirstName' => ['nullable', 'string', 'max:255'],
            'directoryLastName' => ['nullable', 'string', 'max:255'],
            'maxRegistrations' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'limitMax' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'limitDestination' => ['nullable', 'string', 'max:255'],
            'missedCallApp' => ['nullable', 'string', 'max:255'],
            'missedCallData' => ['nullable', 'string', 'max:255'],
            'userContext' => ['nullable', 'string', 'max:255'],
            'tollAllow' => ['nullable', 'string', 'max:255'],
            'callTimeout' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'callGroup' => ['nullable', 'string', 'max:255'],
            'userRecord' => ['nullable', 'string', 'in:all,local,inbound,outbound'],
            'holdMusic' => ['nullable', 'string', 'max:255'],
            'authAcl' => ['nullable', 'string', 'max:255'],
            'cidr' => ['nullable', 'string', 'max:255'],
            'sipForceContact' => ['nullable', 'string', 'max:255'],
            'sipForceExpires' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'nibbleAccount' => ['nullable', 'string', 'max:255'],
            'mwiAccount' => ['nullable', 'string', 'max:255'],
            'sipBypassMedia' => ['nullable', 'string', 'max:255'],
            'absoluteCodecString' => ['nullable', 'string', 'max:1000'],
            'dialString' => ['nullable', 'string', 'max:2000'],
            'extensionLanguage' => ['nullable', 'string', 'max:20'],
            'extensionDialect' => ['nullable', 'string', 'max:20'],
            'extensionVoice' => ['nullable', 'string', 'max:50'],
            'extensionType' => ['required', 'string', 'in:default,virtual'],
            'description' => ['nullable', 'string', 'max:2000'],
            'selectedUserIds' => ['array'],
            'selectedUserIds.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    /**
     * Custom validation messages.
     */
    protected function messages(): array
    {
        return [
            'voicemailPassword.regex' => 'The voicemail password must only contain numbers.',
        ];
    }

    /**
     * Convert blank form strings to null for optional database fields.
     */
    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
