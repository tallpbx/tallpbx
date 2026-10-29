<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.conference')" />

        <form wire:submit="save" class="space-y-6">
            {{-- Tenant --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="tenantId">
                    <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                    <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full">
                    <option value="">{{ __('admin.select_tenant') }}</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                <x-form-error name="tenantId" />
            </div>

            {{-- Name --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="name">
                    <span class="label-text font-medium">{{ __('admin.conference_name') }}</span>
                </label>
                <input
                    wire:model="name"
                    type="text"
                    id="name"
                    class="input input-bordered w-full"
                    placeholder="e.g., Weekly Standup, Project Huddle"
                />
                <x-form-error name="name" />
            </div>

            {{-- Profile --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="profile">
                    <span class="label-text font-medium">{{ __('admin.conference_profile') }}</span>
                </label>
                <select wire:model="profile" id="profile" class="select select-bordered w-full">
                    <option value="sample">sample</option>
                    <option value="cdquality">cdquality</option>
                    <option value="wideband">wideband</option>
                    <option value="ultrawideband">ultrawideband</option>
                </select>
                <x-form-error name="profile" />
            </div>

            {{-- PIN --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="pin">
                    <span class="label-text font-medium">{{ __('admin.conference_pin') }}</span>
                </label>
                <input
                    wire:model="pin"
                    type="text"
                    id="pin"
                    class="input input-bordered w-full"
                    placeholder="Optional PIN for caller authentication"
                />
                <x-form-error name="pin" />
            </div>

            {{-- Max Members --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="maxMembers">
                    <span class="label-text font-medium">{{ __('admin.conference_max_members') }}</span>
                </label>
                <input
                    wire:model="maxMembers"
                    type="number"
                    id="maxMembers"
                    class="input input-bordered w-full"
                    min="1"
                    max="1000"
                />
                <x-form-error name="maxMembers" />
            </div>

            {{-- Enabled --}}
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" wire:loading.attr="disabled" type="checkbox" class="toggle toggle-sm toggle-success" />
                    <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                </label>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-3">
                <x-form-submit-button>
                    {{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.conference') }}
                </x-form-submit-button>
                <a href="{{ route('panel.conferences.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
