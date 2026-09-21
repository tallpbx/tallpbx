<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Modules\Voicemails\Models\Voicemail;
use Modules\Voicemails\Services\VoicemailService;
use Modules\Voicemails\Services\VoicemailServiceInterface;

beforeEach(function (): void {
    $this->root = storage_path('framework/testing/voicemail-mailbox-dirs');
    config(['media-storage.store_root' => $this->root.'/store']);
    $this->service = app(VoicemailServiceInterface::class);
    $this->tenant = Tenant::factory()->create();
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('creates a voicemail', function () {
    $voicemail = $this->service->create([
        'tenant_id' => $this->tenant->id,
        'voicemail_id' => '1000',
        'mailbox' => '1000',
        'name' => 'John Doe\'s Voicemail',
        'password' => '1234',
        'email' => 'john@example.com',
        'require_password' => true,
        'enabled' => true,
    ]);

    expect($voicemail)
        ->toBeInstanceOf(Voicemail::class)
        ->voicemail_id->toBe('1000')
        ->mailbox->toBe('1000')
        ->name->toBe('John Doe\'s Voicemail')
        ->enabled->toBeTrue();
});

it('updates a voicemail', function () {
    $voicemail = Voicemail::factory()->create([
        'tenant_id' => $this->tenant->id,
        'voicemail_id' => '1000',
        'name' => 'Old Name',
    ]);

    $updated = $this->service->update($voicemail, [
        'voicemail_id' => '1001',
        'name' => 'Updated Name',
    ]);

    expect($updated->voicemail_id)->toBe('1001')
        ->and($updated->name)->toBe('Updated Name');
});

it('deletes a voicemail', function () {
    $voicemail = Voicemail::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->service->delete($voicemail);

    $this->assertModelMissing($voicemail);
});

it('enforces unique voicemail_id per tenant', function () {
    $this->service->create([
        'tenant_id' => $this->tenant->id,
        'voicemail_id' => '1000',
        'mailbox' => '1000',
        'name' => 'First',
    ]);

    $this->expectException(ValidationException::class);

    $this->service->create([
        'tenant_id' => $this->tenant->id,
        'voicemail_id' => '1000',
        'mailbox' => '1000',
        'name' => 'Second',
    ]);
});

it('allows same voicemail_id in different tenants', function () {
    $tenant2 = Tenant::factory()->create();

    $vm1 = $this->service->create([
        'tenant_id' => $this->tenant->id,
        'voicemail_id' => '1000',
        'mailbox' => '1000',
        'name' => 'Tenant 1 VM',
    ]);

    $vm2 = $this->service->create([
        'tenant_id' => $tenant2->id,
        'voicemail_id' => '1000',
        'mailbox' => '1000',
        'name' => 'Tenant 2 VM',
    ]);

    expect($vm1->id)->not->toBe($vm2->id);
});

it('finds a voicemail by mailbox number', function () {
    Voicemail::factory()->create([
        'tenant_id' => $this->tenant->id,
        'voicemail_id' => '1000',
        'mailbox' => '2000',
    ]);

    $found = $this->service->findByMailbox($this->tenant->id, '2000');

    expect($found)->not->toBeNull()
        ->and($found->voicemail_id)->toBe('1000');
});

it('returns null when mailbox not found', function () {
    $found = $this->service->findByMailbox($this->tenant->id, '9999');

    expect($found)->toBeNull();
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
