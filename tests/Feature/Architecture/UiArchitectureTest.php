<?php

declare(strict_types=1);

/**
 * Architecture tests enforcing user interface conventions, design token discipline,
 * typography hierarchy, localization, and component standards across all Blade views.
 */
it('forbids arbitrary one-off font size classes across all blade views', function (): void {
    $projectRoot = base_path();

    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    expect(count($files))->toBeGreaterThan(100);

    $violations = [];
    $pattern = '/\btext-\[(?:\d+(?:\.\d+)?(?:px|rem|em|pt))\]/';

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        $lines = explode("\n", $content);
        foreach ($lines as $index => $line) {
            if (preg_match_all($pattern, $line, $matches)) {
                $lineNum = $index + 1;
                foreach ($matches[0] as $match) {
                    $violations[] = "{$relativePath}:{$lineNum} -> {$match} found in '{$line}'";
                }
            }
        }
    }

    expect($violations)->toBeEmpty(
        'Arbitrary font size classes violate design token discipline in AGENTS.md. Normalize to standard text-xs with semantic opacity.'
    );
});

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

        if (preg_match('/<button[^>]*type=[\"\']submit[\"\'][^>]*>/i', $content, $match)) {
            $violations[] = "{$relativePath} contains raw submit button: {$match[0]}";
        }

        if (str_contains($content, '<form') && ! str_contains($content, '<x-form-submit-button')) {
            $violations[] = "{$relativePath} has a <form> but does not use <x-form-submit-button>";
        }

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

it('enforces standardized page-title typography across all module and panel views', function (): void {
    $projectRoot = base_path();

    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    expect(count($files))->toBeGreaterThan(100);

    $violations = [];
    $matchedHeadings = 0;

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (preg_match_all('/<h[12]\b[^>]*class=[\"\']([^\"\']*)[\"\'][^>]*>/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullTag = $match[0];
                $classes = $match[1];

                if (str_contains($classes, 'text-2xl')) {
                    $matchedHeadings++;

                    if (! str_contains($classes, 'font-bold')) {
                        $violations[] = "{$relativePath}: heading missing 'font-bold' -> {$fullTag}";
                    }
                    if (! str_contains($classes, 'tracking-tight')) {
                        $violations[] = "{$relativePath}: heading missing 'tracking-tight' -> {$fullTag}";
                    }
                    if (! str_contains($classes, 'text-base-content')) {
                        $violations[] = "{$relativePath}: heading missing 'text-base-content' -> {$fullTag}";
                    }
                    if (str_contains($classes, 'font-semibold')) {
                        $violations[] = "{$relativePath}: heading contains non-standard 'font-semibold' -> {$fullTag}";
                    }
                }
            }
        }
    }

    expect($matchedHeadings)->toBeGreaterThanOrEqual(125)
        ->and($violations)->toBeEmpty();
});

it('enforces that all table header cells across app-modules and resources/views are localized', function (): void {
    $projectRoot = base_path();

    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    expect(count($files))->toBeGreaterThan(100);

    $thRegex = '/<th\b[^>]*>(.*?)<\/th>/is';
    $violations = [];
    $scannedHeaders = 0;

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (preg_match_all($thRegex, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullTag = trim($match[0]);
                $inner = $match[1];

                $innerClean = preg_replace('/\{\{--.*?--\}\}/s', '', $inner);
                $plainText = trim(strip_tags((string) $innerClean));

                if (preg_match('/[a-zA-Z]/', $plainText)) {
                    $scannedHeaders++;

                    $isLocalized = (bool) preg_match('/__\(|@lang|trans\(|\{\{\s*\$/', (string) $innerClean);

                    if (! $isLocalized) {
                        $violations[] = "{$relativePath}: unlocalized table header '{$plainText}' in {$fullTag}";
                    }
                }
            }
        }
    }

    expect($scannedHeaders)->toBeGreaterThan(200)
        ->and($violations)->toBeEmpty('Unlocalized table headers found: '.implode("\n", $violations));
});

it('enforces standardized sizing and loading locks on all toggle switches', function (): void {
    $projectRoot = base_path();

    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
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

        if (preg_match_all('/<input\b.*?(?<!-)\/?>/is', $content, $matches)) {
            foreach ($matches[0] as $tag) {
                if (! preg_match('/class=[\"\']([^\"\']*)[\"\']/is', $tag, $classMatch)) {
                    continue;
                }

                $classes = $classMatch[1];

                if (! preg_match('/(?<![a-zA-Z0-9_-])toggle(?=[\s\"\']|$)/', $classes)) {
                    continue;
                }

                $scannedToggles++;

                if (! preg_match('/\btoggle-(?:xs|sm|md|lg)\b/', $classes)) {
                    $violations[] = "{$relativePath}: toggle switch missing explicit size (expected 'toggle-sm') -> {$tag}";
                }

                if (! str_contains($tag, 'wire:loading.attr="disabled"') && ! str_contains($tag, "wire:loading.attr='disabled'")) {
                    $violations[] = "{$relativePath}: toggle switch missing 'wire:loading.attr=\"disabled\"' lock -> {$tag}";
                }
            }
        }
    }

    expect($scannedToggles)->toBeGreaterThanOrEqual(15)
        ->and($violations)->toBeEmpty(
            'All toggle switches must specify an explicit size token (e.g. toggle-sm) and wire:loading.attr="disabled".'
        );
});
