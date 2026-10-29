<div>
    <x-page-header :title="__('admin.extensions_title')" :tooltip="__('admin.extension_header_tooltip')">
        <x-slot:actions>
            @if(\Illuminate\Support\Facades\Route::has('panel.extensions.create-multiple'))
                <a href="{{ route('panel.extensions.create-multiple') }}" wire:navigate class="btn btn-primary btn-sm">
                    <x-heroicon-o-queue-list class="w-4 h-4" />
                    {{ __('admin.create_multiple_extensions') }}
                </a>
            @endif
            <a href="{{ route('panel.extensions.create') }}" wire:navigate class="btn btn-primary btn-sm">
                <x-heroicon-o-plus class="w-4 h-4" />
                {{ __('admin.create_extension') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th><x-tooltip :tip="__('admin.extension_number_tooltip')" position="bottom">{{ __('admin.extension_number') }}</x-tooltip></th>
                        <th><x-tooltip :tip="__('admin.extension_description_tooltip')" position="bottom">{{ __('admin.name') }}</x-tooltip></th>
                        <th><x-tooltip :tip="__('admin.voicemail_enabled_tooltip')" position="bottom">{{ __('admin.voicemail') }}</x-tooltip></th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($extensions as $extension)
                        <tr wire:key="{{ $extension->id }}"
                            wire:loading.class="opacity-40 pointer-events-none"
                            wire:target="confirmExtensionDeletion('{{ $extension->id }}')">
                            <td class="font-medium">{{ $extension->extension_number }}</td>
                            <td>{{ $extension->display_name }}</td>
                            <td>
                                @if($extension->voicemail_enabled)
                                    <span class="badge badge-info">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                @if($extension->enabled)
                                    <span class="badge badge-success">{{ __('client.active') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('client.inactive') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.extensions.edit', $extension->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$extension->extension_number" wire:click="confirmExtensionDeletion('{{ $extension->id }}')" class="text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_extensions_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if (method_exists($extensions, 'links'))
        <div class="mt-4">
            {{ $extensions->links() }}
        </div>
    @endif

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_delete_extension_title')"
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_extension_confirm')"
            confirm-action="deleteExtension()"
            cancel-action="cancelExtensionDeletion"
        />
    @endif
</div>
