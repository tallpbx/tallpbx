<div class="space-y-6">
    @if (! $prefilterEnabled)
        <div class="alert alert-warning/15 border border-warning/30 text-base-content rounded-box flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5" role="alert">
            <div class="flex items-center gap-2.5">
                <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning shrink-0" />
                <div class="text-xs">
                    <span class="font-semibold text-warning">{{ __('admin.security_prefilter_bypassed_notice_title') }}:</span>
                    <span class="opacity-90">{{ __('admin.security_prefilter_bypassed_block_allow_notice') }}</span>
                </div>
            </div>
            <button wire:click="setPrefilterEnabled(true)"
                    wire:loading.attr="disabled"
                    wire:target="setPrefilterEnabled"
                    type="button"
                    class="btn btn-warning btn-xs shrink-0 whitespace-nowrap self-start sm:self-auto">
                <span wire:loading.remove wire:target="setPrefilterEnabled" class="inline-flex items-center gap-1">
                    <x-heroicon-o-shield-check class="w-3.5 h-3.5" />
                    <span>{{ __('admin.security_prefilter_enable_action') }}</span>
                </span>
                <span wire:loading wire:target="setPrefilterEnabled" class="inline-flex items-center gap-1">
                    <span class="loading loading-spinner loading-xs"></span>
                    <span>{{ __('admin.security_prefilter_enabling') }}</span>
                </span>
            </button>
        </div>
    @endif

    {{-- Card: Whitelist (trusted allow list) --}}
    <div id="whitelist-section" class="card bg-base-100 shadow-sm border {{ ! $prefilterEnabled ? 'border-warning/30' : 'border-base-200' }} scroll-mt-6">
        <div class="card-body p-4 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_whitelist_ips') }}</h2>
                        <span class="badge badge-neutral badge-sm font-mono">{{ $whitelistCount }}</span>
                        @if (! $prefilterEnabled)
                            <span class="badge badge-warning badge-xs font-semibold">{{ __('admin.security_bypassed_badge') }}</span>
                        @endif
                        <x-tooltip :tip="__('admin.security_whitelist_desc')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </div>
                </div>
            </div>

            {{-- Split-Panel Workbench: Add Form (Left) & Searchable Table (Right) --}}
            <div class="flex flex-col lg:flex-row gap-6 items-start">
                {{-- Left Column: Quick-Add Form & Self-Whitelisting (Autosizes with min width) --}}
                <div class="w-full lg:w-1/4 xl:w-72 2xl:w-80 min-w-[240px] max-w-xs xl:max-w-sm lg:shrink-0 space-y-3">
                    <form wire:submit="addWhitelistIp" class="space-y-2.5 bg-base-200/50 p-3.5 rounded-box border border-base-200">
                        <div class="text-xs font-semibold text-base-content/80 flex items-center gap-1.5">
                            <x-heroicon-o-shield-check class="w-4 h-4 text-success" />
                            <span>{{ __('admin.security_add_to_whitelist') }}</span>
                        </div>
                        <div class="space-y-2">
                            <div>
                                <input wire:model="newWhitelistIp" type="text"
                                       placeholder="{{ __('admin.security_ip_or_cidr') }}"
                                       class="input input-bordered input-sm w-full font-mono @error('newWhitelistIp') input-error @enderror" />
                                @error('newWhitelistIp')
                                    <span class="text-error text-xs mt-0.5 block">{{ $message }}</span>
                                @enderror
                            </div>
                            <input wire:model="newWhitelistDescription" type="text"
                                   placeholder="{{ __('admin.security_ip_description') }}"
                                   class="input input-bordered input-sm w-full" />
                            <button type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="addWhitelistIp"
                                    class="btn btn-primary btn-sm w-full gap-1 shadow-xs">
                                <span wire:loading.remove wire:target="addWhitelistIp" class="inline-flex items-center gap-1">
                                    <x-heroicon-o-plus class="w-4 h-4" />
                                    <span>{{ __('admin.security_add_to_whitelist') }}</span>
                                </span>
                                <span wire:loading wire:target="addWhitelistIp" class="inline-flex items-center gap-1">
                                    <span class="loading loading-spinner loading-xs"></span>
                                    <span>{{ __('admin.security_adding_to_whitelist') }}</span>
                                </span>
                            </button>
                        </div>
                    </form>

                    {{-- Admin Self-Whitelisting Lockout Guard (shown only when unprotected) --}}
                    @if (! $isCurrentIpWhitelisted)
                        <div class="p-3 bg-warning/10 border border-warning/30 rounded-box flex flex-col gap-2">
                            <div class="text-xs">
                                <div class="font-semibold text-warning-content flex items-center gap-1">
                                    <x-heroicon-o-exclamation-triangle class="w-3.5 h-3.5 text-warning shrink-0" />
                                    <span>Lockout Safety</span>
                                </div>
                                <div class="text-base-content/70 text-[11px] mt-0.5">Your IP (<span class="font-mono">{{ $adminIp }}</span>) is not whitelisted.</div>
                            </div>
                            <button wire:click="whitelistCurrentIp"
                                    wire:loading.attr="disabled"
                                    wire:target="whitelistCurrentIp"
                                    type="button"
                                    class="btn btn-warning btn-xs w-full shadow-xs">
                                <span wire:loading.remove wire:target="whitelistCurrentIp">
                                    {{ __('admin.security_protect_my_ip') }}
                                </span>
                                <span wire:loading wire:target="whitelistCurrentIp" class="inline-flex items-center justify-center gap-1">
                                    <span class="loading loading-spinner loading-xs"></span>
                                    <span>{{ __('admin.security_protecting_my_ip') }}</span>
                                </span>
                            </button>
                        </div>
                    @endif
                </div>

                {{-- Right Column: Search Filter & Scrollable Table Viewport (Flex Expand) --}}
                <div class="w-full lg:flex-1 min-w-0 space-y-3">
                    <div class="relative">
                        <input wire:model.live.debounce.250ms="whitelistSearch" type="text"
                               placeholder="{{ __('admin.security_search_whitelist') }}"
                               class="input input-bordered input-sm w-full pl-9" />
                        <x-heroicon-o-magnifying-glass class="w-4 h-4 absolute left-3 top-2.5 text-base-content/40" />
                    </div>

                    <div class="overflow-x-auto w-full max-h-80 overflow-y-auto border border-base-200 rounded-box">
                        <table class="table table-pin-rows w-full">
                            <thead>
                                <tr>
                                    <th class="whitespace-nowrap">{{ __('admin.security_ip_or_cidr') }}</th>
                                    <th>{{ __('admin.description') }}</th>
                                    <th class="text-right w-16 whitespace-nowrap">{{ __('admin.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($whitelistIps as $item)
                                    <tr class="hover" wire:loading.class="opacity-40 pointer-events-none" wire:target="deleteIp({{ $item->id }})">
                                        <td class="font-mono font-medium text-success whitespace-nowrap">
                                            {{ $item->ip_address }}
                                            @if ($item->ip_address === $adminIp)
                                                <span class="badge badge-success badge-sm ml-1">You</span>
                                            @endif
                                        </td>
                                        <td class="text-base-content/70 whitespace-normal break-words max-w-xs text-xs sm:text-sm">
                                            {{ $item->description ?: '—' }}
                                        </td>
                                        <td class="text-right w-16 whitespace-nowrap">
                                            <x-icon-button icon="heroicon-o-trash"
                                                           :label="__('client.delete').' '.$item->ip_address"
                                                           wire:click="deleteIp({{ $item->id }})"
                                                           loading-target="deleteIp({{ $item->id }})"
                                                           class="text-error p-1" />
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center py-6 text-base-content/60">
                                            {{ __('admin.security_no_whitelist_found') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Card: Blacklist (permanent kernel drop) --}}
    <div id="blacklist-section" class="card bg-base-100 shadow-sm border {{ ! $prefilterEnabled ? 'border-warning/30' : 'border-base-200' }} scroll-mt-6">
        <div class="card-body p-4 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_blacklist_ips') }}</h2>
                        <span class="badge badge-neutral badge-sm font-mono">{{ $blacklistCount }}</span>
                        @if (! $prefilterEnabled)
                            <span class="badge badge-warning badge-xs font-semibold">{{ __('admin.security_bypassed_badge') }}</span>
                        @endif
                        <x-tooltip :tip="__('admin.security_blacklist_desc')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </div>
                </div>
            </div>

            {{-- Split-Panel Workbench: Add Form (Left) & Searchable Table (Right) --}}
            <div class="flex flex-col lg:flex-row gap-6 items-start">
                {{-- Left Column: Quick-Add Form (Autosizes with min width) --}}
                <div class="w-full lg:w-1/4 xl:w-72 2xl:w-80 min-w-[240px] max-w-xs xl:max-w-sm lg:shrink-0 space-y-3">
                    <form wire:submit="addBlacklistIp" class="space-y-2.5 bg-base-200/50 p-3.5 rounded-box border border-base-200">
                        <div class="text-xs font-semibold text-base-content/80 flex items-center gap-1.5">
                            <x-heroicon-o-no-symbol class="w-4 h-4 text-error" />
                            <span>{{ __('admin.security_add_to_blacklist') }}</span>
                        </div>
                        <div class="space-y-2">
                            <div>
                                <input wire:model="newBlacklistIp" type="text"
                                       placeholder="{{ __('admin.security_ip_or_cidr') }}"
                                       class="input input-bordered input-sm w-full font-mono @error('newBlacklistIp') input-error @enderror" />
                                @error('newBlacklistIp')
                                    <span class="text-error text-xs mt-0.5 block">{{ $message }}</span>
                                @enderror
                            </div>
                            <input wire:model="newBlacklistDescription" type="text"
                                   placeholder="{{ __('admin.security_ip_description') }}"
                                   class="input input-bordered input-sm w-full" />
                            <button type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="addBlacklistIp"
                                    class="btn btn-error btn-sm w-full gap-1 shadow-xs">
                                <span wire:loading.remove wire:target="addBlacklistIp" class="inline-flex items-center gap-1">
                                    <x-heroicon-o-plus class="w-4 h-4" />
                                    <span>{{ __('admin.security_add_to_blacklist') }}</span>
                                </span>
                                <span wire:loading wire:target="addBlacklistIp" class="inline-flex items-center gap-1">
                                    <span class="loading loading-spinner loading-xs"></span>
                                    <span>{{ __('admin.security_adding_to_blacklist') }}</span>
                                </span>
                            </button>
                        </div>
                    </form>
                </div>

                {{-- Right Column: Search Filter & Scrollable Table Viewport (Flex Expand) --}}
                <div class="w-full lg:flex-1 min-w-0 space-y-3">
                    <div class="relative">
                        <input wire:model.live.debounce.250ms="blacklistSearch" type="text"
                               placeholder="{{ __('admin.security_search_blacklist') }}"
                               class="input input-bordered input-sm w-full pl-9" />
                        <x-heroicon-o-magnifying-glass class="w-4 h-4 absolute left-3 top-2.5 text-base-content/40" />
                    </div>

                    <div class="overflow-x-auto w-full max-h-80 overflow-y-auto border border-base-200 rounded-box">
                        <table class="table table-pin-rows w-full">
                            <thead>
                                <tr>
                                    <th class="whitespace-nowrap">{{ __('admin.security_ip_or_cidr') }}</th>
                                    <th>{{ __('admin.description') }}</th>
                                    <th class="text-right w-16 whitespace-nowrap">{{ __('admin.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($blacklistIps as $item)
                                    <tr class="hover" wire:loading.class="opacity-40 pointer-events-none" wire:target="deleteIp({{ $item->id }})">
                                        <td class="font-mono font-medium text-error whitespace-nowrap">
                                            {{ $item->ip_address }}
                                        </td>
                                        <td class="text-base-content/70 whitespace-normal break-words max-w-xs text-xs sm:text-sm">
                                            {{ $item->description ?: '—' }}
                                        </td>
                                        <td class="text-right w-16 whitespace-nowrap">
                                            <x-icon-button icon="heroicon-o-trash"
                                                           :label="__('client.delete').' '.$item->ip_address"
                                                           wire:click="deleteIp({{ $item->id }})"
                                                           loading-target="deleteIp({{ $item->id }})"
                                                           class="text-error p-1" />
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center py-6 text-base-content/60">
                                            {{ __('admin.security_no_blacklist_found') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
