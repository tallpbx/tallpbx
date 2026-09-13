<?php

declare(strict_types=1);

namespace Modules\ActiveCalls\Livewire;

use App\Services\FreeSwitchControlService;
use App\Services\FreeSwitchServiceInterface;
use App\Services\TenantEslScoping;
use App\Services\TenantManager;
use App\Support\BaseListComponent;
use App\Support\OperationalControlFeedback;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;

class ActiveCallsList extends BaseListComponent
{
    use OperationalControlFeedback;

    public bool $fsConnected = false;

    /** The destination typed for a transfer action. */
    public string $transferDestination = '';

    /** @var list<array{uuid: string, caller_id: string, caller_id_name: string, destination: string, duration: int, state: string, context: string}> */
    public array $calls = [];

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
        $this->refreshCalls();
    }

    #[On('refresh-calls')]
    public function refreshCalls(): void
    {
        $this->fsConnected = $this->fs->isConnected();

        if (! $this->fsConnected) {
            $this->calls = [];

            return;
        }

        try {
            $raw = $this->fs->api('show channels as json');
            $this->calls = $this->scoping->filterChannelsByContext(
                $this->parseChannels($raw),
                $this->isAdminGuard() ? null : $this->tenantId()
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to parse show channels output.', ['error' => $e->getMessage()]);
            $this->calls = [];
            $this->fsConnected = false;
        }
    }

    /**
     * Parse the JSON output of "show channels as json".
     *
     * The response has a header + rows array; rows is absent when the
     * server has no active channels. Duration is the call age derived
     * from created_epoch.
     */
    private function parseChannels(string $raw): array
    {
        $decoded = json_decode($raw, true);
        $calls = [];

        foreach ($decoded['rows'] ?? [] as $row) {
            $calls[] = [
                'uuid' => (string) ($row['uuid'] ?? ''),
                'caller_id' => (string) ($row['cid_num'] ?? ''),
                'caller_id_name' => (string) ($row['cid_name'] ?? ''),
                'destination' => (string) ($row['dest'] ?? ''),
                'duration' => max(0, time() - (int) ($row['created_epoch'] ?? time())),
                'state' => (string) ($row['state'] ?? ''),
                'context' => (string) ($row['context'] ?? ''),
            ];
        }

        return $calls;
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
     * Whether the current user may hang up calls.
     */
    public function getCanHangupProperty(): bool
    {
        return $this->authorizeAction('active-calls.hangup');
    }

    /**
     * Whether the current user may transfer calls.
     */
    public function getCanTransferProperty(): bool
    {
        return $this->authorizeAction('active-calls.transfer');
    }

    /**
     * Hang up a listed call.
     */
    public function hangupCall(string $uuid): void
    {
        $this->actionMessage = null;
        $this->actionError = null;

        if (! $this->canHangup) {
            $this->actionError = 'You do not have permission to hang up calls.';

            return;
        }

        $this->applyResult($this->control->hangup($uuid, array_column($this->calls, 'uuid')));
        $this->refreshCalls();
    }

    /**
     * Transfer a listed call to a destination in its current context.
     */
    public function transferCall(string $uuid, string $destination): void
    {
        $this->actionMessage = null;
        $this->actionError = null;

        if (! $this->canTransfer) {
            $this->actionError = 'You do not have permission to transfer calls.';

            return;
        }

        $this->applyResult($this->control->transfer($uuid, $destination, array_column($this->calls, 'uuid')));
        $this->refreshCalls();
    }
}
