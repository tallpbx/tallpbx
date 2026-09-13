<?php

declare(strict_types=1);

namespace Modules\CallCenterActive\Livewire;

use App\Services\FreeSwitchControlService;
use App\Services\FreeSwitchServiceInterface;
use App\Services\TenantEslScoping;
use App\Services\TenantManager;
use App\Support\BaseListComponent;
use App\Support\OperationalControlFeedback;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;

class CallCenterActiveList extends BaseListComponent
{
    use OperationalControlFeedback;

    public bool $fsConnected = false;

    /** @var list<array{name: string, strategy: string}> */
    public array $queues = [];

    /** @var list<array{agent: string, status: string}> */
    public array $agents = [];

    private FreeSwitchServiceInterface $fs;

    private FreeSwitchControlService $control;

    private TenantEslScoping $scoping;

    public function boot(FreeSwitchServiceInterface $fs, FreeSwitchControlService $control, TenantEslScoping $scoping): void
    {
        $this->fs = $fs;
        $this->control = $control;
        $this->scoping = $scoping;
    }

    public function mount(): void
    {
        $this->refresh();
    }

    #[On('refresh')]
    public function refresh(): void
    {
        $this->fsConnected = $this->fs->isConnected();
        if (! $this->fsConnected) {
            $this->queues = [];
            $this->agents = [];

            return;
        }
        try {
            $this->queues = $this->parseQueues($this->fs->api('callcenter_config queue list'));
            $this->agents = $this->parseAgents($this->fs->api('callcenter_config agent list'));

            $tenantId = $this->isAdminGuard() ? null : $this->tenantId();
            $this->queues = $this->scoping->filterQueuesByTenant($this->queues, $tenantId);
            $this->agents = $this->scoping->filterAgentsByTenant($this->agents, $tenantId);
        } catch (\Throwable $e) {
            Log::warning('Failed to parse callcenter output.', ['error' => $e->getMessage()]);
            $this->queues = [];
            $this->agents = [];
            $this->fsConnected = false;
        }
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
     * Parse the pipe-delimited queue list, skipping the header row.
     *
     * The real command output starts with a column header and may end
     * with a bare +OK line; both are ignored.
     */
    private function parseQueues(string $raw): array
    {
        $lines = explode("\n", trim($raw));
        $queues = [];
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || $index === 0) {
                continue;
            }
            $parts = explode('|', $line);
            if (count($parts) >= 2) {
                $queues[] = ['name' => $parts[0], 'strategy' => $parts[1]];
            }
        }

        return $queues;
    }

    /**
     * Parse the pipe-delimited agent list, skipping the header row.
     *
     * Real rows carry four columns (agent|status|state|last_state_change);
     * only the agent id and status are kept.
     */
    private function parseAgents(string $raw): array
    {
        $lines = explode("\n", trim($raw));
        $agents = [];
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || $index === 0) {
                continue;
            }
            $parts = explode('|', $line, 3);
            if (count($parts) >= 2) {
                $agents[] = ['agent' => $parts[0], 'status' => $parts[1]];
            }
        }

        return $agents;
    }

    /**
     * Whether the current user may control call center agents.
     */
    public function getCanControlProperty(): bool
    {
        return $this->authorizeAction('call-center-active.control');
    }

    /**
     * Pause a call center agent.
     */
    public function pauseAgent(string $agent): void
    {
        $this->actionMessage = null;
        $this->actionError = null;

        if (! $this->canControl) {
            $this->actionError = 'You do not have permission to control call center agents.';

            return;
        }

        $this->applyResult($this->control->agentPause($agent));
        $this->refresh();
    }

    /**
     * Unpause a call center agent.
     */
    public function unpauseAgent(string $agent): void
    {
        $this->actionMessage = null;
        $this->actionError = null;

        if (! $this->canControl) {
            $this->actionError = 'You do not have permission to control call center agents.';

            return;
        }

        $this->applyResult($this->control->agentUnpause($agent));
        $this->refresh();
    }

    /**
     * Log out a call center agent.
     */
    public function logoutAgent(string $agent): void
    {
        $this->actionMessage = null;
        $this->actionError = null;

        if (! $this->canControl) {
            $this->actionError = 'You do not have permission to control call center agents.';

            return;
        }

        $this->applyResult($this->control->agentLogout($agent));
        $this->refresh();
    }
}
