<?php

declare(strict_types=1);

/**
 * Architecture test: no Blade template across app-modules/ and resources/views/
 * may use arbitrary one-off font sizes (e.g., text-[10px], text-[11px], text-[0.8rem]).
 *
 * All typography must strictly adhere to DaisyUI & Tailwind design token scales (text-xs,
 * text-sm, text-base, text-lg, text-xl, text-2xl, etc.) with semantic opacity utilities
 * (text-base-content/60, opacity-75) per AGENTS.md design token discipline.
 */
it('forbids arbitrary one-off font size classes across all blade views', function (): void {
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

    // Pattern matches arbitrary font size utility classes such as text-[10px], text-[11px], text-[0.75rem], etc.
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
