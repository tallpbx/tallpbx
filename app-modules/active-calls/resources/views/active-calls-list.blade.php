<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.active_calls_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        @if ($fsConnected)
            <span class="badge badge-success gap-1">
                <x-heroicon-o-arrow-path class="w-3 h-3 animate-spin" />
                {{ __('admin.live') }}
            </span>
        @endif
    </div>

    @if (!$fsConnected)
        <x-inline-alert type="warning">{{ __('admin.fs_not_connected') }}</x-inline-alert>
    @elseif (count($calls) === 0)
        <div class="card bg-base-100 border border-base-300">
            <div class="text-center py-8 text-base-content/40">
                {{ __('admin.no_active_calls') }}
            </div>
        </div>
    @else
        <div class="card bg-base-100 border border-base-300">
            @if ($actionMessage)
                <div class="alert alert-success rounded-none"><span>{{ $actionMessage }}</span></div>
            @endif
            @if ($actionError)
                <div class="alert alert-error rounded-none"><span>{{ $actionError }}</span></div>
            @endif
            <div class="overflow-x-auto">
                <table class="table table-zebra" wire:poll.2s="refreshCalls">
                    <thead>
                        <tr>
                            <th>{{ __('admin.cdr_caller') }}</th>
                            <th>{{ __('admin.cdr_destination') }}</th>
                            <th>{{ __('admin.cdr_duration') }}</th>
                            <th>{{ __('client.status') }}</th>
                            @if ($this->canHangup || $this->canTransfer)
                                <th></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($calls as $call)
                            <tr>
                                <td>
                                    <span class="font-medium">{{ $call['caller_id_name'] ?: $call['caller_id'] }}</span>
                                    @if ($call['caller_id_name'] && $call['caller_id'])
                                        <span class="text-sm text-base-content/60 block">{{ $call['caller_id'] }}</span>
                                    @endif
                                </td>
                                <td><span class="font-mono text-sm">{{ $call['destination'] }}</span></td>
                                <td>{{ gmdate('i:s', $call['duration']) }}</td>
                                <td><span class="badge badge-ghost badge-sm">{{ $call['state'] }}</span></td>
                                @if ($this->canHangup || $this->canTransfer)
                                    <td>
                                        <div class="flex gap-2 items-center">
                                            @if ($this->canHangup)
                                                <button type="button" class="btn btn-error btn-xs"
                                                    wire:click="hangupCall('{{ $call['uuid'] }}')">
                                                    {{ __('admin.active_calls_hangup') }}
                                                </button>
                                            @endif
                                            @if ($this->canTransfer)
                                                <input wire:model="transferDestination" type="text"
                                                    placeholder="{{ __('admin.active_calls_transfer_placeholder') }}"
                                                    class="input input-bordered input-xs w-28" />
                                                <button type="button" class="btn btn-outline btn-xs"
                                                    wire:click="transferCall('{{ $call['uuid'] }}', '{{ $transferDestination }}')">
                                                    {{ __('admin.active_calls_transfer') }}
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
