<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Modules\Backups\Services\RestoreCommandRunnerInterface;
use Modules\Backups\Services\RestoreDeadline;
use Modules\Backups\Services\RestoreJournal;
use Modules\Backups\Services\RestoreOperationExecutor;
use Modules\Backups\Services\SymfonyRestoreCommandRunner;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/*
 * The restore budget has two layers: a per-command ceiling for one hung
 * subprocess, and a whole-operation budget that stops a sequence of healthy
 * commands from running for hours.
 */

afterEach(function (): void {
    Carbon::setTestNow();
});

it('applies the configured per-command ceiling instead of inheriting sixty seconds', function (): void {
    $runner = new SymfonyRestoreCommandRunner(timeout: 0.2);

    expect(fn () => $runner->run(['sleep', '2']))
        ->toThrow(ProcessTimedOutException::class);
});

it('reserves a fifteen minute command ceiling and a one hour operation budget', function (): void {
    expect((float) config('backup-storage.restore_command_timeout'))->toBe(900.0)
        ->and((int) config('backup-storage.restore_operation_deadline'))->toBe(3600);
});

it('refuses to start the next phase once the operation budget is spent', function (): void {
    Carbon::setTestNow('2026-11-15 10:00:00');
    $deadline = new RestoreDeadline(seconds: 3600);

    Carbon::setTestNow('2026-11-15 10:59:00');
    expect($deadline->remainingSeconds())->toBe(60);

    Carbon::setTestNow('2026-11-15 11:00:01');
    expect(fn () => $deadline->assertRemaining())->toThrow(RuntimeException::class, 'restore budget exhausted');
});

it('never resets the budget while phases run', function (): void {
    Carbon::setTestNow('2026-11-15 10:00:00');
    $deadline = new RestoreDeadline(seconds: 60);

    Carbon::setTestNow('2026-11-15 10:00:30');
    $deadline->assertRemaining();

    Carbon::setTestNow('2026-11-15 10:01:05');
    expect(fn () => $deadline->assertRemaining())->toThrow(RuntimeException::class);
});

it('streams the database dump into the client instead of buffering it', function (): void {
    $archiveRoot = sys_get_temp_dir().'/restore-stream-'.uniqid();
    File::ensureDirectoryExists($archiveRoot);
    File::put($archiveRoot.'/database.sql', str_repeat('CREATE TABLE x (id);\n', 200_000));

    $runner = new class implements RestoreCommandRunnerInterface
    {
        /** @var array<int, bool> */
        public array $streamed = [];

        /**
         * Record whether the input arrived as a stream.
         */
        public function run(array $command, mixed $input = null, array $environment = []): string
        {
            // Recorded while the handle is still open: the executor closes it
            // in a finally before this test asserts.
            $this->streamed[] = is_resource($input);

            return '';
        }
    };

    $executor = new RestoreOperationExecutor($runner, new RestoreJournal(sys_get_temp_dir().'/restore-stream-journal-'.uniqid()));
    $method = new ReflectionMethod($executor, 'restoreDatabase');

    try {
        $method->invoke($executor, $archiveRoot, ['database']);
    } finally {
        File::deleteDirectory($archiveRoot);
    }

    expect($runner->streamed)->toBe([true]);
});
