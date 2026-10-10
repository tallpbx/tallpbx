{{-- Modal: Edit Core PBX System Service --}}
@if ($showSystemServiceModal)
    <div class="modal modal-open">
        <div class="modal-box max-w-lg">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="font-bold text-lg text-base-content">
                        {{ __('admin.security_edit_service_title', ['name' => $systemServiceName]) }}
                    </h3>
                    <p class="text-xs text-base-content/60 mt-0.5">{{ $systemServiceDescription }}</p>
                </div>
                <button wire:click="$set('showSystemServiceModal', false)" type="button" class="btn btn-ghost btn-circle btn-sm">
                    <x-heroicon-o-x-mark class="w-5 h-5" />
                </button>
            </div>

            {{-- Safety Alert Banner --}}
            <div class="alert alert-warning text-xs mt-4 py-2.5 px-3 rounded-lg flex items-start gap-2">
                <x-heroicon-o-exclamation-triangle class="w-5 h-5 shrink-0 text-warning" />
                <div class="space-y-1">
                    <div class="font-semibold">{{ __('admin.security_edit_service_warning') }}</div>
                    @if (in_array($systemServiceName, ['Web Admin Portal', 'SSH Console'], true))
                        <div class="text-[11px] opacity-90">{{ __('admin.security_edit_service_lockout_note', ['ip' => $adminIp]) }}</div>
                    @endif
                </div>
            </div>

            <form wire:submit="saveSystemService" class="space-y-4 mt-4">
                @if ($systemServiceProtocol === 'icmp')
                    {{-- ICMP Diagnostic Echo --}}
                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">{{ __('admin.security_service_port_range') }}</span>
                        </label>
                        <input type="text" value="echo-request (ICMP & ICMPv6 Echo)" disabled
                               class="input input-bordered input-sm font-mono bg-base-200/60 cursor-not-allowed text-base-content/80" />
                    </div>

                    {{-- Protocol --}}
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">{{ __('admin.security_service_protocol') }}</span></label>
                        <select wire:model="systemServiceProtocol" disabled class="select select-bordered select-sm w-full bg-base-200/60 cursor-not-allowed">
                            <option value="icmp">ICMP (Ping Diagnostics & IPv6)</option>
                        </select>
                    </div>

                    {{-- Burstable Rate Limiting Option --}}
                    <div class="p-3.5 bg-base-200/50 rounded-lg space-y-3 border border-base-200">
                        <div class="flex items-center justify-between">
                            <label class="label cursor-pointer justify-start gap-2 p-0">
                                <input wire:model.live="systemServiceRateLimitEnabled" type="checkbox" class="checkbox checkbox-primary checkbox-xs" />
                                <span class="label-text font-semibold text-xs">{{ __('admin.security_enable_rate_limit') }}</span>
                            </label>
                            <span class="text-[11px] font-mono px-2 py-0.5 rounded {{ $systemServiceRateLimitEnabled ? 'bg-success/10 text-success' : 'bg-base-content/10 text-base-content/70' }}">
                                {{ $systemServiceRateLimitEnabled ? __('admin.security_rate_limited_active') : __('admin.security_unlimited_active') }}
                            </span>
                        </div>
                        @if ($systemServiceRateLimitEnabled)
                            <div class="grid grid-cols-2 gap-2 pt-1">
                                <div class="form-control">
                                    <label class="label p-0 pb-1">
                                        <span class="label-text text-xs">{{ __('admin.security_rate_limit_pps') }}</span>
                                    </label>
                                    <input wire:model="systemServiceRateLimit" type="number" min="1" max="1000" class="input input-bordered input-xs font-mono" />
                                </div>
                                <div class="form-control">
                                    <label class="label p-0 pb-1">
                                        <span class="label-text text-xs">{{ __('admin.security_rate_burst_packets') }}</span>
                                    </label>
                                    <input wire:model="systemServiceBurst" type="number" min="1" max="1000" class="input input-bordered input-xs font-mono" />
                                </div>
                            </div>
                        @endif
                        <div class="text-[11px] text-base-content/60 italic pt-1 border-t border-base-200/50">
                            {{ __('admin.security_icmp_dual_stack_note') }}
                        </div>
                    </div>
                @else
                    {{-- Port Range --}}
                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">{{ __('admin.security_service_port_range') }}</span>
                            <x-tooltip :tip="__('admin.security_service_port_range_help')" align="start" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </label>
                        <input wire:model="systemServicePortRange" type="text"
                               placeholder="e.g. 5060, 10000-20000"
                               class="input input-bordered input-sm font-mono @error('systemServicePortRange') input-error @enderror" />
                        @error('systemServicePortRange') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>

                    {{-- Protocol --}}
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">{{ __('admin.security_service_protocol') }}</span></label>
                        <select wire:model="systemServiceProtocol" class="select select-bordered select-sm w-full">
                            <option value="both">{{ __('admin.security_service_protocol_both') }}</option>
                            <option value="tcp">{{ __('admin.security_service_protocol_tcp') }}</option>
                            <option value="udp">{{ __('admin.security_service_protocol_udp') }}</option>
                        </select>
                        @error('systemServiceProtocol') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>
                @endif

                {{-- Source Network Restriction --}}
                <div class="form-control">
                    <label class="label justify-start gap-2">
                        <span class="label-text font-medium">{{ __('admin.security_service_source_ip') }}</span>
                        <x-tooltip :tip="__('admin.security_service_source_ip_help')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input wire:model="systemServiceSourceIp" type="text" placeholder="any or 10.8.0.0/24"
                           class="input input-bordered input-sm font-mono @error('systemServiceSourceIp') input-error @enderror" />
                    @error('systemServiceSourceIp') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                {{-- Status Enabled / Disabled --}}
                <div class="form-control pt-1">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input wire:model="systemServiceEnabled" type="checkbox" class="checkbox checkbox-primary checkbox-sm" />
                        <span class="label-text font-medium">{{ __('admin.security_service_enabled') }}</span>
                    </label>
                </div>

                <div class="modal-action flex items-center justify-between pt-2">
                    <button wire:click="resetSystemServiceToDefault({{ (int) $editingSystemServiceId }})" type="button"
                            class="btn btn-outline btn-warning btn-sm gap-1">
                        <x-heroicon-o-arrow-path class="w-4 h-4" />
                        <span>{{ __('admin.security_restore_defaults') }}</span>
                    </button>
                    <div class="flex items-center gap-2">
                        <button wire:click="$set('showSystemServiceModal', false)" type="button" class="btn btn-outline btn-sm">
                            {{ __('client.cancel') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm">
                            {{ __('client.save') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endif
