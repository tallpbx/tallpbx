<?php

declare(strict_types=1);

use Modules\CallRecordings\Services\CallRecordingService;
use Modules\Fax\Services\FaxService;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\MusicOnHold\Services\MusicOnHoldService;
use Modules\Recordings\Services\RecordingService;
use Modules\VoicemailMessages\Services\VoicemailMessageService;

/**
 * Architecture test: Contract Seams Over Direct Cross-Module Model Imports (Task 4.2).
 *
 * Forbids direct instantiation (creation, mutation, firstOrCreate) of FileStore and MediaAsset
 * outside the FileStores module, and Extension outside the Extensions module. Cross-module
 * domain services must route media lifecycle through MediaStorageServiceInterface and
 * destination/extension lookups through their respective contract seams.
 */
it('forbids direct instantiation and creation of FileStore and MediaAsset outside Modules\FileStores', function (): void {
    $projectRoot = base_path();
    $modulesDir = $projectRoot.'/app-modules';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir)
    );

    $scannedFiles = 0;
    $violations = [];

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $filePath = $file->getPathname();
        $relativePath = str_replace($projectRoot.'/', '', $filePath);

        // Scope strictly to production application code (src/), excluding tests, migrations, factories
        if (! str_contains($relativePath, '/src/')
            || str_contains($relativePath, 'app-modules/file-stores/src/')) {
            continue;
        }

        $scannedFiles++;
        $content = (string) file_get_contents($filePath);
        $lines = explode("\n", $content);

        foreach ($lines as $lineIndex => $line) {
            $trimmed = trim($line);

            // Direct instantiation or persistence calls on FileStore or MediaAsset
            if (preg_match('/(?:new\s+(?<![a-zA-Z0-9_])(?:FileStore|MediaAsset)\b|(?<![a-zA-Z0-9_])(?:FileStore|MediaAsset)::(?:query\(\)->)?(?:create|firstOrCreate)\b)/', $trimmed)) {
                $violations[] = sprintf(
                    '%s:%d directly instantiates or creates FileStore/MediaAsset [%s]',
                    $relativePath,
                    $lineIndex + 1,
                    $trimmed
                );
            }
        }
    }

    expect($scannedFiles)->toBeGreaterThan(100)
        ->and($violations)->toBeEmpty(
            "Found unauthorized direct instantiation of FileStore/MediaAsset outside Modules\\FileStores:\n".implode("\n", $violations)
        );
});

it('forbids direct instantiation and creation of Extension outside Modules\Extensions', function (): void {
    $projectRoot = base_path();
    $modulesDir = $projectRoot.'/app-modules';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir)
    );

    $scannedFiles = 0;
    $violations = [];

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $filePath = $file->getPathname();
        $relativePath = str_replace($projectRoot.'/', '', $filePath);

        // Scope strictly to production application code (src/), excluding tests, migrations, factories
        if (! str_contains($relativePath, '/src/')
            || str_contains($relativePath, 'app-modules/extensions/src/')) {
            continue;
        }

        $scannedFiles++;
        $content = (string) file_get_contents($filePath);
        $lines = explode("\n", $content);

        foreach ($lines as $lineIndex => $line) {
            $trimmed = trim($line);

            // Direct instantiation or persistence calls on Extension model
            if (preg_match('/(?:new\s+(?<![a-zA-Z0-9_])Extension\b|(?<![a-zA-Z0-9_])Extension::(?:query\(\)->)?(?:create|firstOrCreate)\b)/', $trimmed)) {
                $violations[] = sprintf(
                    '%s:%d directly instantiates or creates Extension [%s]',
                    $relativePath,
                    $lineIndex + 1,
                    $trimmed
                );
            }
        }
    }

    expect($scannedFiles)->toBeGreaterThan(100)
        ->and($violations)->toBeEmpty(
            "Found unauthorized direct instantiation of Extension outside Modules\\Extensions:\n".implode("\n", $violations)
        );
});

it('enforces that domain media services depend on MediaStorageServiceInterface', function (): void {
    $services = [
        CallRecordingService::class,
        VoicemailMessageService::class,
        RecordingService::class,
        MusicOnHoldService::class,
        FaxService::class,
    ];

    foreach ($services as $serviceClass) {
        $reflection = new ReflectionClass($serviceClass);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull("Service {$serviceClass} must declare a constructor.");

        $hasMediaStorage = false;
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === MediaStorageServiceInterface::class) {
                $hasMediaStorage = true;
                break;
            }
        }

        expect($hasMediaStorage)->toBeTrue(
            "Service {$serviceClass} must inject MediaStorageServiceInterface for managed media lifecycle."
        );
    }
});
