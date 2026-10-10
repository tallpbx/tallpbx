{{-- Modal: Custom Firewall Rule --}}
@if ($showRuleModal)
    <div class="modal modal-open">
        <div class="modal-box max-w-lg">
            <h3 class="font-bold text-lg text-base-content">
                {{ $editingRuleId ? 'Edit Firewall Rule' : __('admin.security_add_rule') }}
            </h3>
            <form wire:submit="saveCustomRule" class="space-y-4 mt-4">
                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">{{ __('admin.security_rule_name') }}</span></label>
                    <input wire:model="ruleDescription" type="text" placeholder="e.g. Allow Office VoIP Phones"
                           class="input input-bordered input-sm @error('ruleDescription') input-error @enderror" />
                    @error('ruleDescription') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">{{ __('admin.security_source_ip') }}</span></label>
                    <input wire:model="ruleSourceIp" type="text" placeholder="any or 192.168.1.0/24"
                           class="input input-bordered input-sm font-mono @error('ruleSourceIp') input-error @enderror" />
                    @error('ruleSourceIp') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">{{ __('admin.security_service_port') }}</span></label>
                    <select wire:model.live="ruleServiceId" class="select select-bordered select-sm">
                        <option value="">Custom Port / Protocol</option>
                        @foreach ($catalogServices as $service)
                            <option value="{{ $service->id }}">{{ $service->name }} ({{ $service->port_range }}/{{ strtoupper($service->protocol) }})</option>
                        @endforeach
                    </select>
                </div>

                @if (! $ruleServiceId)
                    <div class="grid grid-cols-2 gap-2">
                        <div class="form-control">
                            <label class="label"><span class="label-text font-medium">Custom Port</span></label>
                            <input wire:model="ruleCustomPort" type="text" placeholder="e.g. 5060, 10000-20000"
                                   class="input input-bordered input-sm font-mono @error('ruleCustomPort') input-error @enderror" />
                            @error('ruleCustomPort') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text font-medium">Protocol</span></label>
                            <select wire:model="ruleCustomProtocol" class="select select-bordered select-sm">
                                <option value="tcp">TCP</option>
                                <option value="udp">UDP</option>
                                <option value="all">Both (All)</option>
                            </select>
                        </div>
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-2">
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">{{ __('admin.security_action') }}</span></label>
                        <select wire:model="ruleAction" class="select select-bordered select-sm">
                            <option value="accept">{{ __('admin.security_action_allow') }} (Accept)</option>
                            <option value="drop">{{ __('admin.security_action_block') }} (Drop)</option>
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">{{ __('client.status') }}</span></label>
                        <label class="label cursor-pointer justify-start gap-2 pt-2">
                            <input wire:model="ruleEnabled" type="checkbox" class="checkbox checkbox-primary checkbox-sm" />
                            <span class="label-text">{{ __('client.enabled') }}</span>
                        </label>
                    </div>
                </div>

                <div class="modal-action">
                    <button wire:click="$set('showRuleModal', false)" type="button" class="btn btn-outline btn-sm">
                        {{ __('client.cancel') }}
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        {{ __('client.save') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
@endif
