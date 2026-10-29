<div x-data="{
    allOpen: true,
    openCategories: @js(array_keys($groupedModules)),
    toggleAll() {
        this.allOpen = !this.allOpen;
        if (this.allOpen) {
            this.openCategories = @js(array_keys($groupedModules));
        } else {
            this.openCategories = [];
        }
    },
    isOpen(category) {
        return this.openCategories.includes(category);
    },
    toggleCategory(category, open) {
        if (open) {
            if (!this.openCategories.includes(category)) {
                this.openCategories.push(category);
            }
        } else {
            this.openCategories = this.openCategories.filter(c => c !== category);
        }
        this.allOpen = this.openCategories.length === {{ count($groupedModules) }};
    }
}">
    <x-page-header :title="__('admin.modules_title')" :tooltip="__('admin.page_info_tooltip')">
        <x-slot:actions>
            <button type="button" @click="toggleAll()" class="btn btn-ghost btn-sm text-xs gap-1.5">
                <x-heroicon-o-arrows-pointing-out class="w-4 h-4" x-show="!allOpen" />
                <x-heroicon-o-arrows-pointing-in class="w-4 h-4" x-show="allOpen" />
                <span x-text="allOpen ? '{{ __('admin.collapse_all') }}' : '{{ __('admin.expand_all') }}'"></span>
            </button>
        </x-slot:actions>
    </x-page-header>

    @foreach($groupedModules as $category => $categoryModules)
        <details class="collapse collapse-arrow bg-base-100 border border-base-300 mb-3"
                 :open="isOpen('{{ $category }}')"
                 @toggle="toggleCategory('{{ $category }}', $el.open)">
            <summary class="collapse-title">
                <div class="flex items-center gap-2">
                    <span class="text-lg font-semibold">{{ $this->categoryLabel($category) }}</span>
                    <span class="badge badge-ghost badge-sm">{{ $categoryModules->count() }}</span>
                </div>
            </summary>
            <div class="collapse-content">
                <div class="overflow-x-auto">
                    <table class="table table-zebra table-fixed w-full">
                        <colgroup>
                            <col class="w-7/12">
                            <col class="w-2/12">
                            <col class="w-3/12">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="w-7/12">{{ __('admin.modules_title') }}</th>
                                <th class="w-2/12">{{ __('client.status') }}</th>
                                <th class="w-3/12 text-right">{{ __('client.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($categoryModules as $module)
                                <tr class="{{ $module->status === \App\Models\Module::StatusUninstalled ? 'opacity-60' : '' }}">
                                    <td class="w-7/12">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-sm">{{ $module->display_name }}</span>
                                            @if($module->source === 'custom')
                                                <span class="badge badge-info badge-xs">{{ __('admin.modules_custom') }}</span>
                                            @elseif($module->source === 'vendor')
                                                <span class="badge badge-ghost badge-xs">{{ __('admin.modules_vendor') }}</span>
                                            @endif
                                            @if($module->source !== 'core' && $module->source !== 'builtin' && $module->version)
                                                <span class="badge badge-ghost badge-xs font-mono">v{{ $module->version }}</span>
                                            @endif
                                        </div>
                                        <div class="font-mono text-xs text-base-content/50 mt-0.5">{{ $module->name }}</div>
                                    </td>
                                    <td class="w-2/12">
                                        @if($module->status === \App\Models\Module::StatusUninstalled)
                                            <span class="badge badge-neutral badge-xs">{{ __('admin.uninstalled') }}</span>
                                        @elseif($module->required && $module->enabled)
                                            <span class="badge badge-neutral badge-xs opacity-75">{{ __('admin.module_required') }}</span>
                                        @elseif($module->enabled)
                                            <span class="badge badge-success badge-xs">{{ __('admin.enabled') }}</span>
                                        @else
                                            <span class="badge badge-error badge-xs">{{ __('admin.disabled') }}</span>
                                        @endif
                                    </td>
                                    <td class="w-3/12 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            @if($module->status === \App\Models\Module::StatusUninstalled)
                                                <span class="text-xs text-base-content/60">
                                                    {{ __('admin.module_restore_hint', ['name' => $module->name]) }}
                                                </span>
                                            @elseif($module->required && $module->enabled)
                                                <span class="text-base-content/30">—</span>
                                            @elseif($canManage)
                                                <div class="flex items-center gap-2"
                                                     wire:loading.class="opacity-70 pointer-events-none"
                                                     wire:target="toggleEnabled('{{ $module->id }}')">
                                                    <input type="checkbox" class="toggle toggle-sm {{ $module->enabled ? 'toggle-success' : 'toggle-neutral' }}"
                                                           @checked($module->enabled)
                                                           wire:click="toggleEnabled('{{ $module->id }}')"
                                                           wire:target="toggleEnabled('{{ $module->id }}')"
                                                           wire:loading.attr="disabled"
                                                           aria-label="{{ $module->display_name }}" />
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
                                                </div>
                                            @else
                                                <span class="text-xs text-base-content/50">
                                                    {{ $module->enabled ? __('admin.enabled') : __('admin.disabled') }}
                                                </span>
                                            @endif

                                            @if($canManage && $module->source === 'custom' && $module->status !== \App\Models\Module::StatusUninstalled)
                                                <div class="dropdown dropdown-end">
                                                    <div tabindex="0" role="button" class="btn btn-ghost btn-xs btn-square" aria-label="{{ __('admin.modules_delete_extension') }}">
                                                        <x-heroicon-m-ellipsis-vertical class="w-4 h-4" />
                                                    </div>
                                                    <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box border border-base-300 z-30 w-52 p-2 shadow-lg text-left">
                                                        <li>
                                                            <button type="button" class="text-error"
                                                                    wire:click="confirmDeleteCustom('{{ $module->id }}')">
                                                                {{ __('admin.modules_delete_extension') }}
                                                            </button>
                                                        </li>
                                                    </ul>
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center py-6 text-base-content/40">{{ __('admin.no_modules_found') }}</td>
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
