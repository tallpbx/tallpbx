<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.ivr_menu') }}</h1>

        <form wire:submit="save" class="space-y-6">
            {{-- Tenant --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="tenantId">
                    <span class="label-text font-medium">Tenant</span>
                    <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full">
                    <option value="">Select a tenant...</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                @error('tenantId')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            {{-- Name --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="name">
                    <span class="label-text font-medium">Name</span>
                    <x-tooltip :tip="__('admin.name_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <input
                    wire:model="name"
                    type="text"
                    id="name"
                    class="input input-bordered w-full"
                    placeholder="e.g., Main IVR, Support Menu"
                />
                @error('name')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            {{-- Greeting --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="greetingUpload">
                    <span class="label-text font-medium">Greeting</span>
                    <x-tooltip :tip="__('admin.ivr_menu_greeting_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <input
                    wire:model="greetingUpload"
                    type="file"
                    id="greetingUpload"
                    accept="audio/wav,audio/mpeg,audio/ogg,.wav,.mp3,.ogg"
                    class="file-input file-input-bordered w-full"
                />
                @if ($this->isEdit && $greeting)
                    <span class="label-text-alt mt-1">Leave blank to keep the existing greeting.</span>
                @endif
                @error('greetingUpload')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            {{-- Description --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="description">
                    <span class="label-text font-medium">Description</span>
                </label>
                <textarea
                    wire:model="description"
                    id="description"
                    class="textarea textarea-bordered w-full"
                    rows="2"
                    placeholder="Optional description of this IVR menu"
                ></textarea>
                @error('description')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            {{-- Settings Grid --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                {{-- Timeout --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="timeout">
                        <span class="label-text font-medium">Timeout (seconds)</span>
                    </label>
                    <input
                        wire:model="timeout"
                        type="number"
                        id="timeout"
                        class="input input-bordered w-full"
                        min="1"
                        max="300"
                    />
                    @error('timeout')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>

                {{-- Max Failures --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="maxFailures">
                        <span class="label-text font-medium">Max Failures</span>
                    </label>
                    <input
                        wire:model="maxFailures"
                        type="number"
                        id="maxFailures"
                        class="input input-bordered w-full"
                        min="1"
                        max="100"
                    />
                    @error('maxFailures')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>

                {{-- Digit Length --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="digitLength">
                        <span class="label-text font-medium">Digit Length (0 = variable)</span>
                    </label>
                    <input
                        wire:model="digitLength"
                        type="number"
                        id="digitLength"
                        class="input input-bordered w-full"
                        min="0"
                        max="20"
                    />
                    @error('digitLength')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            {{-- Enabled --}}
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" type="checkbox" class="checkbox checkbox-primary" />
                    <span class="label-text font-medium">Enabled</span>
                    <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-primary">
                    {{ $this->isEdit ? 'Update' : 'Create' }} IVR Menu
                </button>
                <a href="{{ route('panel.ivr-menus.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
