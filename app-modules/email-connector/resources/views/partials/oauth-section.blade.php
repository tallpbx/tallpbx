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
            <x-form-error name="smtp_oauth_provider" />
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
            <x-form-error name="smtp_oauth_client_id" />
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
            <x-form-error name="smtp_oauth_client_secret" />
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
                <x-form-error name="smtp_oauth_auth_endpoint" />
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
                <x-form-error name="smtp_oauth_token_endpoint" />
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
                <x-form-error name="smtp_oauth_scopes" />
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
