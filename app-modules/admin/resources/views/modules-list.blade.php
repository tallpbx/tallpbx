<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.modules_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
    </div>

    @if($pendingUninstallModuleId)
        <div class="alert alert-warning mb-6 items-start">
            <div class="space-y-3 w-full">
                <div>
                    <h3 class="font-semibold">{{ __('admin.uninstall_module') }}</h3>
                    @if($uninstallPreview['reason'] ?? null)
                        <p class="text-sm">{{ $uninstallPreview['reason'] }}</p>
                    @else
                        <p class="text-sm">{{ __('admin.uninstall_module_warning') }}</p>
                    @endif
                </div>

                @if(! empty($uninstallPreview['items']))
                    <ul class="list-disc list-inside text-sm">
                        @foreach($uninstallPreview['items'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @endif

                @if($uninstallPreview['can_uninstall'] ?? false)
                    <div class="max-w-xl">
                        <label class="label" for="uninstallConfirmation">
                            <span class="label-text">{{ __('admin.uninstall_module_confirmation', ['phrase' => $uninstallPreview['confirmation'] ?? '']) }}</span>
                        </label>
                        <input id="uninstallConfirmation"
                               type="text"
                               wire:model="uninstallConfirmation"
                               class="input input-bordered w-full"
                               autocomplete="off">
                        @error('uninstallConfirmation')
                            <div class="text-error text-sm mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                @endif

                <div class="flex gap-2">
                    @if($uninstallPreview['can_uninstall'] ?? false)
                        <button wire:click="uninstallModule"
                                class="btn btn-error btn-sm">
                            {{ __('admin.confirm_uninstall_module') }}
                        </button>
                    @endif
                    <button wire:click="cancelUninstall"
                            class="btn btn-ghost btn-sm">
                        {{ __('client.cancel') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.module_name') }}</th>
                        <th>{{ __('admin.module_display_name') }}</th>
                        <th>{{ __('admin.module_version') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('admin.module_protected') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($modules as $module)
                        <tr>
                            <td class="font-mono text-sm">{{ $module->name }}</td>
                            <td class="font-medium">{{ $module->display_name }}</td>
                            <td><span class="badge badge-ghost">{{ $module->version }}</span></td>
                            <td>
                                @if($module->status === \App\Models\Module::StatusUninstalled)
                                    <span class="badge badge-neutral">{{ __('admin.uninstalled') }}</span>
                                @elseif($module->enabled)
                                    <span class="badge badge-success">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-error">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                @if($module->required)
                                    <span class="badge badge-warning">{{ __('admin.module_required') }}</span>
                                @elseif($module->protected)
                                    <span class="badge badge-info">{{ __('admin.module_protected') }}</span>
                                @else
                                    <span class="text-base-content/30">—</span>
                                @endif
                            </td>
                            <td>
                                @if($module->status === \App\Models\Module::StatusUninstalled)
                                    <button wire:click="reinstallModule('{{ $module->id }}')"
                                            class="btn btn-primary btn-xs">
                                        {{ __('admin.reinstall_module') }}
                                    </button>
                                @else
                                    <div class="flex flex-wrap gap-2">
                                        @if($module->enabled)
                                            <button wire:click="toggleEnabled('{{ $module->id }}')"
                                                    @if($module->required) disabled @endif
                                                    class="btn btn-error btn-xs"
                                                    @if($module->required) title="{{ __('admin.module_required') }}" @endif>
                                                {{ __('admin.disable_module') }}
                                            </button>
                                        @else
                                            <button wire:click="toggleEnabled('{{ $module->id }}')"
                                                    class="btn btn-success btn-xs">
                                                {{ __('admin.enable_module') }}
                                            </button>
                                        @endif

                                        <button wire:click="prepareUninstall('{{ $module->id }}')"
                                                @if($module->required || $module->protected) disabled @endif
                                                class="btn btn-outline btn-error btn-xs"
                                                @if($module->required || $module->protected) title="{{ __('admin.module_protected') }}" @endif>
                                            {{ __('admin.uninstall_module') }}
                                        </button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_modules_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
