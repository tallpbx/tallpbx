<div>
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-semibold">
            {{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.tenant_domains_title') }}
        </h2>
    </div>

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <form wire:submit="save" class="card-body">
            {{-- Tenant --}}
            <div class="form-control w-full">
                <label for="tenantId" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">Tenant</span>
                </label>
                <select id="tenantId" wire:model="tenantId"
                        class="select select-bordered w-full" required>
                    <option value="">Select a tenant</option>
                    @foreach($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                @error('tenantId')
                    <span class="label-text-alt text-error">{{ $message }}</span>
                @enderror
            </div>

            {{-- Domain --}}
            <div class="form-control w-full mt-4">
                <label for="domain" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">Domain</span>
                </label>
                <input type="text" id="domain" wire:model="domain"
                       class="input input-bordered w-full" placeholder="sip.example.com" required />
                @error('domain')
                    <span class="label-text-alt text-error">{{ $message }}</span>
                @enderror
            </div>

            {{-- Purpose --}}
            <div class="form-control w-full mt-4">
                <label for="purpose" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">Purpose</span>
                </label>
                <select id="purpose" wire:model="purpose"
                        class="select select-bordered w-full">
                    <option value="sip_realm">SIP Realm</option>
                    <option value="provisioning">Provisioning</option>
                    <option value="web">Web</option>
                    <option value="alias">Alias</option>
                </select>
                @error('purpose')
                    <span class="label-text-alt text-error">{{ $message }}</span>
                @enderror
            </div>

            {{-- Enabled --}}
            <div class="form-control w-full mt-4">
                <label class="label cursor-pointer justify-start gap-4">
                    <input type="checkbox" wire:model="enabled"
                           class="toggle toggle-primary" />
                    <span class="label-text font-medium">Enabled</span>
                </label>
            </div>

            {{-- Actions --}}
            <div class="flex gap-3 mt-6">
                <button type="submit" class="btn btn-primary">
                    <x-heroicon-o-check class="w-4 h-4" />
                    {{ $this->isEdit ? 'Update Domain' : 'Create Domain' }}
                </button>
                <a href="{{ route('panel.tenant-domains.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
