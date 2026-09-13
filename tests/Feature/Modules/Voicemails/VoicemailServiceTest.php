<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\File;
use Modules\Voicemails\Models\Voicemail;
use Modules\Voicemails\Services\VoicemailService;

beforeEach(function (): void {
    $this->root = storage_path('framework/testing/voicemail-mailbox-dirs');
    config(['media-storage.store_root' => $this->root.'/store']);
    $this->tenant = Tenant::factory()->create();
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('provisions a group-writable mailbox directory when a mailbox is created', function (): void {
    $mailbox = app(VoicemailService::class)->create([
        'tenant_id' => $this->tenant->id,
        'voicemail_id' => '2002',
        'mailbox' => '2002',
        'name' => 'Group-writable mailbox',
        'require_password' => false,
        'enabled' => true,
    ]);

    // mod_voicemail creates the mailbox storage subdirectory itself with
    // 0750, which the web application cannot write into when it archives
    // the message out; the service must pre-create it with the shared
    // media group so FreeSWITCH deposits and app archive moves both work.
    $directory = rtrim((string) config('media-storage.store_root'), '/')
        .'/runtime/'.$this->tenant->id.'/voicemail-message/'.$mailbox->mailbox;

    expect(is_dir($directory))->toBeTrue()
        ->and(fileperms($directory) & 07777)->toBe(02775);
});

it('keeps existing mailbox records untouched when provisioning is skipped', function (): void {
    $mailbox = Voicemail::factory()->create(['tenant_id' => $this->tenant->id, 'mailbox' => '3001']);

    app(VoicemailService::class)->update($mailbox, ['name' => 'Renamed']);

    expect($mailbox->fresh()->name)->toBe('Renamed');
});
