<div wire:poll.5s="refresh">
    <div class="mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.call_center_active_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
    </div>

    @if (!$fsConnected)
        <x-inline-alert type="warning">{{ __('admin.fs_not_connected') }}</x-inline-alert>
    @else
        @if ($actionMessage)
            <div class="alert alert-success rounded-none mb-4"><span>{{ $actionMessage }}</span></div>
        @endif
        @if ($actionError)
            <div class="alert alert-error rounded-none mb-4"><span>{{ $actionError }}</span></div>
        @endif

        @if (count($queues) === 0)
            <div class="card bg-base-100 border border-base-300">
                <div class="text-center py-8 text-base-content/40">{{ __('admin.no_active_queues') }}</div>
            </div>
        @else
            <div class="card bg-base-100 border border-base-300">
                <div class="overflow-x-auto">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th>Queue</th>
                                <th>Strategy</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($queues as $q)
                                <tr>
                                    <td class="font-medium">{{ $q['name'] }}</td>
                                    <td><span class="badge badge-ghost badge-sm">{{ $q['strategy'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if (count($agents) > 0)
            <div class="card bg-base-100 border border-base-300 mt-4">
                <div class="overflow-x-auto">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>{{ __('client.status') }}</th>
                                @if ($this->canControl)
                                    <th></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($agents as $agent)
                                <tr>
                                    <td class="font-medium font-mono text-sm">{{ $agent['agent'] }}</td>
                                    <td>
                                        <span class="badge @if($agent['status']==='Available') badge-success @elseif($agent['status']==='On Break') badge-warning @else badge-ghost @endif badge-sm">{{ $agent['status'] }}</span>
                                    </td>
                                    @if ($this->canControl)
                                        <td>
                                            <div class="flex gap-2 items-center">
                                                <button type="button" class="btn btn-outline btn-xs"
                                                    wire:click="pauseAgent('{{ $agent['agent'] }}')">
                                                    {{ __('admin.call_center_pause') }}
                                                </button>
                                                <button type="button" class="btn btn-outline btn-xs"
                                                    wire:click="unpauseAgent('{{ $agent['agent'] }}')">
                                                    {{ __('admin.call_center_unpause') }}
                                                </button>
                                                <button type="button" class="btn btn-error btn-xs"
                                                    wire:click="logoutAgent('{{ $agent['agent'] }}')">
                                                    {{ __('admin.call_center_logout') }}
                                                </button>
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
    @endif
</div>
