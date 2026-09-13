<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GitUpdateService;
use Illuminate\Console\Command;

/**
 * Executes the TallPBX Git update pipeline from the command line or background runner.
 */
class GitUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:git-update {target : The branch or tag name to update to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull the selected Git branch or tag and run database migrations, build assets, and repair permissions';

    /**
     * Execute the console command.
     */
    public function handle(GitUpdateService $gitService): int
    {
        $target = (string) $this->argument('target');

        $this->info("Starting TallPBX update for target: {$target}");

        $result = $gitService->update($target);

        foreach ($result->steps as $step) {
            $statusTag = $step['status'] === 'ok' ? '<info>✓ OK</info>' : '<error>✗ FAILED</error>';
            $this->line(sprintf('  [%s] %s', $statusTag, $step['label']));

            if ($step['output'] !== '') {
                $this->line('    '.str_replace("\n", "\n    ", trim($step['output'])));
            }
        }

        if ($result->success) {
            $this->info("Successfully updated TallPBX to target: {$target}");

            return self::SUCCESS;
        }

        $this->error("Update failed: {$result->reason}");

        if ($result->rollbackReport !== null) {
            $this->warn("Rollback report: {$result->rollbackReport}");
        }

        return self::FAILURE;
    }
}
