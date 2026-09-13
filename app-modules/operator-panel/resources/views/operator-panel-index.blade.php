<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">{{ __('admin.operator_panel_title') }}</h1>
            @if ($fsConnected)<span class="badge badge-success gap-1"><x-heroicon-o-arrow-path class="w-3 h-3 animate-spin" /> {{ __('admin.live') }}</span>@endif
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

            @if ($this->canOriginate)
                <div class="flex gap-2 items-center mb-4">
                    <label class="text-sm">{{ __('admin.operator_panel_source') }}</label>
                    <input wire:model="sourceExtension" type="text"
                        placeholder="{{ __('admin.active_calls_transfer_placeholder') }}"
                        class="input input-bordered input-xs w-28" />
                    <button type="button" class="btn btn-primary btn-xs" wire:click="originateCall">
                        {{ __('admin.operator_panel_originate') }}
                    </button>
                </div>
            @endif

            <div class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 lg:grid-cols-10 gap-3" wire:poll.5s="refresh">
                @forelse ($extensions as $ext)
                    @php($active = $activeCalls[$ext->extension_number] ?? null)
                    <div class="card @if($active) bg-success text-success-content @elseif($ext->extension_number === $selectedExtension) bg-primary text-primary-content @else bg-base-200 @endif compact @if($this->canOriginate) cursor-pointer @endif"
                        @if($this->canOriginate) wire:click="$set('selectedExtension', '{{ $ext->extension_number }}')" @endif>
                        <div class="card-body items-center text-center p-3">
                            <span class="text-lg font-bold">{{ $ext->extension_number }}</span>
                            <span class="text-xs truncate w-full">{{ $ext->display_name ?: $ext->extension_number }}</span>
                            <span class="text-xs">@if($active){{ $active['state'] }}@else<span class="opacity-50">idle</span>@endif</span>
                            @if ($active && $this->canHangup)
                                <button type="button" class="btn btn-error btn-xs"
                                    wire:click.stop="hangupCall('{{ $active['uuid'] }}')">
                                    {{ __('admin.operator_panel_hangup') }}
                                </button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="col-span-full text-center py-8 text-base-content/50">{{ __('admin.no_extensions_found') }}</div>
                @endforelse
            </div>
        @endif
    </div>
</div>
