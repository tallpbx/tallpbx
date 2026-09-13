<?php

declare(strict_types=1);

use App\Support\ApplicationFilePermissions;
use App\Support\PermissionRepairScope;

// Create an isolated file tree so permission changes never touch the application.
beforeEach(function (): void {
    $this->permissionsTestRoot = sys_get_temp_dir().'/tallpbx-permissions-'.bin2hex(random_bytes(6));
    ensureDirectory($this->permissionsTestRoot);
});

// Remove every temporary file and directory created by the current test.
afterEach(function (): void {
    deleteDirectory($this->permissionsTestRoot);
});

// Generated scope must fix only files Laravel or Vite can recreate at runtime.
it('repairs only generated paths in generated scope', function (): void {
    $cacheDirectory = $this->permissionsTestRoot.'/bootstrap/cache';
    $sourceFile = $this->permissionsTestRoot.'/app/Example.php';
    ensureDirectory($cacheDirectory);
    ensureDirectory(dirname($sourceFile));
    file_put_contents($cacheDirectory.'/services.php', '<?php return [];');
    file_put_contents($sourceFile, '<?php');
    chmod($cacheDirectory.'/services.php', 0600);
    chmod($sourceFile, 0600);

    (new ApplicationFilePermissions($this->permissionsTestRoot))->repair(PermissionRepairScope::Generated);

    expect(fileMode($cacheDirectory.'/services.php'))->toBe(0664)
        ->and(fileMode($cacheDirectory))->toBe(02775)
        ->and(fileMode($sourceFile))->toBe(0600);
});

// Full scope must normalize deployed files without breaking Composer commands.
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
        ensureDirectory(dirname($path));
        file_put_contents($path, 'contents');
        chmod($path, 0600);
    }
    chmod($vendorCommand, 0700);
    chmod($gitDir, 0700);

    (new ApplicationFilePermissions($this->permissionsTestRoot))->repair(PermissionRepairScope::Full);

    expect(fileMode($sourceFile))->toBe(0644)
        ->and(fileMode($docFile))->toBe(0644)
        ->and(fileMode($testFile))->toBe(0644)
        ->and(fileMode(dirname($docFile)))->toBe(0755)
        ->and(fileMode(dirname($testFile)))->toBe(0755)
        ->and(fileMode($vendorFile))->toBe(0664)
        ->and(fileMode(dirname($vendorFile)))->toBe(02775)
        ->and(fileMode($vendorCommand))->toBe(0755)
        ->and(fileMode($runtimeFile))->toBe(0664)
        ->and(fileMode(dirname($runtimeFile)))->toBe(02775)
        ->and(fileMode($environmentFile))->toBe(0640)
        ->and(fileMode($gitConfig))->toBe(0664)
        ->and(fileMode($gitDir))->toBe(02775)
        ->and(fileMode($lockFile))->toBe(0664);
});

/**
 * Return only the Unix permission bits for an existing test file or directory.
 */
function fileMode(string $path): int
{
    clearstatcache(true, $path);

    return fileperms($path) & 07777;
}

/**
 * Create a test directory and its parents when they do not already exist.
 */
function ensureDirectory(string $path): void
{
    if (! is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

/**
 * Remove a test directory and everything created inside it.
 */
function deleteDirectory(string $path): void
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
