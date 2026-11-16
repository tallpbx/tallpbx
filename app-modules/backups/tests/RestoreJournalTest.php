<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Backups\Services\RestoreJournal;

/*
 * The journal is the only record that survives the database it describes.
 * One process (the root helper) writes it; the panel and the queue read a
 * projection instead.
 */

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/restore-journal-'.Str::random(8);
    $this->uuid = (string) Str::uuid();
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('writes an entry atomically and reads it back', function (): void {
    $journal = new RestoreJournal($this->root);
    $journal->write($this->uuid, ['phase' => 'queued', 'archive_path' => '/x.tar.gz', 'scopes' => ['database']]);

    expect($journal->read($this->uuid))
        ->toMatchArray(['phase' => 'queued', 'archive_path' => '/x.tar.gz'])
        ->and(glob($this->root.'/*.tmp'))->toBe([]);
});

it('merges phases and reports a terminal status only when finished', function (): void {
    $journal = new RestoreJournal($this->root);
    $journal->write($this->uuid, ['phase' => 'queued']);
    expect($journal->terminalStatus($this->uuid))->toBeNull();

    $journal->record($this->uuid, 'importing');
    expect($journal->read($this->uuid)['phase'])->toBe('importing');

    $journal->record($this->uuid, 'completed', ['result' => 'ok']);
    expect($journal->terminalStatus($this->uuid))->toBe('completed')
        ->and($journal->read($this->uuid)['result'])->toBe('ok');
});

it('returns null for an unknown operation', function (): void {
    expect((new RestoreJournal($this->root))->read('00000000-0000-4000-8000-000000000000'))->toBeNull();
});

it('seeds the full operation metadata before the database is replaced', function (): void {
    $journal = new RestoreJournal($this->root);
    $journal->seed($this->uuid, [
        'archive_path' => '/var/lib/tallpbx/backups/x.tar.gz',
        'archive_checksum' => str_repeat('a', 64),
        'source_backup_id' => null,
        'scopes' => ['database'],
        'requester' => ['id' => 7, 'email' => 'admin@example.test', 'name' => 'Admin'],
        'started_at' => now()->toIso8601String(),
    ]);

    expect($journal->read($this->uuid))->toMatchArray([
        'phase' => 'queued',
        'archive_path' => '/var/lib/tallpbx/backups/x.tar.gz',
        'archive_checksum' => str_repeat('a', 64),
        'scopes' => ['database'],
        'requester' => ['id' => 7, 'email' => 'admin@example.test', 'name' => 'Admin'],
        'source_backup_id' => null,
    ]);
});

it('creates the journal directory and files with owner-only permissions', function (): void {
    $journal = new RestoreJournal($this->root);
    $journal->write($this->uuid, ['phase' => 'queued']);

    $directoryMode = fileperms($this->root) & 0777;
    $fileMode = fileperms($this->root.'/'.$this->uuid.'.json') & 0777;

    expect($directoryMode)->toBe(0700)
        ->and($fileMode)->toBe(0600)
        ->and(fileowner($this->root))->toBe(fileowner($this->root.'/'.$this->uuid.'.json'));
});

it('refuses a symlinked journal directory', function (): void {
    $real = sys_get_temp_dir().'/restore-journal-real-'.Str::random(8);
    File::ensureDirectoryExists($real);
    symlink($real, $this->root);

    try {
        expect(fn () => (new RestoreJournal($this->root))->write($this->uuid, ['phase' => 'queued']))
            ->toThrow(RuntimeException::class);
    } finally {
        @unlink($this->root);
        File::deleteDirectory($real);
    }
});

it('lists every readable journal entry keyed by operation id', function (): void {
    $journal = new RestoreJournal($this->root);
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();
    $journal->write($first, ['phase' => 'queued']);
    $journal->write($second, ['phase' => 'failed']);

    file_put_contents($this->root.'/not-json.json', '{');

    expect(array_keys($journal->all()))->toHaveCount(2)
        ->and($journal->all()[$second]['phase'])->toBe('failed');
});
