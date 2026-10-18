<?php

declare(strict_types=1);

/**
 * Architecture test: every module edit/create view must use the standardized
 * <x-form-submit-button> component for form submissions with built-in loading
 * spinner and disabled locks, and must normalize cancel links to btn-sm with wire:navigate.
 */
it('enforces standardized x-form-submit-button across all edit and create views', function (): void {
    $projectRoot = base_path();

    $files = array_merge(
        glob($projectRoot.'/app-modules/*/resources/views/*edit*.blade.php') ?: [],
        glob($projectRoot.'/app-modules/*/resources/views/*create*.blade.php') ?: [],
        [
            $projectRoot.'/app-modules/event-rate-limits/resources/views/event-rate-limits-settings.blade.php',
            $projectRoot.'/app-modules/dialplan-tools/resources/views/dialplan-tester.blade.php',
            $projectRoot.'/app-modules/fax/resources/views/fax-send.blade.php',
        ]
    );

    expect(count($files))->toBeGreaterThan(45);

    $violations = [];

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        // Disallow raw <button type="submit" ...> without x-form-submit-button
        if (preg_match('/<button[^>]*type=[\"\']submit[\"\'][^>]*>/i', $content, $match)) {
            $violations[] = "{$relativePath} contains raw submit button: {$match[0]}";
        }

        // Ensure every form view contains <x-form-submit-button
        if (str_contains($content, '<form') && ! str_contains($content, '<x-form-submit-button')) {
            $violations[] = "{$relativePath} has a <form> but does not use <x-form-submit-button>";
        }

        // Cancel links must have standard btn-sm sizing
        if (preg_match_all('/<a[^>]*class=[\"\'][^\"\']*btn-ghost[^\"\']*[\"\'][^>]*>/i', $content, $matches)) {
            foreach ($matches[0] as $cancelTag) {
                if (! str_contains($cancelTag, 'btn-sm') && ! str_contains($cancelTag, 'btn-xs')) {
                    $violations[] = "{$relativePath} contains un-sized cancel link: {$cancelTag}";
                }
                if (! str_contains($cancelTag, 'wire:navigate')) {
                    $violations[] = "{$relativePath} cancel link lacks wire:navigate: {$cancelTag}";
                }
            }
        }
    }

    expect($violations)->toBeEmpty();
});
