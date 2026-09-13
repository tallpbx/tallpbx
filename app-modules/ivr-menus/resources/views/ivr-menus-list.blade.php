<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.ivr_menus_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.ivr-menus.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_ivr_menu') }}
        </a>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.ivr_menu_name') }}</th>
                        <th>{{ __('admin.ivr_menu_options') }}</th>
                        <th>{{ __('admin.ivr_menu_timeout') }}</th>
                        <th>{{ __('admin.ivr_menu_digit_length') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($menus as $menu)
                        <tr>
                            <td class="font-medium">{{ $menu->name }}</td>
                            <td>
                                @if ($menu->options_count > 0)
                                    <span class="badge badge-ghost">{{ $menu->options_count }} {{ __('admin.ivr_menu_options') }}</span>
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </td>
                            <td>{{ $menu->timeout }}s</td>
                            <td>
                                @if ($menu->digit_length > 0)
                                    {{ $menu->digit_length }} {{ __('admin.ivr_menu_digits') }}
                                @else
                                    <span class="text-base-content/50">{{ __('admin.ivr_menu_variable') }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($menu->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.ivr-menus.edit', $menu->id) }}" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button
                                        wire:click="confirmMenuDeletion('{{ $menu->id }}')"
                                        class="btn btn-ghost btn-xs text-error"
                                    >
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_ivr_menus_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_delete_ivr_menu_title')"
            :message="__('admin.modal_delete_generic_statement', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_ivr_menu_confirm')"
            confirm-action="deleteMenu('{{ $pendingDeletionId }}')"
            cancel-action="cancelMenuDeletion"
            :error="$deleteError"
            :error-title="__('admin.modal_delete_ivr_menu_error_title')"
        />
    @endif
</div>
