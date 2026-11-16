<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Backups\Services\RestoreCommandRunnerInterface;
use Modules\Backups\Services\RestoreJournal;
use Modules\Backups\Services\RestoreOperationExecutor;

/*
 * The backup captures every member of storage/app that is not media; the
 * restore must put every one of them back, including a top-level file that
 * sits beside the directories.
 */

it('restores a top-level file next to the restored directories', function (): void {
    $archiveRoot = storage_path('framework/testing/restore-symmetry-'.Str::random(6));
    $token = Str::random(8);
    $fileToken = Str::random(8);

    File::ensureDirectoryExists($archiveRoot.'/app_files/nested-'.$token);
    File::put($archiveRoot.'/app_files/nested-'.$token.'/inner.txt', 'inner');
    File::put($archiveRoot.'/app_files/state-'.$fileToken.'.json', '{"v":1}');

    $executor = new RestoreOperationExecutor(
        mock(RestoreCommandRunnerInterface::class),
        new RestoreJournal(storage_path('framework/testing/restore-symmetry-journal-'.Str::random(6))),
    );

    try {
        $executor->restoreFiles($archiveRoot, ['app_files']);

        expect(file_get_contents(storage_path('app/nested-'.$token.'/inner.txt')))->toBe('inner')
            ->and(file_get_contents(storage_path('app/state-'.$fileToken.'.json')))->toBe('{"v":1}');
    } finally {
        File::deleteDirectory(storage_path('app/nested-'.$token));
        @unlink(storage_path('app/state-'.$fileToken.'.json'));
        File::deleteDirectory($archiveRoot);
    }
});
