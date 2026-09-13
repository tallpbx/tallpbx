<div>
    <div class="mb-6">
        <h2 class="text-2xl font-semibold">
            {{ $this->isEdit ? 'Edit SIP Account' : 'Create SIP Account' }}
        </h2>
    </div>

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                {{-- Tenant --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="tenantId">
                        <span class="label-text font-medium">Tenant</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <select id="tenantId" wire:model="tenantId" class="select select-bordered w-full @error('tenantId') select-error @enderror">
                        <option value="">Select tenant</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    @error('tenantId') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Identity Mode --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="identityMode">
                        <span class="label-text font-medium">Identity Mode</span>
                    </label>
                    <select id="identityMode" wire:model="identityMode" class="select select-bordered w-full @error('identityMode') select-error @enderror">
                        <option value="global_username">Global Username</option>
                        <option value="domain_username">Domain Username</option>
                        <option value="hybrid">Hybrid</option>
                    </select>
                    @error('identityMode') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Domain (for domain/hybrid modes) --}}
                @if($identityMode !== 'global_username')
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="tenantDomainId">
                        <span class="label-text font-medium">Domain</span>
                    </label>
                    <select id="tenantDomainId" wire:model="tenantDomainId" class="select select-bordered w-full @error('tenantDomainId') select-error @enderror">
                        <option value="">Select domain</option>
                        @foreach($domains as $domain)
                            <option value="{{ $domain->id }}">{{ $domain->domain }}</option>
                        @endforeach
                    </select>
                    @error('tenantDomainId') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>
                @endif

                {{-- Auth Username --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="authUsername">
                        <span class="label-text font-medium">Auth Username</span>
                    </label>
                    <input type="text" id="authUsername" wire:model="authUsername"
                           class="input input-bordered w-full @error('authUsername') input-error @enderror" />
                    @error('authUsername') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Auth Password --}}
                <div class="form-control w-full" x-data="{ show: false }">
                    <label class="label justify-start gap-2 pb-1" for="authPassword">
                        <span class="label-text font-medium">Auth Password</span>
                    </label>
                    <div class="relative w-full">
                        <input :type="show ? 'text' : 'password'" id="authPassword" wire:model="authPassword"
                               autocomplete="new-password"
                               data-lpignore="true"
                               data-1p-ignore="true"
                               data-bwignore="true"
                               data-form-type="other"
                               class="input input-bordered w-full pr-10 @error('authPassword') input-error @enderror" />
                        <button type="button" @click="show = !show" class="btn btn-ghost btn-xs btn-circle absolute right-2 top-1/2 -translate-y-1/2 text-base-content/60 hover:text-base-content" tabindex="-1">
                            <x-heroicon-o-eye x-show="!show" class="w-4 h-4" />
                            <x-heroicon-o-eye-slash x-show="show" class="w-4 h-4" x-cloak />
                        </button>
                    </div>
                    @error('authPassword') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Enabled --}}
                <div class="form-control w-full">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" wire:model="enabled" class="checkbox checkbox-primary" />
                        <span class="label-text font-medium">Enabled</span>
                        <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                </div>

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <button type="submit" class="btn btn-primary">
                        {{ $this->isEdit ? 'Update' : 'Create' }}
                    </button>
                    <a href="{{ route('panel.sip-accounts.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
