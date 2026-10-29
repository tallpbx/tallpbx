<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="__('admin.fax_send_title')" />

        @if ($operationalMessage !== null)
            <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">
                {{ $operationalMessage }}
            </x-inline-alert>
        @endif

        <form wire:submit="send" class="space-y-6">
            <div class="form-control w-full">
                <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                    <label class="label" for="tenantId">
                        <span class="label-text">Tenant</span>
                    </label>
                </x-tooltip>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full">
                    <option value="">Select a tenant...</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                <x-form-error name="tenantId" />
            </div>

            <div class="form-control w-full">
                <label class="label" for="faxNumber">
                    <span class="label-text">{{ __('admin.fax_number') }}</span>
                </label>
                <input wire:model="faxNumber" type="text" id="faxNumber" class="input input-bordered w-full" placeholder="e.g., +15551234567" />
                <x-form-error name="faxNumber" />
            </div>

            <div class="form-control w-full">
                <label class="label" for="document">
                    <span class="label-text">{{ __('admin.fax_document_path') }}</span>
                </label>
                <input wire:model="document" type="file" id="document" accept="application/pdf" class="file-input file-input-bordered w-full" />
                <x-form-error name="document" />
            </div>

            <div class="flex items-center gap-3">
                <x-form-submit-button target="send">
                    {{ __('admin.fax_send') }}
                </x-form-submit-button>
                <a href="{{ route('panel.fax.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
