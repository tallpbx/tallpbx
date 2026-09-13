<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\FreeSwitchServiceInterface;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    private ?FreeSwitchServiceInterface $fs = null;

    public bool $fsConnected = false;

    public int $activeCallsCount = 0;

    public bool $isAdmin = false;

    /** @var BaseCollection<int, \Modules\XmlCdr\Models\Cdr> */
    public BaseCollection $recentCdrs;

    public function boot(FreeSwitchServiceInterface $fs = null): void
    {
        $this->fs = $fs;
    }

    public function mount(): void
    {
        $this->isAdmin = Auth::guard('admin')->check();
        $this->refreshMonitoringData();
    }

    #[On('refresh-monitoring')]
    #[On('refresh-calls')]
    #[On('echo:dashboard.monitoring,.DashboardStatsUpdated')]
    public function refreshMonitoringData(): void
    {
        unset($this->totalUsers);
        unset($this->totalTenants);
        unset($this->cardItems);

        $this->isAdmin = Auth::guard('admin')->check();

        if ($this->isAdmin && $this->fs !== null) {
            try {
                $this->fsConnected = $this->fs->isConnected();

                if ($this->fsConnected) {
                    $raw = $this->fs->api('show channels as json');
                    if ($raw === '' || str_starts_with($raw, 'ERROR')) {
                        $raw = $this->fs->api('show channels');
                    }
                    $this->activeCallsCount = $this->countActiveChannels($raw);
                } else {
                    $this->activeCallsCount = 0;
                }
            } catch (\Throwable $e) {
                $this->fsConnected = false;
                $this->activeCallsCount = 0;
            }
        } else {
            $this->fsConnected = false;
            $this->activeCallsCount = 0;
        }

        $this->recentCdrs = $this->loadRecentCdrs();
    }

    private function loadRecentCdrs(): BaseCollection
    {
        if (! $this->isAdmin) {
            return new BaseCollection();
        }

        if (class_exists(\Modules\XmlCdr\Models\Cdr::class)) {
            try {
                return \Modules\XmlCdr\Models\Cdr::withoutGlobalScope('tenant')
                    ->orderBy('start_stamp', 'desc')
                    ->take(5)
                    ->get();
            } catch (\Throwable) {
                return new BaseCollection();
            }
        }

        return new BaseCollection();
    }

    private function countActiveChannels(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }

        // Handle JSON response (e.g. from "show channels as json")
        if (str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);
            if (isset($decoded['row_count'])) {
                return (int) $decoded['row_count'];
            }
            if (isset($decoded['rows']) && is_array($decoded['rows'])) {
                return count($decoded['rows']);
            }
        }

        // Handle plain text response (e.g. from "show channels")
        $count = 0;
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, 'uuid') && ! str_contains($line, 'total.')) {
                $count++;
            }
        }

        return $count;
    }

    #[Computed]
    public function totalUsers(): int
    {
        return User::count();
    }

    #[Computed]
    public function totalTenants(): int
    {
        return Tenant::count();
    }

    #[Computed]
    public function cardItems(): array
    {
        $cards = [
            [
                'title' => __('admin.total_users'),
                'value' => $this->totalUsers,
                'icon' => 'users',
            ],
            [
                'title' => __('admin.total_tenants'),
                'value' => $this->totalTenants,
                'icon' => 'building',
            ],
        ];

        if ($this->isAdmin) {
            $cards[] = [
                'title' => __('admin.fs_connection'),
                'value' => $this->fsConnected ? __('admin.connected') : __('admin.disconnected'),
                'icon' => 'server',
                'badge' => $this->fsConnected ? 'badge-success' : 'badge-error',
            ];
            $cards[] = [
                'title' => __('admin.active_calls'),
                'value' => $this->activeCallsCount,
                'icon' => 'phone',
            ];
        }

        return $cards;
    }
};

?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <h2 class="text-xl font-semibold">{{ __('admin.dashboard_overview') }}</h2>
            @if($isAdmin && $fsConnected)
                <span class="badge badge-success badge-sm gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-current animate-pulse"></span>
                    {{ __('admin.live') }}
                </span>
            @endif
        </div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        @foreach($this->cardItems as $card)
            <div wire:key="dashboard-stat-{{ $card['icon'] }}" class="card bg-base-100 border border-base-300 p-6">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-sm font-medium text-base-content/60">{{ $card['title'] }}</span>
                    @if($card['icon'] === 'users')
                        <x-tooltip :tip="__('admin.total_users_tooltip')">
                            <x-heroicon-o-users class="w-5 h-5 text-primary" />
                        </x-tooltip>
                    @elseif($card['icon'] === 'building')
                        <x-tooltip :tip="__('admin.total_tenants_tooltip')">
                            <x-heroicon-o-building-office-2 class="w-5 h-5 text-success" />
                        </x-tooltip>
                    @elseif($card['icon'] === 'server')
                        <x-tooltip :tip="__('admin.fs_connection_tooltip')">
                            <x-heroicon-o-server-stack class="w-5 h-5 text-info" />
                        </x-tooltip>
                    @elseif($card['icon'] === 'phone')
                        <x-tooltip :tip="__('admin.active_calls_tooltip')">
                            <x-heroicon-o-phone class="w-5 h-5 text-warning" />
                        </x-tooltip>
                    @endif
                </div>
                <div class="text-2xl font-bold">
                    {{ $card['value'] }}
                    @isset($card['badge'])
                        <span class="badge {{ $card['badge'] }} badge-sm ml-2">{{ $card['value'] }}</span>
                    @endisset
                </div>
            </div>
        @endforeach
    </div>

    @if($recentCdrs->isNotEmpty())
        <div class="card bg-base-100 border border-base-300 mt-6">
            <div class="card-body">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold">{{ __('admin.recent_calls') }}</h3>
                    <a href="{{ route('panel.cdr.index') }}" class="btn btn-ghost btn-sm">{{ __('admin.view_all') }}</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>{{ __('admin.cdr_caller') }}</th>
                                <th>{{ __('admin.cdr_destination') }}</th>
                                <th>{{ __('admin.cdr_duration') }}</th>
                                <th>{{ __('admin.cdr_date') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentCdrs as $cdr)
                                <tr wire:key="dashboard-cdr-{{ $cdr->id }}">
                                    <td>
                                        <span class="font-medium">{{ $cdr->caller_id_name }}</span>
                                        <span class="text-xs text-base-content/60 block">{{ $cdr->caller_id }}</span>
                                    </td>
                                    <td><span class="font-mono text-xs">{{ $cdr->destination }}</span></td>
                                    <td>{{ gmdate('i:s', $cdr->duration) }}</td>
                                    <td class="text-xs">{{ $cdr->start_stamp?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
