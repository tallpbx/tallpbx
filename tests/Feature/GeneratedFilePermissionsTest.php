<?php

declare(strict_types=1);

use App\Support\GeneratedFilePermissions;

it('repairs compiled blade view permissions', function () {
    $compiledView = storage_path('framework/views/tallpbx-permission-regression.php');

    file_put_contents($compiledView, '<?php return true;');
    chmod($compiledView, 0600);

    try {
        GeneratedFilePermissions::repair();

        expect(substr(sprintf('%o', fileperms($compiledView)), -4))->toBe('0664');
    } finally {
        @unlink($compiledView);
    }
});
