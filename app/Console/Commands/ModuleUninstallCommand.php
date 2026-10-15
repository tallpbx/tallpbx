<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ModuleLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Permanently uninstall a custom extension or vendor module.
 *
 * Built-in core modules are refused — they are permanent monorepo code and
 * are disabled with the panel toggle instead. Custom extensions are deleted
 * from custom-modules/ (irreversible; nothing can restore them), and vendor
 * modules are removed through Composer, keeping a registry marker so
 * module:restore can re-require them later.
 */
class ModuleUninstallCommand extends Command
{
    protected $signature = 'module:uninstall
        {name : Module name in kebab-case (e.g., "my-extension")}
        {--confirm= : Exact confirmation phrase (skips the interactive prompt)}';

    protected $description = 'Permanently uninstall a custom extension or vendor module';

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

        // Never present a partial cleanup as a clean uninstall: tell the
        // operator exactly what still needs manual attention.
        $cleanupIncomplete = $report['module_kind'] === 'custom' && ! $report['module_dir_deleted'];

        if ($cleanupIncomplete) {
            $this->components->warn("Uninstalled module [{$name}], but some cleanup steps need manual attention — review the warnings below.");
        } else {
            $this->components->info("Uninstalled module [{$name}].");
        }

        if ($report['restore_hint'] !== '') {
            $this->components->info($report['restore_hint']);
        }

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
                : "Custom extension directory: {$preview['module_dir']} (irreversible — custom extensions cannot be restored)",
            "Permissions: {$preview['permission_count']}",
            $preview['registry_row']
                ? ($isVendor ? 'Module registry row (kept as a restore marker)' : 'Module registry row (deleted with the extension)')
                : 'Module registry row: none',
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
