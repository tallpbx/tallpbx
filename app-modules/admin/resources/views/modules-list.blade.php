<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.modules_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right" align="start"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
    </div>

    @foreach($groupedModules as $category => $categoryModules)
        <details class="collapse collapse-arrow bg-base-100 border border-base-300 mb-3" @if($loop->first) open @endif>
            <summary class="collapse-title">
                <div class="flex items-center gap-2">
                    <span class="text-lg font-semibold">{{ $this->categoryLabel($category) }}</span>
                    <span class="badge badge-ghost badge-sm">{{ $categoryModules->count() }}</span>
                </div>
            </summary>
            <div class="collapse-content">
                <div class="overflow-x-auto">
                    <table class="table table-zebra w-full">
                        <thead>
                            <tr>
                                <th>{{ __('admin.module_name') }}</th>
                                <th>{{ __('admin.module_display_name') }}</th>
                                <th>{{ __('admin.module_version') }}</th>
                                <th>{{ __('admin.modules_source') }}</th>
                                <th>{{ __('client.status') }}</th>
                                <th>{{ __('client.actions') }}</th>
                                <th class="w-12"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($categoryModules as $module)
                                <tr class="{{ $module->status === \App\Models\Module::StatusUninstalled ? 'opacity-60' : '' }}">
                                    <td class="font-mono text-xs">{{ $module->name }}</td>
                                    <td class="font-medium text-sm">{{ $module->display_name }}</td>
                                    <td><span class="badge badge-ghost badge-xs font-mono">{{ $module->version }}</span></td>
                                    <td>
                                        @if($module->source === 'custom')
                                            <span class="badge badge-info badge-xs">{{ __('admin.modules_custom') }}</span>
                                        @elseif($module->source === 'vendor')
                                            <span class="badge badge-ghost badge-xs">{{ __('admin.modules_vendor') }}</span>
                                        @else
                                            <span class="badge badge-neutral badge-xs">{{ __('admin.modules_builtin') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($module->status === \App\Models\Module::StatusUninstalled)
                                            <span class="badge badge-neutral badge-xs">{{ __('admin.uninstalled') }}</span>
                                        @elseif($module->enabled)
                                            <span class="badge badge-success badge-xs">{{ __('admin.enabled') }}</span>
                                        @else
                                            <span class="badge badge-error badge-xs">{{ __('admin.disabled') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($module->status === \App\Models\Module::StatusUninstalled)
                                            <span class="text-xs text-base-content/60">
                                                {{ __('admin.module_restore_hint', ['name' => $module->name]) }}
                                            </span>
                                        @else
                                            <div class="flex items-center gap-2">
                                                {{-- Standardized toggle pattern: pointer lock, scoped wire:target,
                                                     present-continuous verb, and the DaisyUI spinner while in flight. --}}
                                                <div class="flex items-center gap-2"
                                                     wire:loading.class="opacity-70 pointer-events-none"
                                                     wire:target="toggleEnabled('{{ $module->id }}')">
                                                    @if($module->required)
                                                        <x-tooltip :tip="__('admin.module_required')" position="left">
                                                            <input type="checkbox" class="toggle toggle-sm toggle-primary" checked disabled />
                                                        </x-tooltip>
                                                        <span class="text-xs text-base-content/60">{{ __('admin.enabled') }}</span>
                                                    @else
                                                        @if($module->protected)
                                                            <x-tooltip :tip="__('admin.modules_protected_hint')" position="left">
                                                        @endif
                                                            <input type="checkbox" class="toggle toggle-sm {{ $module->enabled ? 'toggle-primary' : 'toggle-warning' }}"
                                                                   @checked($module->enabled)
                                                                   wire:click="toggleEnabled('{{ $module->id }}')"
                                                                   wire:target="toggleEnabled('{{ $module->id }}')"
                                                                   wire:loading.attr="disabled" />
                                                        @if($module->protected)
                                                            </x-tooltip>
                                                        @endif
                                                        <span class="text-xs text-base-content/60"
                                                              wire:loading.remove wire:target="toggleEnabled('{{ $module->id }}')">
                                                            {{ $module->enabled ? __('admin.enabled') : __('admin.disabled') }}
                                                        </span>
                                                        <span class="hidden text-xs text-base-content/60"
                                                              wire:loading.class="flex" wire:loading
                                                              wire:target="toggleEnabled('{{ $module->id }}')">
                                                            <span class="loading loading-spinner loading-xs"></span>
                                                            {{ $module->enabled ? __('admin.modules_disabling') : __('admin.modules_enabling') }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="w-12">
                                        @if($module->source === 'custom' && $module->status !== \App\Models\Module::StatusUninstalled)
                                            <div class="dropdown dropdown-end">
                                                <div tabindex="0" role="button" class="btn btn-ghost btn-xs btn-square" aria-label="{{ __('admin.modules_delete_extension') }}">
                                                    <x-heroicon-m-ellipsis-vertical class="w-4 h-4" />
                                                </div>
                                                <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box border border-base-300 z-30 w-52 p-2 shadow-lg">
                                                    <li>
                                                        <button type="button" class="text-error"
                                                                wire:click="confirmDeleteCustom('{{ $module->id }}')">
                                                            {{ __('admin.modules_delete_extension') }}
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        @else
                                            <span class="text-base-content/30">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-6 text-base-content/40">{{ __('admin.no_modules_found') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
    @endforeach

    @if($pendingDeletionId !== null)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modules_delete_extension')"
            :message="__('admin.modules_delete_confirm', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modules_delete_extension')"
            confirm-action="deleteCustomModule('{{ $pendingDeletionId }}')"
            cancel-action="cancelCustomDeletion"
            :error="$deleteError"
            :required-text="$pendingDeletionPhrase"
        />
    @endif
</div>
