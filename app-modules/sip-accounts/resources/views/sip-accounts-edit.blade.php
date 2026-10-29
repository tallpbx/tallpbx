<div>
    <x-page-header :title="$this->isEdit ? 'Edit SIP Account' : 'Create SIP Account'" />

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                {{-- Tenant --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="tenantId">
                        <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <select id="tenantId" wire:model="tenantId" class="select select-bordered w-full {{ $errors->has('tenantId') ? 'select-error' : '' }}">
                        <option value="">{{ __('admin.select_tenant') }}</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    <x-form-error name="tenantId" />
                </div>

                {{-- Identity Mode --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="identityMode">
                        <span class="label-text font-medium">{{ __('admin.sip_account_identity_mode') }}</span>
                    </label>
                    <select id="identityMode" wire:model="identityMode" class="select select-bordered w-full {{ $errors->has('identityMode') ? 'select-error' : '' }}">
                        <option value="global_username">Global Username</option>
                        <option value="domain_username">Domain Username</option>
                        <option value="hybrid">Hybrid</option>
                    </select>
                    <x-form-error name="identityMode" />
                </div>

                {{-- Domain (for domain/hybrid modes) --}}
                @if($identityMode !== 'global_username')
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="tenantDomainId">
                        <span class="label-text font-medium">{{ __('admin.domain') }}</span>
                    </label>
                    <select id="tenantDomainId" wire:model="tenantDomainId" class="select select-bordered w-full {{ $errors->has('tenantDomainId') ? 'select-error' : '' }}">
                        <option value="">{{ __('admin.sip_account_select_domain') }}</option>
                        @foreach($domains as $domain)
                            <option value="{{ $domain->id }}">{{ $domain->domain }}</option>
                        @endforeach
                    </select>
                    <x-form-error name="tenantDomainId" />
                </div>
                @endif

                {{-- Auth Username --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="authUsername">
                        <span class="label-text font-medium">{{ __('admin.sip_account_auth_username') }}</span>
                    </label>
                    <input type="text" id="authUsername" wire:model="authUsername"
                           class="input input-bordered w-full {{ $errors->has('authUsername') ? 'input-error' : '' }}" />
                    <x-form-error name="authUsername" />
                </div>

                {{-- Auth Password --}}
                <div class="form-control w-full" x-data="{ show: false }">
                    <label class="label justify-start gap-2 pb-1" for="authPassword">
                        <span class="label-text font-medium">{{ __('admin.sip_account_auth_password') }}</span>
                    </label>
                    <div class="relative w-full">
                        <input :type="show ? 'text' : 'password'" id="authPassword" wire:model="authPassword"
                               autocomplete="new-password"
                               data-lpignore="true"
                               data-1p-ignore="true"
                               data-bwignore="true"
                               data-form-type="other"
                               class="input input-bordered w-full pr-10 {{ $errors->has('authPassword') ? 'input-error' : '' }}" />
                        <button type="button" @click="show = !show" class="btn btn-ghost btn-xs btn-circle absolute right-2 top-1/2 -translate-y-1/2 text-base-content/60 hover:text-base-content" aria-label="{{ __('admin.password_visibility') }}" :aria-pressed="show" tabindex="-1">
                            <x-heroicon-o-eye x-show="!show" class="w-4 h-4" />
                            <x-heroicon-o-eye-slash x-show="show" class="w-4 h-4" x-cloak />
                        </button>
                    </div>
                    <x-form-error name="authPassword" />
                </div>

                {{-- Enabled --}}
                <div class="form-control w-full">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" wire:model="enabled" wire:loading.attr="disabled" class="toggle toggle-sm toggle-success" />
                        <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                        <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                </div>

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <x-form-submit-button>
                    {{ $this->isEdit ? 'Update' : 'Create' }}
                </x-form-submit-button>
                    <a href="{{ route('panel.sip-accounts.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
