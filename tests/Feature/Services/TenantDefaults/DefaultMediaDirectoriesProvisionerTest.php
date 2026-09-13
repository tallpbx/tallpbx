<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantDefaults\DefaultMediaDirectoriesProvisioner;
use Illuminate\Support\Facades\File;
use Modules\FileStores\Enums\MediaCategory;

beforeEach(function (): void {
    $this->root = storage_path('framework/testing/media-dirs');
    config([
        'media-storage.store_root' => $this->root.'/store',
        'media-storage.spool_root' => $this->root.'/spool',
    ]);
    $this->tenant = Tenant::factory()->create();
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('provisions per-tenant media directories with a shared group', function (): void {
    app(DefaultMediaDirectoriesProvisioner::class)->provision($this->tenant);

    $spoolRoot = rtrim((string) config('media-storage.spool_root'), '/').'/'.$this->tenant->id;

    // Every remote-archive spool category plus the FreeSWITCH voicemail
    // deposit root must exist so record_session, fax receive, and
    // mod_voicemail never hit a missing-directory error on fresh installs.
    foreach (MediaCategory::cases() as $category) {
        expect(is_dir($spoolRoot.'/'.$category->value))->toBeTrue()
            ->and(fileperms($spoolRoot.'/'.$category->value) & 07777)->toBe(02775);
    }

    $voicemailDeposit = rtrim((string) config('media-storage.store_root'), '/')
        .'/runtime/'.$this->tenant->id.'/voicemail-message';
    expect(is_dir($voicemailDeposit))->toBeTrue()
        ->and(fileperms($voicemailDeposit) & 07777)->toBe(02775);
});

it('is idempotent across repeated provisioning runs', function (): void {
    $provisioner = app(DefaultMediaDirectoriesProvisioner::class);
    $provisioner->provision($this->tenant);

    $result = $provisioner->provision($this->tenant);

    expect($result)->toBe(['created' => 0, 'skipped' => count(MediaCategory::cases()) + 1]);

    $spoolRoot = rtrim((string) config('media-storage.spool_root'), '/').'/'.$this->tenant->id;
    expect(is_dir($spoolRoot.'/'.MediaCategory::CallRecording->value))->toBeTrue();
});
