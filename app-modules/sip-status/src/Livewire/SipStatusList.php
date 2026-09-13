<?php

declare(strict_types=1);

namespace Modules\SipStatus\Livewire;

use App\Services\FreeSwitchServiceInterface;
use App\Support\BaseListComponent;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;

class SipStatusList extends BaseListComponent
{
    public bool $fsConnected = false;

    public array $profiles = [];

    private FreeSwitchServiceInterface $fs;

    public function boot(FreeSwitchServiceInterface $fs): void
    {
        $this->fs = $fs;
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
