<div class="max-w-2xl">
    <div class="mb-6">
        <div class="flex items-center gap-2">
            <h2 class="text-2xl font-semibold">{{ __('admin.email_connector_title') }}</h2>
            <x-tooltip :tip="__('admin.email_connector_tooltip')" align="start" position="right">
                <x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" />
            </x-tooltip>
        </div>
        <p class="text-sm text-base-content/60 mt-1">{{ __('admin.email_connector_description') }}</p>
    </div>

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
            @error('smtp_host') <span class="text-error text-sm">{{ $message }}</span> @enderror
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
                @error('smtp_port') <span class="text-error text-sm">{{ $message }}</span> @enderror
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
            @error('smtp_username') <span class="text-error text-sm">{{ $message }}</span> @enderror
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
                    @error('smtp_password') <span class="text-error text-sm">{{ $message }}</span> @enderror
                </div>
            </div>

            {{-- OAuth 2.0 section — x-show for instant toggle; internal conditionals use Blade for server-side state --}}
            <div x-show="authType === 'oauth'" x-cloak>
                <div class="space-y-4 border border-base-300 rounded-lg p-4 bg-base-200/50">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium">{{ __('admin.smtp_oauth') }}:</span>
                        @if ($oauthAuthorized)
                            <span class="badge badge-success">{{ __('admin.smtp_oauth_authorized') }}</span>
                        @else
                            <span class="badge badge-ghost">{{ __('admin.smtp_oauth_not_authorized') }}</span>
                        @endif
                    </div>

                    {{-- Provider --}}
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="smtp_oauth_provider">
                            <span class="label-text font-medium">{{ __('admin.smtp_oauth_provider') }}</span>
                            <x-tooltip :tip="__('admin.smtp_oauth_provider_tooltip')" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </label>
                        <select id="smtp_oauth_provider" wire:model.live="smtp_oauth_provider"
                                class="select select-bordered w-full">
                            <option value="google">{{ __('admin.smtp_oauth_provider_google') }}</option>
                            <option value="microsoft">{{ __('admin.smtp_oauth_provider_microsoft') }}</option>
                            <option value="custom">{{ __('admin.smtp_oauth_provider_custom') }}</option>
                        </select>
                        @error('smtp_oauth_provider') <span class="text-error text-sm">{{ $message }}</span> @enderror
                    </div>

                    {{-- Client ID --}}
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="smtp_oauth_client_id">
                            <span class="label-text font-medium">{{ __('admin.smtp_oauth_client_id') }}</span>
                            <x-tooltip :tip="__('admin.smtp_oauth_client_id_tooltip')" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </label>
                        <input type="text" id="smtp_oauth_client_id" wire:model="smtp_oauth_client_id"
                               class="input input-bordered w-full"
                               placeholder="your-client-id.apps.googleusercontent.com" />
                        @error('smtp_oauth_client_id') <span class="text-error text-sm">{{ $message }}</span> @enderror
                    </div>

                    {{-- Client Secret --}}
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="smtp_oauth_client_secret">
                            <span class="label-text font-medium">{{ __('admin.smtp_oauth_client_secret') }}</span>
                            <span class="label-text-alt text-base-content/60">{{ __('admin.smtp_password_encrypted') }}</span>
                            <x-tooltip :tip="__('admin.smtp_oauth_client_secret_tooltip')" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </label>
                        <input type="password" id="smtp_oauth_client_secret" wire:model="smtp_oauth_client_secret"
                               class="input input-bordered w-full" placeholder="••••••••" />
                        @error('smtp_oauth_client_secret') <span class="text-error text-sm">{{ $message }}</span> @enderror
                    </div>

                    {{-- Custom provider fields --}}
                    @if ($smtp_oauth_provider === 'custom')
                        <div class="form-control w-full">
                            <label class="label justify-start gap-2 pb-1" for="smtp_oauth_auth_endpoint">
                                <span class="label-text font-medium">{{ __('admin.smtp_oauth_auth_endpoint') }}</span>
                                <x-tooltip :tip="__('admin.smtp_oauth_auth_endpoint_tooltip')" position="right">
                                    <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                                </x-tooltip>
                            </label>
                            <input type="url" id="smtp_oauth_auth_endpoint" wire:model="smtp_oauth_auth_endpoint"
                                   class="input input-bordered w-full"
                                   placeholder="https://provider.example.com/oauth2/auth" />
                            @error('smtp_oauth_auth_endpoint') <span class="text-error text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-control w-full">
                            <label class="label justify-start gap-2 pb-1" for="smtp_oauth_token_endpoint">
                                <span class="label-text font-medium">{{ __('admin.smtp_oauth_token_endpoint') }}</span>
                                <x-tooltip :tip="__('admin.smtp_oauth_token_endpoint_tooltip')" position="right">
                                    <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                                </x-tooltip>
                            </label>
                            <input type="url" id="smtp_oauth_token_endpoint" wire:model="smtp_oauth_token_endpoint"
                                   class="input input-bordered w-full"
                                   placeholder="https://provider.example.com/oauth2/token" />
                            @error('smtp_oauth_token_endpoint') <span class="text-error text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-control w-full">
                            <label class="label justify-start gap-2 pb-1" for="smtp_oauth_scopes">
                                <span class="label-text font-medium">{{ __('admin.smtp_oauth_scopes') }}</span>
                                <x-tooltip :tip="__('admin.smtp_oauth_scopes_tooltip')" position="right">
                                    <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                                </x-tooltip>
                            </label>
                            <input type="text" id="smtp_oauth_scopes" wire:model="smtp_oauth_scopes"
                                   class="input input-bordered w-full"
                                   placeholder="smtp.send offline_access" />
                            @error('smtp_oauth_scopes') <span class="text-error text-sm">{{ $message }}</span> @enderror
                        </div>
                    @endif

                    {{-- Instructions (provider-specific, shows before authorization) --}}
                    @if (! $oauthAuthorized)
                        @php
                            $oauthCallbackUrl = url('/panel/email-connector/oauth-callback');
                        @endphp
                        <p class="text-xs text-base-content/50">
                            @if ($smtp_oauth_provider === 'google')
                                {{ __('admin.smtp_oauth_instructions_google', ['url' => $oauthCallbackUrl]) }}
                            @elseif ($smtp_oauth_provider === 'microsoft')
                                {{ __('admin.smtp_oauth_instructions_microsoft', ['url' => $oauthCallbackUrl]) }}
                            @else
                                {{ __('admin.smtp_oauth_instructions_custom', ['url' => $oauthCallbackUrl]) }}
                            @endif
                        </p>
                    @endif

                    {{-- Authorize / Disconnect buttons --}}
                    <div class="flex items-center gap-2">
                        @if ($oauthAuthorized)
                            <button type="button" wire:click="startOAuth" class="btn btn-sm btn-outline">
                                {{ __('admin.smtp_oauth_reauth') }}
                            </button>
                            <button type="button" wire:click="confirmOAuthDisconnect"
                                    class="btn btn-sm btn-ghost text-error">
                                {{ __('admin.smtp_oauth_disconnect') }}
                            </button>
                        @else
                            <button type="button" wire:click="startOAuth" class="btn btn-sm btn-primary">
                                {{ __('admin.smtp_oauth_authorize') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
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
                @error('smtp_from_address') <span class="text-error text-sm">{{ $message }}</span> @enderror
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
                @error('smtp_from_name') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
        </div>

        {{-- Save button --}}
        <div class="pt-2">
            <button type="submit" class="btn btn-primary">
                <x-heroicon-o-check class="w-4 h-4" />
                {{ __('client.save') }}
            </button>
        </div>
    </form>

    {{-- Test email section --}}
    <div class="card bg-base-100 border border-base-300 p-6 mt-6 space-y-4">
        <div class="flex items-center gap-2">
            <h3 class="text-lg font-semibold">{{ __('admin.smtp_test_email') }}</h3>
            <x-tooltip :tip="__('admin.smtp_test_email_tooltip')" position="left">
                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-40 hover:opacity-80" />
            </x-tooltip>
        </div>

        @if ($testResult !== null)
            <x-inline-alert :type="$testSuccess ? 'success' : 'error'" :title="null">
                {{ $testResult }}
            </x-inline-alert>
        @endif

        <div class="flex items-end gap-4">
            <div class="form-control flex-1">
                <label class="label" for="testEmailAddress">
                    <span class="label-text">{{ __('admin.smtp_test_recipient') }}</span>
                </label>
                <input type="email" id="testEmailAddress" wire:model="testEmailAddress"
                       class="input input-bordered w-full" placeholder="admin@example.com" />
                @error('testEmailAddress') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
            <button type="button" wire:click="sendTest" class="btn btn-outline"
                    :disabled="!$wire.isConfigured">
                <x-heroicon-o-paper-airplane class="w-4 h-4" />
                {{ __('admin.smtp_send_test') }}
            </button>
        </div>
    </div>

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
