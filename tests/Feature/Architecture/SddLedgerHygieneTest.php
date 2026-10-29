<?php

declare(strict_types=1);

/**
 * Architecture tests enforcing SDD ledger hygiene and consistency.
 */
beforeEach(function (): void {
    if (! is_dir(base_path('.superpowers/sdd'))) {
        $this->markTestSkipped('No local .superpowers/sdd ledger directory present.');
    }
});

it('ensures .superpowers/sdd contains no empty subdirectories', function (): void {
    $sddPath = base_path('.superpowers/sdd');
    $subdirs = glob($sddPath.'/*', GLOB_ONLYDIR) ?: [];

    $emptyDirs = [];
    foreach ($subdirs as $dir) {
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        if (count($files) === 0) {
            $emptyDirs[] = basename($dir);
        }
    }

    expect($emptyDirs)->toBeEmpty(
        '.superpowers/sdd must contain no empty subdirectories: '.implode(', ', $emptyDirs)
    );
});

it('ensures referenced plans exist on disk or ledgers are explicitly marked archived', function (): void {
    $sddPath = base_path('.superpowers/sdd');
    $subdirs = glob($sddPath.'/*', GLOB_ONLYDIR) ?: [];

    $unarchivedMissing = [];

    foreach ($subdirs as $dir) {
        $progressFile = $dir.'/progress.md';
        if (! file_exists($progressFile)) {
            continue;
        }

        $lines = file($progressFile, FILE_IGNORE_NEW_LINES) ?: [];
        if (empty($lines)) {
            continue;
        }

        $firstLine = trim($lines[0]);
        if (preg_match('/^# SDD ledger — plan:\s*(\S+)/', $firstLine, $matches)) {
            $planPath = $matches[1];
            $fullPlanPath = base_path($planPath);

            if (! file_exists($fullPlanPath)) {
                $first10 = array_slice($lines, 0, 10);
                $isArchived = false;
                foreach ($first10 as $line) {
                    if (str_contains($line, '> **Archived:**')) {
                        $isArchived = true;
                        break;
                    }
                }

                if (! $isArchived) {
                    $unarchivedMissing[] = basename($dir)." references non-existent plan '{$planPath}' without '> **Archived:**' header";
                }
            }
        }
    }

    expect($unarchivedMissing)->toBeEmpty(
        'Ledgers whose source plans no longer exist must have an explicit archive annotation: '.implode("\n", $unarchivedMissing)
    );
});

it('ensures .superpowers/sdd/progress.md indexes all archived ledgers without phantom entries', function (): void {
    $sddPath = base_path('.superpowers/sdd');
    $indexFile = $sddPath.'/progress.md';

    expect(file_exists($indexFile))->toBeTrue('.superpowers/sdd/progress.md must exist');

    $indexContent = (string) file_get_contents($indexFile);

    $subdirs = glob($sddPath.'/*', GLOB_ONLYDIR) ?: [];

    foreach ($subdirs as $dir) {
        $progressFile = $dir.'/progress.md';
        if (! file_exists($progressFile)) {
            continue;
        }

        $lines = file($progressFile, FILE_IGNORE_NEW_LINES) ?: [];
        if (empty($lines)) {
            continue;
        }

        $firstLine = trim($lines[0]);
        if (preg_match('/^# SDD ledger — plan:\s*(\S+)/', $firstLine, $matches)) {
            $planPath = $matches[1];
            $fullPlanPath = base_path($planPath);

            // Active in-flight SDD workspaces whose plan file exists on disk are exempt
            if (file_exists($fullPlanPath)) {
                continue;
            }

            $dirName = basename($dir);

            expect($indexContent)->toContain($dirName);
        }
    }

    preg_match_all('/`([a-zA-Z0-9_-]+(?:-implementation-plan|[0-9_-]+))`/', $indexContent, $matches);
    $referencedDirs = array_unique($matches[1] ?? []);

    $phantomDirs = [];
    foreach ($referencedDirs as $refDir) {
        if (! is_dir($sddPath.'/'.$refDir)) {
            $phantomDirs[] = $refDir;
        }
    }

    expect($phantomDirs)->toBeEmpty(
        '.superpowers/sdd/progress.md must not reference non-existent directories: '.implode(', ', $phantomDirs)
    );
});
