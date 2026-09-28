<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.modules_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
    </div>

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
                                    <span class="text-sm text-base-content/60">
                                        {{ __('admin.module_restore_hint', ['name' => $module->name]) }}
                                    </span>
                                @else
                                    <div class="flex flex-wrap gap-2">
                                        @if($module->enabled)
                                            <button wire:click="toggleEnabled('{{ $module->id }}')"
                                                    @if($module->required || $module->protected) disabled @endif
                                                    class="btn btn-error btn-xs"
                                                    @if($module->required || $module->protected) title="{{ __('admin.module_protected') }}" @endif>
                                                {{ __('admin.disable_module') }}
                                            </button>
                                        @else
                                            <button wire:click="toggleEnabled('{{ $module->id }}')"
                                                    class="btn btn-success btn-xs">
                                                {{ __('admin.enable_module') }}
                                            </button>
                                        @endif
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
