<?php

declare(strict_types=1);

namespace Modules\SipStatus\Livewire;

use App\Services\FreeSwitchServiceInterface;
use App\Services\TenantManager;
use App\Support\BaseListComponent;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;

/**
 * Livewire component listing Sofia SIP profile statuses.
 */
class SipStatusList extends BaseListComponent
{
    public bool $fsConnected = false;

    public string $tenantId = 'global';

    public array $profiles = [];

    private FreeSwitchServiceInterface $fs;

    /**
     * Inject the FreeSWITCH session used by this component.
     */
    public function boot(FreeSwitchServiceInterface $fs): void
    {
        $this->fs = $fs;
    }

    /**
     * Load the SIP profile statuses when the page opens.
     */
    public function mount(): void
    {
        $id = $this->tenantId();
        $this->tenantId = $id !== null ? (string) $id : 'global';
        $this->refresh();
    }

    /**
     * The current tenant id for tenant users, null for admins.
     */
    private function tenantId(): ?int
    {
        $id = app(TenantManager::class)->getTenantId();

        return $id !== null ? (int) $id : null;
    }

    /**
     * Fetch the Sofia profile statuses from FreeSWITCH and refresh
     * the list.
     */
    #[On('refresh')]
    #[On('echo-private:tenant.{tenantId}.telephony,.TelephonyUpdated')]
    #[On('echo:dashboard.monitoring,.DashboardStatsUpdated')]
    public function refresh(): void
    {
        $this->fsConnected = $this->fs->isConnected();
        if (! $this->fsConnected) {
            $this->profiles = [];

            return;
        }

        try {
            $raw = $this->fs->api('sofia status');
            $this->profiles = $this->parseProfiles($raw);
        } catch (\Throwable $e) {
            Log::warning('Failed to parse sofia status output.', ['error' => $e->getMessage()]);
            $this->profiles = [];
            $this->fsConnected = false;
        }
    }

    /**
     * Parse the plain-text "sofia status" output into profile rows.
     */
    private function parseProfiles(string $raw): array
    {
        $lines = explode("\n", trim($raw));
        $profiles = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_contains($line, 'Name')) {
                continue;
            }
            if (preg_match('/^(\S+)\s+(RUNNING|ERROR|DOWN)/', $line, $m)) {
                $profiles[] = ['name' => $m[1], 'status' => $m[2]];
            }
        }

        return $profiles;
    }
}
