<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Services\FreeSwitchServiceInterface;
use App\Support\ApplicationFilePermissions;
use App\Support\Concerns\HasOperationalFeedback;
use App\Support\FreeSwitchRuntimeVersion;
use App\Support\PermissionRepairScope;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Mockery;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/*
|--------------------------------------------------------------------------
| HasOperationalFeedback Concern Tests
|--------------------------------------------------------------------------
*/

describe('hasoperationalfeedback concern', function (): void {
    it('stores each semantic operational feedback type and clears it', function (): void {
        $feedback = new class
        {
            use HasOperationalFeedback;

            /**
             * Record a success message.
             */
            public function success(): void
            {
                $this->showSuccess('Saved.');
            }

            /**
             * Record a warning message.
             */
            public function warning(): void
            {
                $this->showWarning('Check this.');
            }

            /**
             * Record an error message.
             */
            public function error(): void
            {
                $this->showError('Could not continue.');
            }

            /**
             * Record an informational message.
             */
            public function info(): void
            {
                $this->showInfo('Useful information.');
            }

            /**
             * Clear the currently stored message.
             */
            public function clear(): void
            {
                $this->clearOperationalMessage();
            }
        };

        $feedback->success();
        expect($feedback->operationalMessageType)->toBe('success')->and($feedback->operationalMessage)->toBe('Saved.');
        $feedback->warning();
        expect($feedback->operationalMessageType)->toBe('warning');
        $feedback->error();
        expect($feedback->operationalMessageType)->toBe('error');
        $feedback->info();
        expect($feedback->operationalMessageType)->toBe('info');
        $feedback->clear();
        expect($feedback->operationalMessage)->toBeNull()->and($feedback->operationalMessageType)->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| FreeSWITCH Runtime Version Reader Tests
|--------------------------------------------------------------------------
*/

describe('freeswitch runtime version reader', function (): void {
    afterEach(function (): void {
        Mockery::close();
    });

    it('formats the connected FreeSWITCH version for footer display', function (): void {
        $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
        $freeSwitch->shouldReceive('isConnected')->once()->andReturnTrue();
        $freeSwitch->shouldReceive('api')->once()->with('version')->andReturn(
            'FreeSWITCH Version 1.11.2 -dev-29340309922-8020f5f2e7 64bit'
        );

        $reader = new FreeSwitchRuntimeVersion($freeSwitch, new Repository(new ArrayStore));
        expect($reader->label())->toBe('FreeSWITCH 1.11.2');
    });

    it('falls back to the product name when ESL is unavailable', function (): void {
        $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
        $freeSwitch->shouldReceive('isConnected')->once()->andReturnFalse();
        $freeSwitch->shouldNotReceive('api');

        $reader = new FreeSwitchRuntimeVersion($freeSwitch, new Repository(new ArrayStore));
        expect($reader->label())->toBe('FreeSWITCH');
    });

    it('parses the version from status-style output too', function (): void {
        $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
        $reader = new FreeSwitchRuntimeVersion($freeSwitch, new Repository(new ArrayStore));

        expect($reader->parse('FreeSWITCH (Version 1.11.2 -dev-abc 64bit) is ready'))->toBe('1.11.2');
    });

    it('trims build metadata from compact version output', function (): void {
        $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
        $reader = new FreeSwitchRuntimeVersion($freeSwitch, new Repository(new ArrayStore));

        expect($reader->parse('FreeSWITCH Version 1.11.2-dev-29340309922-8020f5f2e7~64bit'))->toBe('1.11.2');
    });
});

/*
|--------------------------------------------------------------------------
| Application File Permissions Tests
|--------------------------------------------------------------------------
*/

describe('application file permissions service', function (): void {
    beforeEach(function (): void {
        $this->permissionsTestRoot = sys_get_temp_dir().'/tallpbx-permissions-'.bin2hex(random_bytes(6));
        ensurePermDirectory($this->permissionsTestRoot);
    });

    afterEach(function (): void {
        deletePermDirectory($this->permissionsTestRoot);
    });

    it('repairs only generated paths in generated scope', function (): void {
        $cacheDirectory = $this->permissionsTestRoot.'/bootstrap/cache';
        $testingViewsDirectory = $this->permissionsTestRoot.'/storage/framework/testing-views';
        $sourceFile = $this->permissionsTestRoot.'/app/Example.php';
        ensurePermDirectory($cacheDirectory);
        ensurePermDirectory($testingViewsDirectory);
        ensurePermDirectory(dirname($sourceFile));
        file_put_contents($cacheDirectory.'/services.php', '<?php return [];');
        file_put_contents($testingViewsDirectory.'/compiled.php', '<?php');
        file_put_contents($sourceFile, '<?php');
        chmod($cacheDirectory.'/services.php', 0600);
        chmod($testingViewsDirectory.'/compiled.php', 0600);
        chmod($sourceFile, 0600);

        (new ApplicationFilePermissions($this->permissionsTestRoot))->repair(PermissionRepairScope::Generated);

        expect(unitFileMode($cacheDirectory.'/services.php'))->toBe(0664)
            ->and(unitFileMode($cacheDirectory))->toBe(02775)
            ->and(unitFileMode($testingViewsDirectory.'/compiled.php'))->toBe(0664)
            ->and(unitFileMode($testingViewsDirectory))->toBe(02775)
            ->and(unitFileMode($sourceFile))->toBe(0600);
    });

    it('repairs the complete application while preserving vendor executables in full scope', function (): void {
        $sourceFile = $this->permissionsTestRoot.'/app/Example.php';
        $docFile = $this->permissionsTestRoot.'/docs/example.md';
        $testFile = $this->permissionsTestRoot.'/tests/Feature/ExampleTest.php';
        $vendorFile = $this->permissionsTestRoot.'/vendor/package/Example.php';
        $vendorCommand = $this->permissionsTestRoot.'/vendor/bin/example';
        $runtimeFile = $this->permissionsTestRoot.'/storage/logs/tallpbx.log';
        $environmentFile = $this->permissionsTestRoot.'/.env';
        $gitDir = $this->permissionsTestRoot.'/.git';
        $gitConfig = $gitDir.'/config';
        $lockFile = $this->permissionsTestRoot.'/composer.lock';

        foreach ([$sourceFile, $docFile, $testFile, $vendorFile, $vendorCommand, $runtimeFile, $environmentFile, $gitConfig, $lockFile] as $path) {
            ensurePermDirectory(dirname($path));
            file_put_contents($path, 'contents');
            chmod($path, 0600);
        }
        chmod($vendorCommand, 0700);
        chmod($gitDir, 0700);

        (new ApplicationFilePermissions($this->permissionsTestRoot))->repair(PermissionRepairScope::Full);

        expect(unitFileMode($sourceFile))->toBe(0644)
            ->and(unitFileMode($docFile))->toBe(0644)
            ->and(unitFileMode($testFile))->toBe(0644)
            ->and(unitFileMode(dirname($docFile)))->toBe(0755)
            ->and(unitFileMode(dirname($testFile)))->toBe(0755)
            ->and(unitFileMode($vendorFile))->toBe(0664)
            ->and(unitFileMode(dirname($vendorFile)))->toBe(02775)
            ->and(unitFileMode($vendorCommand))->toBe(0755)
            ->and(unitFileMode($runtimeFile))->toBe(0664)
            ->and(unitFileMode(dirname($runtimeFile)))->toBe(02775)
            ->and(unitFileMode($environmentFile))->toBe(0640)
            ->and(unitFileMode($gitConfig))->toBe(0664)
            ->and(unitFileMode($gitDir))->toBe(02775)
            ->and(unitFileMode($lockFile))->toBe(0664);
    });
});

/**
 * Return only the Unix permission bits for an existing test file or directory.
 */
function unitFileMode(string $path): int
{
    clearstatcache(true, $path);

    return fileperms($path) & 07777;
}

/**
 * Create a test directory and its parents when they do not already exist.
 */
function ensurePermDirectory(string $path): void
{
    if (! is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

/**
 * Remove a test directory and everything created inside it.
 */
function deletePermDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($path);
}
