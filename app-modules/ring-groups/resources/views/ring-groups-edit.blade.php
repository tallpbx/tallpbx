<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.ring_group')" />

        <form wire:submit="save" class="space-y-6">
            {{-- Tenant --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="tenantId">
                    <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
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
                    <span class="label-text font-medium">{{ __('admin.name') }}</span>
                </label>
                <input
                    wire:model="name"
                    type="text"
                    id="name"
                    class="input input-bordered w-full"
                    placeholder="e.g., Sales Team, Support Group"
                />
                <x-form-error name="name" />
            </div>

            {{-- Strategy --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="strategy">
                    <span class="label-text font-medium">{{ __('admin.ring_group_strategy') }}</span>
                    <x-tooltip :tip="__('admin.ring_group_strategy_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <select wire:model="strategy" id="strategy" class="select select-bordered w-full">
                    <option value="ring-all">Ring All</option>
                    <option value="sequential">Sequential</option>
                    <option value="round-robin">Round Robin</option>
                </select>
                <x-form-error name="strategy" />
            </div>

            {{-- Ring Timeout --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="ringTimeout">
                    <span class="label-text font-medium">{{ __('admin.ring_group_timeout_seconds') }}</span>
                    <x-tooltip :tip="__('admin.ring_group_timeout_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <input
                    wire:model="ringTimeout"
                    type="number"
                    id="ringTimeout"
                    class="input input-bordered w-full"
                    min="1"
                    max="300"
                />
                <x-form-error name="ringTimeout" />
            </div>

            {{-- Description --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="description">
                    <span class="label-text font-medium">{{ __('admin.description') }}</span>
                </label>
                <textarea
                    wire:model="description"
                    id="description"
                    class="textarea textarea-bordered w-full"
                    rows="2"
                    placeholder="Optional description of this ring group"
                ></textarea>
                <x-form-error name="description" />
            </div>

            {{-- Extensions --}}
            <div class="form-control w-full">
                <div class="flex items-center justify-between mb-2">
                    <label class="label-text font-medium">{{ __('admin.extensions') }}</label>
                    <button type="button" wire:click="addExtension" class="btn btn-sm btn-outline">
                        <x-heroicon-o-plus class="w-4 h-4" />
                        {{ __('admin.add_extension') }}
                    </button>
                </div>

                @foreach ($extensions as $index => $ext)
                    <div class="flex items-center gap-2 mb-2" wire:key="ext-{{ $index }}">
                        <input
                            wire:model="extensions.{{ $index }}.extension_uuid"
                            type="text"
                            class="input input-bordered flex-1"
                            placeholder="Extension number"
                        />
                        <span class="text-sm text-base-content/50">#{{ $ext['position'] }}</span>
                        <button type="button" wire:click="removeExtension({{ $index }})" class="btn btn-ghost btn-sm text-error">
                            <x-heroicon-o-x-mark class="w-4 h-4" />
                        </button>
                    </div>
                @endforeach

                @if (empty($extensions))
                    <p class="text-sm text-base-content/50 italic">No extensions assigned. Click "Add Extension" to add one.</p>
                @endif

                <x-form-error name="extensions.*.extension_uuid" />
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
                    {{ $this->isEdit ? 'Update' : 'Create' }} Ring Group
                </x-form-submit-button>
                <a href="{{ route('panel.ring-groups.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
