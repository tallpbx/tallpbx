<?php

declare(strict_types=1);

namespace Modules\OperatorPanel\Livewire;

use App\Services\DialplanContext;
use App\Services\FreeSwitchControlService;
use App\Services\FreeSwitchServiceInterface;
use App\Services\ImpersonationServiceInterface;
use App\Services\TenantContext;
use App\Services\TenantEslScoping;
use App\Services\TenantManager;
use App\Support\OperationalControlFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Extensions\Models\Extension;

#[Layout('layouts.app')]
class OperatorPanelIndex extends Component
{
    use OperationalControlFeedback;

    public bool $fsConnected = false;

    /** @var Collection<int, Extension> */
    public Collection $extensions;

    /** The extension selected for click-to-call. */
    public string $selectedExtension = '';

    /** The extension the operator originates calls from. */
    public string $sourceExtension = '';

    /** @var array<string, array{uuid: string, state: string}> */
    public array $activeCalls = [];

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
        $this->fsConnected = $this->fs->isConnected();
        $this->extensions = Extension::withoutGlobalScope('tenant')
            ->when(! $this->isAdminGuard(), fn ($q) => $q->where('tenant_id', $this->tenantId()))
            ->where('enabled', true)
            ->orderBy('extension_number')
            ->get();
        $this->loadActiveCalls();
    }

    #[On('refresh')]
    public function refresh(): void
    {
        $this->fsConnected = $this->fs->isConnected();
        $this->loadActiveCalls();
    }

    /**
     * Load active calls from FreeSWITCH ESL with error resilience.
     *
     * Malformed API output must not crash the operator panel.
     * On parse failure, activeCalls is reset to an empty array
     * and the error is logged for diagnostics.
     */
    private function loadActiveCalls(): void
    {
        if (! $this->fsConnected) {
            $this->activeCalls = [];

            return;
        }

        try {
            $raw = $this->fs->api('show channels as json');
            $rows = $this->scoping->filterChannelsByContext(
                $this->parseActiveExtensions($raw),
                $this->isAdminGuard() ? null : $this->tenantId()
            );

            $this->activeCalls = [];

            foreach ($rows as $row) {
                if ($row['cid_num'] !== '') {
                    $this->activeCalls[$row['cid_num']] = ['uuid' => $row['uuid'], 'state' => $row['state']];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to parse show channels output in operator panel.', ['error' => $e->getMessage()]);
            $this->activeCalls = [];
            $this->fsConnected = false;
        }
    }

    /**
     * Parse the JSON output of "show channels as json" into a row list.
     */
    private function parseActiveExtensions(string $raw): array
    {
        $decoded = json_decode($raw, true);
        $rows = [];

        foreach ($decoded['rows'] ?? [] as $row) {
            $rows[] = [
                'uuid' => (string) ($row['uuid'] ?? ''),
                'state' => (string) ($row['state'] ?? ''),
                'context' => (string) ($row['context'] ?? ''),
                'cid_num' => (string) ($row['cid_num'] ?? ''),
            ];
        }

        return $rows;
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
     * Whether the current user is an admin (tenant users are scoped).
     */
    private function isAdminGuard(): bool
    {
        return Auth::guard('admin')->check()
            && ! app(ImpersonationServiceInterface::class)->isImpersonating();
    }

    /**
     * Whether the current user may originate calls.
     */
    public function getCanOriginateProperty(): bool
    {
        return $this->authorizeAction('operator-panel.originate');
    }

    /**
     * Whether the current user may hang up calls.
     */
    public function getCanHangupProperty(): bool
    {
        return $this->authorizeAction('operator-panel.hangup');
    }

    /**
     * Originate a call from the source extension to the selected extension.
     *
     * The internal dialplan context is resolved from the current tenant
     * (session, tenant user's default, or single-tenant fallback).
     */
    public function originateCall(): void
    {
        $this->actionMessage = null;
        $this->actionError = null;

        if (! $this->canOriginate) {
            $this->actionError = 'You do not have permission to originate calls.';

            return;
        }

        $tenant = app(TenantContext::class)->current();

        if ($tenant === null) {
            $this->actionError = 'No tenant context is available to originate the call.';

            return;
        }

        $context = app(DialplanContext::class)->internal((string) $tenant->id);
        $this->applyResult($this->control->originate($this->sourceExtension, $this->selectedExtension, $context));
    }

    /**
     * Hang up an active call shown on the panel.
     */
    public function hangupCall(string $uuid): void
    {
        $this->actionMessage = null;
        $this->actionError = null;

        if (! $this->canHangup) {
            $this->actionError = 'You do not have permission to hang up calls.';

            return;
        }

        $this->applyResult($this->control->hangup($uuid, array_column($this->activeCalls, 'uuid')));
        $this->refresh();
    }

    public function render(): View
    {
        return view('operator-panel::operator-panel-index');
    }
}
