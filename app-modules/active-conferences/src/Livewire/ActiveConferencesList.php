<?php

declare(strict_types=1);

namespace Modules\ActiveConferences\Livewire;

use App\Services\FreeSwitchControlService;
use App\Services\FreeSwitchServiceInterface;
use App\Services\TenantEslScoping;
use App\Services\TenantManager;
use App\Support\BaseListComponent;
use App\Support\OperationalControlFeedback;
use App\Support\PanelActor;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;

/**
 * Livewire component listing active conferences and offering mute, unmute,
 * and kick actions for permitted users.
 */
class ActiveConferencesList extends BaseListComponent
{
    use OperationalControlFeedback;

    public bool $fsConnected = false;

    public string $tenantId = 'global';

    /** @var list<array{name: string, members: int, status: string, members_list: list<array{id: string, uuid: string, caller_id: string}>}> */
    public array $conferences = [];

    private FreeSwitchServiceInterface $fs;

    private FreeSwitchControlService $control;

    private TenantEslScoping $scoping;

    /**
     * Resolve the FreeSWITCH session, its control service, and the
     * tenant scoper used by this component.
     */
    public function boot(FreeSwitchServiceInterface $fs, FreeSwitchControlService $control, TenantEslScoping $scoping): void
    {
        $this->fs = $fs;
        $this->control = $control;
        $this->scoping = $scoping;
    }

    /**
     * Load the active conference list when the page opens.
     */
    public function mount(): void
    {
        $id = $this->tenantId();
        $this->tenantId = $id !== null ? (string) $id : 'global';
        $this->refresh();
    }

    /**
     * Fetch the current conferences from FreeSWITCH, keep only the calling
     * tenant's conferences, and refresh the list.
     */
    #[On('refresh')]
    #[On('echo-private:tenant.{tenantId}.telephony,.TelephonyUpdated')]
    #[On('echo:dashboard.monitoring,.DashboardStatsUpdated')]
    public function refresh(): void
    {
        $this->fsConnected = $this->fs->isConnected();
        if (! $this->fsConnected) {
            $this->conferences = [];

            return;
        }

        try {
            $raw = $this->fs->api('conference list');
            $tenantId = ($this->isAdminGuard() && ! app(TenantManager::class)->hasTenant())
                ? null
                : $this->tenantId();

            $this->conferences = $this->scoping->filterConferencesByTenant(
                $this->parseConferences($raw),
                $tenantId
            );
        } catch (\Throwable $e) {
            Log::error('Failed to parse conference list output.', ['error' => $e->getMessage()]);
            $this->conferences = [];
        }
    }

    /**
     * Resynchronize active conferences state after an Echo WebSocket reconnection event.
     */
    #[On('echo-reconnected')]
    public function resyncAfterReconnect(): void
    {
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
     * Parse the plain-text "conference list" output into conference rows
     * with their member lines.
     */
    private function parseConferences(string $raw): array
    {
        $lines = explode("\n", trim($raw));
        $confs = [];
        $current = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // A conference header starts a new block; member lines below it
            // use FreeSWITCH's member format: id;uuid;caller_id;conf;flags...
            if (preg_match('/^Conference\s+(\S+)\s+\(members:\s+(\d+)\)\s+(\w+)/i', $line, $m)) {
                $confs[] = ['name' => $m[1], 'members' => (int) $m[2], 'status' => $m[3], 'members_list' => []];
                // Track the block by index so later member lines attach to it
                // (references would clobber earlier blocks on the next header).
                $current = count($confs) - 1;

                continue;
            }

            if ($current !== null && preg_match('/^(\d+);([0-9a-f-]+);([^;]+);/', $line, $m)) {
                $confs[$current]['members_list'][] = ['id' => $m[1], 'uuid' => $m[2], 'caller_id' => $m[3]];
            }
        }

        return $confs;
    }

    /**
     * Whether the current user may mute conference members.
     */
    public function getCanMuteProperty(): bool
    {
        return $this->authorizeAction('active-conferences.mute');
    }

    /**
     * Whether the current user may kick conference members.
     */
    public function getCanKickProperty(): bool
    {
        return $this->authorizeAction('active-conferences.kick');
    }

    /**
     * Compute allowed conference names server-side from fresh ESL state.
     *
     * Returns null for unscoped admin callers without an active tenant filter.
     *
     * @return array<int, string>|null
     */
    public function allowedConferenceNames(): ?array
    {
        $tenantScoped = ! PanelActor::isAdmin() || app(TenantManager::class)->hasTenant();

        if (! $tenantScoped) {
            return null;
        }

        if (! $this->fs->isConnected()) {
            return [];
        }

        try {
            $raw = $this->fs->api('conference list');
            $scoped = $this->scoping->filterConferencesByTenant(
                $this->parseConferences($raw),
                $this->tenantId()
            );

            return array_values(array_filter(array_column($scoped, 'name')));
        } catch (\Throwable $e) {
            Log::error('Failed to resolve allowed conferences from ESL.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Mute a conference member.
     */
    public function muteMember(string $conference, string $memberId): void
    {
        $this->actionMessage = null;
        $this->actionError = null;

        if (! $this->canMute) {
            $this->actionError = 'You do not have permission to mute conference members.';

            return;
        }

        $this->applyResult($this->control->conferenceMute($conference, $memberId, true, $this->allowedConferenceNames()));
        $this->refresh();
    }

    /**
     * Unmute a conference member.
     */
    public function unmuteMember(string $conference, string $memberId): void
    {
        $this->actionMessage = null;
        $this->actionError = null;

        if (! $this->canMute) {
            $this->actionError = 'You do not have permission to mute conference members.';

            return;
        }

        $this->applyResult($this->control->conferenceMute($conference, $memberId, false, $this->allowedConferenceNames()));
        $this->refresh();
    }

    /**
     * Kick a member from a conference.
     */
    public function kickMember(string $conference, string $memberId): void
    {
        $this->actionMessage = null;
        $this->actionError = null;

        if (! $this->canKick) {
            $this->actionError = 'You do not have permission to kick conference members.';

            return;
        }

        $this->applyResult($this->control->conferenceKick($conference, $memberId, $this->allowedConferenceNames()));
        $this->refresh();
    }
}
