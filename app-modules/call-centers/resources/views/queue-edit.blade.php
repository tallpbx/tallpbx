<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.queue') }}</h1>
        <form wire:submit="save" class="space-y-6">
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="tenantId"><span class="label-text font-medium">Tenant</span></label>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full">
                    <option value="">Select a tenant...</option>
                    @foreach ($tenants as $tenant)<option value="{{ $tenant->id }}">{{ $tenant->name }}</option>@endforeach
                </select>
                @error('tenantId')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="name"><span class="label-text font-medium">{{ __('admin.call_center_queue_name') }}</span></label>
                <input wire:model="name" type="text" id="name" class="input input-bordered w-full" placeholder="e.g., Sales Queue" />
                @error('name')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="strategy"><span class="label-text font-medium">{{ __('admin.call_center_queue_strategy') }}</span></label>
                    <select wire:model="strategy" id="strategy" class="select select-bordered w-full">
                        <option value="ring-all">Ring All</option>
                        <option value="sequential">Sequential</option>
                        <option value="round-robin">Round Robin</option>
                        <option value="fewest-idle">Fewest Idle</option>
                    </select>
                    @error('strategy')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="timeout"><span class="label-text font-medium">{{ __('admin.call_center_queue_timeout') }}</span></label>
                    <input wire:model="timeout" type="number" id="timeout" class="input input-bordered w-full" min="1" max="600" />
                    @error('timeout')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="musicOnHold"><span class="label-text font-medium">{{ __('admin.call_center_queue_moh') }}</span></label>
                <input wire:model="musicOnHold" type="text" id="musicOnHold" class="input input-bordered w-full" placeholder="e.g., local_stream://moh" />
                @error('musicOnHold')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" type="checkbox" class="checkbox checkbox-primary" />
                    <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                    <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-primary">{{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.queue') }}</button>
                <a href="{{ route('panel.call-centers.queues.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
