<?php

declare(strict_types=1);

use Modules\Backups\Services\RestoreCommandRunnerInterface;
use Modules\Backups\Services\RestoreJournal;
use Modules\Backups\Services\RestoreOperationExecutor;

/*
 * A restore that cannot fit must be refused before anything is extracted or
 * replaced. The bound is the archive's own uncompressed member list, not a
 * multiplier: a highly compressible dump can expand far more than fourfold.
 */

/**
 * Build an executor whose tar listing reports the given member bytes.
 *
 * @param  array<string, float>  $freeSpace  Bytes available per filesystem path
 */
function makeRestoreSpaceExecutor(float $archiveMemberBytes, array $freeSpace): RestoreOperationExecutor
{
    $runner = new class($archiveMemberBytes) implements RestoreCommandRunnerInterface
    {
        public function __construct(private readonly float $bytes) {}

        public function run(array $command, mixed $input = null, array $environment = []): string
        {
            return sprintf(
                "-rw-r--r-- root/root %d 2026-11-15 10:00 root/app_files/payload.bin\n",
                (int) $this->bytes,
            );
        }
    };

    $journal = new RestoreJournal(sys_get_temp_dir().'/restore-space-journal-'.uniqid());

    return new RestoreOperationExecutor(
        $runner,
        $journal,
        null,
        fn (string $path): float => $freeSpace[$path] ?? 1_000_000_000_000.0,
    );
}

it('requires the working set plus the reserve, not the reserve alone', function (): void {
    expect(makeRestoreSpaceExecutor(1.0, [])->projectedRequirement(32_000_000_000))->toMatchArray([
        'workingSet' => 32_000_000_000,
        'reserve' => 3_200_000_000,
        'required' => 35_200_000_000,
    ]);
});

it('keeps a two gigabyte floor under the reserve', function (): void {
    expect(makeRestoreSpaceExecutor(1.0, [])->projectedRequirement(1_000_000_000)['reserve'])->toBe(2_000_000_000);
});

it('refuses a volume that cannot hold the working set and the reserve', function (): void {
    // 30 GB free against a 35.2 GB requirement: a reserve-only check (3.2 GB)
    // would have admitted this restore.
    $executor = makeRestoreSpaceExecutor(32_000_000_000, [
        (string) config('backup-storage.root') => 30_000_000_000.0,
        storage_path('backups') => 100_000_000_000.0,
    ]);

    expect(fn () => $executor->assertSpaceFor('/var/lib/tallpbx/backups/x.tar.gz', ['app_files']))
        ->toThrow(RuntimeException::class, 'insufficient free space');
});

it('refuses when a second volume is short even though the archive volume fits', function (): void {
    $executor = makeRestoreSpaceExecutor(32_000_000_000, [
        (string) config('backup-storage.root') => 100_000_000_000.0,
        storage_path('backups') => 1_000_000.0,
    ]);

    expect(fn () => $executor->assertSpaceFor('/var/lib/tallpbx/backups/x.tar.gz', ['app_files']))
        ->toThrow(RuntimeException::class, 'insufficient free space');
});

it('admits a volume that holds the working set and the reserve', function (): void {
    $executor = makeRestoreSpaceExecutor(1_000_000, [
        (string) config('backup-storage.root') => 100_000_000_000.0,
        storage_path('backups') => 100_000_000_000.0,
    ]);

    expect(fn () => $executor->assertSpaceFor('/var/lib/tallpbx/backups/x.tar.gz', ['database']))
        ->not->toThrow(RuntimeException::class);
});
