<?php

declare(strict_types=1);

/*
 * The request directory is an appliance contract: the systemd path watcher,
 * the dispatcher, the installer, and the queue sandbox all name the same host
 * path. The config key exists only so isolated tests can redirect it; it is not
 * a user-facing setting.
 */

it('keeps the production request directory aligned with the host watcher and sandbox', function (): void {
    $path = (string) config('backup-storage.request_root');

    expect($path)->toBe('/var/lib/tallpbx/restore-requests');

    $files = [
        'scripts/resources/tallpbx-restore-dispatch',
        'scripts/resources/tallpbx-restore-dispatch.path',
        'scripts/resources/tall.sh',
        'scripts/tallpbx-queue.service',
    ];

    foreach ($files as $file) {
        expect(str_contains((string) file_get_contents(base_path($file)), $path))
            ->toBeTrue($file.' must name the production request directory');
    }
});

it('keeps the journal directory root-only and outside the queue sandbox', function (): void {
    expect((string) config('backup-storage.journal_root'))->toBe('/var/lib/tallpbx/restore-journal');

    // The queue worker must never receive write access to journal state.
    expect(file_get_contents(base_path('scripts/tallpbx-queue.service')))
        ->not->toContain('/var/lib/tallpbx/restore-journal');

    expect(file_get_contents(base_path('scripts/resources/tall.sh')))
        ->toContain('install -d -m 0700 -o root -g root /var/lib/tallpbx/restore-journal');
});
