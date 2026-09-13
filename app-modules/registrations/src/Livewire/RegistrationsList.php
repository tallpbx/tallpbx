<?php

declare(strict_types=1);

namespace Modules\Registrations\Livewire;

use App\Services\FreeSwitchServiceInterface;
use App\Services\TenantEslScoping;
use App\Services\TenantManager;
use App\Support\BaseListComponent;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;

class RegistrationsList extends BaseListComponent
{
    public bool $fsConnected = false;

    public array $registrations = [];

    private FreeSwitchServiceInterface $fs;

    private TenantEslScoping $scoping;

    public function boot(FreeSwitchServiceInterface $fs, TenantEslScoping $scoping): void
    {
        $this->fs = $fs;
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
            $this->registrations = [];

            return;
        }

        try {
            $raw = $this->fs->api('sofia status profile internal reg');
            $this->registrations = $this->scoping->filterRegistrationsByTenant(
                $this->parseRegistrations($raw),
                $this->isAdminGuard() ? null : $this->tenantId()
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to parse registrations output.', ['error' => $e->getMessage()]);
            $this->registrations = [];
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
     * Parse "sofia status profile internal reg" rows.
     *
     * Real row layout: user@domain;user@domain;contact;expires;0;1;status
     */
    private function parseRegistrations(string $raw): array
    {
        $lines = explode("\n", trim($raw));
        $regs = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_contains($line, 'Registrations') || str_contains($line, 'Total items')) {
                continue;
            }
            $parts = explode(';', $line);
            if (count($parts) >= 7) {
                $regs[] = [
                    'user' => $parts[0],
                    'contact' => $parts[2],
                    'status' => $parts[6],
                    'expires' => $parts[3],
                ];
            }
        }

        return $regs;
    }
}
