<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Backups\Services\RestoreJournal;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/restore-journal-durability-'.Str::random(8);
    $this->uuid = (string) Str::uuid();
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('retries a short write before publishing a journal entry', function (): void {
    $firstWrite = true;
    $journal = new RestoreJournal($this->root, writeBytes: function ($handle, string $bytes) use (&$firstWrite): int|false {
        if ($firstWrite) {
            $firstWrite = false;

            return fwrite($handle, substr($bytes, 0, 1));
        }

        return fwrite($handle, $bytes);
    });

    $journal->write($this->uuid, ['phase' => 'queued']);

    expect($journal->read($this->uuid))->toBe(['phase' => 'queued']);
});

it('refuses to publish a journal entry when flushing fails', function (): void {
    $journal = new RestoreJournal($this->root, flushFile: static fn ($handle): bool => false);

    expect(fn () => $journal->write($this->uuid, ['phase' => 'queued']))
        ->toThrow(RuntimeException::class, 'could not be synced');
    expect($journal->read($this->uuid))->toBeNull();
});

it('refuses to publish a journal entry when file syncing fails', function (): void {
    $journal = new RestoreJournal($this->root, syncHandle: static fn ($handle): bool => false);

    expect(fn () => $journal->write($this->uuid, ['phase' => 'queued']))
        ->toThrow(RuntimeException::class, 'could not be synced');
    expect($journal->read($this->uuid))->toBeNull();
});

it('reports a directory sync failure rather than silently accepting it', function (): void {
    $calls = 0;
    $journal = new RestoreJournal($this->root, syncHandle: static function ($handle) use (&$calls): bool {
        return ++$calls === 1;
    });

    expect(fn () => $journal->write($this->uuid, ['phase' => 'queued']))
        ->toThrow(RuntimeException::class, 'directory could not be synced');
});
