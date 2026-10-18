<?php

declare(strict_types=1);

/**
 * Architecture test: every table header cell (<th>) across app-modules/ and resources/views/
 * must be localized via translation helpers (__(), @lang(), trans()) or dynamic expressions,
 * preventing hardcoded English strings in table headers.
 */
it('enforces that all table header cells across app-modules and resources/views are localized', function (): void {
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

                // Remove Blade comments {{-- ... --}}
                $innerClean = preg_replace('/\{\{--.*?--\}\}/s', '', $inner);
                // Strip HTML tags to evaluate text payload
                $plainText = trim(strip_tags((string) $innerClean));

                // Check if the cell contains letters (is not purely whitespace, icons, or checkboxes)
                if (preg_match('/[a-zA-Z]/', $plainText)) {
                    $scannedHeaders++;

                    // Check if it contains translation directives or variable interpolation
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
