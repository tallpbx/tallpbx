<div>
    <div class="mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.active_conferences_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
    </div>

    @if (!$fsConnected)
        <x-inline-alert type="warning">{{ __('admin.fs_not_connected') }}</x-inline-alert>
    @elseif (count($conferences) === 0)
        <div class="card bg-base-100 border border-base-300">
            <div class="text-center py-8 text-base-content/40">{{ __('admin.no_active_conferences') }}</div>
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
                <table class="table table-zebra" wire:poll.5s="refresh">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>{{ __('admin.conference_members') }}</th>
                            <th>{{ __('client.status') }}</th>
                            @if ($this->canMute || $this->canKick)
                                <th></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($conferences as $conf)
                            <tr>
                                <td class="font-medium">{{ $conf['name'] }}</td>
                                <td>{{ $conf['members'] }}</td>
                                <td><span class="badge badge-ghost badge-sm">{{ $conf['status'] }}</span></td>
                                @if ($this->canMute || $this->canKick)
                                    <td></td>
                                @endif
                            </tr>
                            @foreach ($conf['members_list'] as $member)
                                <tr>
                                    <td class="pl-8 font-mono text-sm text-base-content/70">{{ $member['caller_id'] }}</td>
                                    <td colspan="2"><span class="badge badge-ghost badge-xs">#{{ $member['id'] }}</span></td>
                                    @if ($this->canMute || $this->canKick)
                                        <td>
                                            <div class="flex gap-2 items-center">
                                                @if ($this->canMute)
                                                    <button type="button" class="btn btn-outline btn-xs"
                                                        wire:click="muteMember('{{ $conf['name'] }}', '{{ $member['id'] }}')">
                                                        {{ __('admin.active_conferences_mute') }}
                                                    </button>
                                                    <button type="button" class="btn btn-outline btn-xs"
                                                        wire:click="unmuteMember('{{ $conf['name'] }}', '{{ $member['id'] }}')">
                                                        {{ __('admin.active_conferences_unmute') }}
                                                    </button>
                                                @endif
                                                @if ($this->canKick)
                                                    <button type="button" class="btn btn-error btn-xs"
                                                        wire:click="kickMember('{{ $conf['name'] }}', '{{ $member['id'] }}')">
                                                        {{ __('admin.active_conferences_kick') }}
                                                    </button>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
