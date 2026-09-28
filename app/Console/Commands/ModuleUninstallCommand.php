<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ModuleLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Permanently uninstall a module from the installation.
 *
 * Deletes the module's files, Composer entries, central test folder,
 * permissions, and data (through its uninstall handler when one exists),
 * keeping a registry marker so module:restore can bring it back.
 */
class ModuleUninstallCommand extends Command
{
    protected $signature = 'module:uninstall
        {name : Module name in kebab-case (e.g., "call-broadcast")}
        {--confirm= : Exact confirmation phrase (skips the interactive prompt)}';

    protected $description = 'Permanently uninstall a module (restorable via module:restore)';

    /**
     * Execute the console command.
     *
     * Prints a removal preview, requires the exact confirmation phrase,
     * then hands the destructive work to ModuleLifecycleService.
     */
    public function handle(ModuleLifecycleService $lifecycle): int
    {
        $name = (string) $this->argument('name');
        $preview = $lifecycle->previewUninstall($name);

        if (! $preview['can_uninstall']) {
            $this->components->error($preview['reason'] ?? 'This module cannot be uninstalled.');

            return self::FAILURE;
        }

        $this->printPreview($preview);

        $confirmation = $this->resolveConfirmation($lifecycle, $name);

        if ($confirmation === null) {
            $this->components->error('Uninstall cancelled.');

            return self::FAILURE;
        }

        try {
            $report = $lifecycle->uninstall($name, $confirmation);
        } catch (ValidationException $exception) {
            $this->components->error(collect($exception->errors())->flatten()->first() ?? 'The module could not be uninstalled.');

            return self::FAILURE;
        }

        $this->components->info("Uninstalled module [{$name}].");
        $this->components->info($report['restore_hint']);
        $this->components->warn('The module files are now deleted in the working tree, which the Git updater treats as uncommitted changes. Use module:restore to undo, or commit/stash the deletions before updating.');

        foreach ($report['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
    }

    /**
     * Print the human-readable preview of everything that will be deleted.
     *
     * @param  array<string, mixed>  $preview
     */
    private function printPreview(array $preview): void
    {
        $isVendor = $preview['module_kind'] === 'vendor';

        $this->components->warn('This will permanently delete:');
        $this->components->bulletList([
            $isVendor
                ? "Vendor package: {$preview['vendor_package']} (removed via Composer)"
                : "Module directory: {$preview['module_dir']}",
            ! $isVendor
                ? 'Module tests (inside the module directory): included in the deletion'
                : 'Central tests: none',
            ! $isVendor && $preview['repository_entry']
                ? 'Composer path-repository entry'
                : 'Composer path-repository entry: none',
            "Composer package: {$preview['composer_package']}",
            $preview['registry_row'] ? 'Module registry row (kept as a restore marker)' : 'Module registry row: none',
            "Permissions: {$preview['permission_count']}",
        ]);

        foreach ($preview['uninstall_items'] as $item) {
            $this->line('  - '.$item);
        }

        foreach ($preview['warnings'] as $warning) {
            $this->components->warn($warning);
        }
    }

    /**
     * Get the confirmation phrase from the option, or ask interactively.
     */
    private function resolveConfirmation(ModuleLifecycleService $lifecycle, string $name): ?string
    {
        $phrase = $lifecycle->confirmationPhrase($name);
        $provided = $this->option('confirm');

        if (is_string($provided) && $provided !== '') {
            return $provided;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error("Run with --confirm=\"{$phrase}\" to proceed non-interactively.");

            return null;
        }

        $answer = $this->ask("Type \"{$phrase}\" to confirm permanent uninstall");

        return is_string($answer) ? $answer : null;
    }
}
