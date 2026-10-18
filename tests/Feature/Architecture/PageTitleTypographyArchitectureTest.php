<?php

declare(strict_types=1);

/**
 * Architecture test: every module and panel page-title heading (<h1> / <h2> with text-2xl)
 * across app-modules/ and resources/views/ must adhere to the design standard established
 * in AGENTS.md: `text-2xl font-bold tracking-tight text-base-content`.
 */
it('enforces standardized page-title typography across all module and panel views', function (): void {
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
    $matchedHeadings = 0;

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        // Find all <h1 and <h2 tags
        if (preg_match_all('/<h[12]\b[^>]*class=[\"\']([^\"\']*)[\"\'][^>]*>/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullTag = $match[0];
                $classes = $match[1];

                // Page-title headings use text-2xl
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

    // Ensure we actually scanned and matched all page titles
    expect($matchedHeadings)->toBeGreaterThanOrEqual(125)
        ->and($violations)->toBeEmpty();
});
