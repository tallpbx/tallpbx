<?php

declare(strict_types=1);

/**
 * Architecture test: every DaisyUI toggle switch across app-modules/ and resources/views/
 * must specify an explicit size token (standard `toggle-sm` or compact table `toggle-xs`)
 * and must enforce mutation locks (`wire:loading.attr="disabled"`) to prevent double-submit
 * and state-desync race conditions per AGENTS.md and tallpbx-custom standards.
 */
it('enforces standardized sizing and loading locks on all toggle switches', function (): void {
    $projectRoot = base_path();

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot.'/app-modules')
    );

    $files = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    $resourceIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot.'/resources/views')
    );
    foreach ($resourceIterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    expect(count($files))->toBeGreaterThan(100);

    $violations = [];
    $scannedToggles = 0;

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        // Match <input ...> tags handling potential '->' in Blade expressions before the closing '>' or '/>'
        if (preg_match_all('/<input\b.*?(?<!-)\/?>/is', $content, $matches)) {
            foreach ($matches[0] as $tag) {
                if (! preg_match('/class=[\"\']([^\"\']*)[\"\']/is', $tag, $classMatch)) {
                    continue;
                }

                $classes = $classMatch[1];

                // Match DaisyUI toggle component: base 'toggle' class not preceded by a dash (excludes 'drawer-toggle')
                if (! preg_match('/(?<![a-zA-Z0-9_-])toggle(?=[\s\"\']|$)/', $classes)) {
                    continue;
                }

                $scannedToggles++;

                // 1. Must specify explicit sizing (standard toggle-sm or compact toggle-xs)
                if (! preg_match('/\btoggle-(?:xs|sm|md|lg)\b/', $classes)) {
                    $violations[] = "{$relativePath}: toggle switch missing explicit size (expected 'toggle-sm') -> {$tag}";
                }

                // 2. Must enforce wire:loading.attr="disabled"
                if (! str_contains($tag, 'wire:loading.attr="disabled"') && ! str_contains($tag, "wire:loading.attr='disabled'")) {
                    $violations[] = "{$relativePath}: toggle switch missing 'wire:loading.attr=\"disabled\"' lock -> {$tag}";
                }
            }
        }
    }

    // Ensure we actually scanned all toggle switches across the codebase
    expect($scannedToggles)->toBeGreaterThanOrEqual(15)
        ->and($violations)->toBeEmpty(
            'All toggle switches must specify an explicit size token (e.g. toggle-sm) and wire:loading.attr="disabled".'
        );
});
