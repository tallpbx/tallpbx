<div>
    <div class="flex items-center gap-2 mb-6">
        <h2 class="text-2xl font-semibold">
            {{ $this->isEdit ? 'Edit SIP Profile' : 'Create SIP Profile' }}
        </h2>
        <x-tooltip :tip="__('admin.sip_profile_header_tooltip')" align="start" position="right">
            <x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" />
        </x-tooltip>
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

                {{-- Name --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="name">
                        <span class="label-text font-medium">Profile Name</span>
                    </label>
                    <input type="text" id="name" wire:model="name"
                           class="input input-bordered w-full @error('name') input-error @enderror"
                           placeholder="internal" />
                    @error('name')
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    @enderror
                </div>

                {{-- Description --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="description">
                        <span class="label-text font-medium">Description</span>
                    </label>
                    <textarea id="description" wire:model="description" rows="2"
                              class="textarea textarea-bordered w-full @error('description') textarea-error @enderror"
                              placeholder="Optional description"></textarea>
                    @error('description')
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    @enderror
                </div>

                {{-- SIP IP & Port Grid --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="sipIp">
                            <span class="label-text font-medium">SIP IP</span>
                        </label>
                        <input type="text" id="sipIp" wire:model="sipIp"
                               class="input input-bordered w-full @error('sipIp') input-error @enderror"
                               placeholder="$${local_ip_v4}" />
                        @error('sipIp')
                            <span class="label-text-alt text-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="sipPort">
                            <span class="label-text font-medium">SIP Port</span>
                        </label>
                        <input type="number" id="sipPort" wire:model="sipPort"
                               class="input input-bordered w-full @error('sipPort') input-error @enderror" />
                        @error('sipPort')
                            <span class="label-text-alt text-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                {{-- Enabled --}}
                <div class="form-control w-full">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" wire:model="enabled"
                               class="checkbox checkbox-primary" />
                        <span class="label-text font-medium">Enabled</span>
                        <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                </div>

                {{-- Actions --}}
                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <button type="submit" class="btn btn-primary">
                        {{ $this->isEdit ? 'Update' : 'Create' }}
                    </button>
                    <a href="{{ route('panel.sip-profiles.index') }}" class="btn btn-ghost">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
