<div class="max-w-2xl">
    <x-page-header :title="__('admin.email_connector_title')" :tooltip="__('admin.email_connector_tooltip')" :subtitle="__('admin.email_connector_description')" />

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">
            {{ $operationalMessage }}
        </x-inline-alert>
    @endif

    <form wire:submit="save" class="card bg-base-100 border border-base-300 p-6 space-y-4">
        {{-- Status badge --}}
        <div class="flex items-center gap-2">
            <span class="text-sm font-medium">Status:</span>
            @if ($isConfigured)
                <x-tooltip :tip="__('admin.smtp_configured_tooltip')" position="right">
                    <span class="badge badge-success">{{ __('admin.smtp_configured') }}</span>
                </x-tooltip>
            @else
                <x-tooltip :tip="__('admin.smtp_not_configured_tooltip')" position="right">
                    <span class="badge badge-ghost">{{ __('admin.smtp_not_configured') }}</span>
                </x-tooltip>
            @endif
        </div>

        {{-- Preset buttons --}}
        <div class="flex items-center gap-2">
            <button type="button" wire:click="applyGmailPreset"
                class="btn btn-sm {{ $preset === 'gmail' ? 'btn-primary' : 'btn-outline' }}">
                {{ __('admin.smtp_gmail_preset') }}
            </button>
            <button type="button" wire:click="applyGenericPreset"
                class="btn btn-sm {{ $preset === 'generic' ? 'btn-primary' : 'btn-outline' }}">
                {{ __('admin.smtp_generic_preset') }}
            </button>
        </div>
        <p class="text-xs text-base-content/40 mt-1">
            @if ($preset === 'gmail')
                smtp.gmail.com:587 TLS — use a Gmail App Password, not your regular password.
            @elseif ($preset === 'generic')
                587 / TLS is the industry standard for most SMTP providers.
            @else
                Select a preset to pre-fill common SMTP settings, or configure manually below.
            @endif
        </p>

        <div class="divider"></div>

        {{-- Host --}}
        <div class="form-control w-full">
            <label class="label justify-start gap-2 pb-1" for="smtp_host">
                <span class="label-text font-medium">{{ __('admin.smtp_host') }}</span>
                <x-tooltip :tip="__('admin.smtp_host_tooltip')" position="right">
                    <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                </x-tooltip>
            </label>
            <input type="text" id="smtp_host" wire:model="smtp_host"
                   class="input input-bordered w-full"
                   placeholder="{{ $preset === 'gmail' ? 'smtp.gmail.com' : 'smtp.example.com' }}" />
            <x-form-error name="smtp_host" />
        </div>

        {{-- Port + Encryption --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="smtp_port">
                    <span class="label-text font-medium">{{ __('admin.smtp_port') }}</span>
                    <x-tooltip :tip="__('admin.smtp_port_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <input type="number" id="smtp_port" wire:model="smtp_port"
                       class="input input-bordered w-full" placeholder="587" />
                <x-form-error name="smtp_port" />
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="smtp_encryption">
                    <span class="label-text font-medium">{{ __('admin.smtp_encryption') }}</span>
                    <x-tooltip :tip="__('admin.smtp_encryption_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <select id="smtp_encryption" wire:model="smtp_encryption" class="select select-bordered w-full">
                    <option value="tls">TLS</option>
                    <option value="ssl">SSL</option>
                    <option value="">{{ __('admin.smtp_none') }}</option>
                </select>
            </div>
        </div>

        {{-- Username --}}
        <div class="form-control w-full">
            <label class="label justify-start gap-2 pb-1" for="smtp_username">
                <span class="label-text font-medium">{{ __('admin.smtp_username') }}</span>
            </label>
            <input type="text" id="smtp_username" wire:model="smtp_username"
                   class="input input-bordered w-full"
                   placeholder="{{ $preset === 'gmail' ? 'you@gmail.com' : 'user@example.com' }}" />
            <x-form-error name="smtp_username" />
        </div>

        {{-- Authentication type — Alpine handles the instant UI toggle; Livewire stays in sync for validation --}}
        <div x-data="{ authType: $wire.smtp_auth_type }" class="space-y-4">
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">{{ __('admin.smtp_auth_type') }}</span>
                    <x-tooltip :tip="__('admin.smtp_auth_type_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 cursor-pointer"
                           @click="authType = 'password'; $wire.smtp_auth_type = 'password'">
                        <input type="radio" wire:model="smtp_auth_type" value="password"
                               class="radio radio-sm" />
                        <span class="text-sm">{{ __('admin.smtp_auth_type_password') }}</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer"
                           @click="authType = 'oauth'; $wire.smtp_auth_type = 'oauth'">
                        <input type="radio" wire:model="smtp_auth_type" value="oauth"
                               class="radio radio-sm" />
                        <span class="text-sm">{{ __('admin.smtp_auth_type_oauth') }}</span>
                    </label>
                </div>
            </div>

            {{-- Password field — x-show for instant toggle, no server roundtrip --}}
            <div x-show="authType === 'password'" x-cloak>
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="smtp_password">
                        <span class="label-text font-medium">{{ __('admin.smtp_password') }}</span>
                        <span class="label-text-alt text-base-content/60">{{ __('admin.smtp_password_encrypted') }}</span>
                        <x-tooltip :tip="__('admin.smtp_password_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input type="password" id="smtp_password" wire:model="smtp_password"
                           class="input input-bordered w-full" placeholder="••••••••" />
                    <x-form-error name="smtp_password" />
                </div>
            </div>

            @include('email-connector::partials.oauth-section')
        </div>
        {{-- End Alpine auth type scope --}}

        {{-- From address + name --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="smtp_from_address">
                    <span class="label-text font-medium">{{ __('admin.smtp_from_address') }}</span>
                    <x-tooltip :tip="__('admin.smtp_from_address_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <input type="email" id="smtp_from_address" wire:model="smtp_from_address"
                       class="input input-bordered w-full"
                       placeholder="{{ $preset === 'gmail' ? 'no-reply@gmail.com' : 'noreply@example.com' }}" />
                <x-form-error name="smtp_from_address" />
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="smtp_from_name">
                    <span class="label-text font-medium">{{ __('admin.smtp_from_name') }}</span>
                    <x-tooltip :tip="__('admin.smtp_from_name_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <input type="text" id="smtp_from_name" wire:model="smtp_from_name"
                       class="input input-bordered w-full" placeholder="TallPBX" />
                <x-form-error name="smtp_from_name" />
            </div>
        </div>

        {{-- Save button --}}
        <div class="pt-2">
            <x-form-submit-button icon="heroicon-o-check">
                    {{ __('client.save') }}
                </x-form-submit-button>
        </div>
    </form>

    @include('email-connector::partials.test-email')

    @if ($confirmingOAuthDisconnect)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_oauth_disconnect_title')"
            :message="__('admin.modal_oauth_disconnect_message')"
            :confirm-label="__('admin.modal_oauth_disconnect_confirm')"
            confirm-action="disconnectOAuth()"
            cancel-action="cancelOAuthDisconnect"
            :error="$oauthDisconnectError"
            :error-title="__('admin.modal_oauth_disconnect_error_title')"
        />
    @endif
</div>
