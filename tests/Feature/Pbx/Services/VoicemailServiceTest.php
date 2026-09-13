<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;
use Modules\Voicemails\Models\Voicemail;
use Modules\Voicemails\Services\VoicemailServiceInterface;

beforeEach(function () {
    $this->service = app(VoicemailServiceInterface::class);
});

it('creates a voicemail', function () {
    $tenant = Tenant::factory()->create();

    $voicemail = $this->service->create([
        'tenant_id' => $tenant->id,
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
    $voicemail = Voicemail::factory()->create();

    $this->service->delete($voicemail);

    $this->assertModelMissing($voicemail);
});

it('enforces unique voicemail_id per tenant', function () {
    $tenant = Tenant::factory()->create();

    $this->service->create([
        'tenant_id' => $tenant->id,
        'voicemail_id' => '1000',
        'mailbox' => '1000',
        'name' => 'First',
    ]);

    $this->expectException(ValidationException::class);

    $this->service->create([
        'tenant_id' => $tenant->id,
        'voicemail_id' => '1000',
        'mailbox' => '1000',
        'name' => 'Second',
    ]);
});

it('allows same voicemail_id in different tenants', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    $vm1 = $this->service->create([
        'tenant_id' => $tenant1->id,
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
    $tenant = Tenant::factory()->create();
    Voicemail::factory()->create([
        'tenant_id' => $tenant->id,
        'voicemail_id' => '1000',
        'mailbox' => '2000',
    ]);

    $found = $this->service->findByMailbox($tenant->id, '2000');

    expect($found)->not->toBeNull()
        ->and($found->voicemail_id)->toBe('1000');
});

it('returns null when mailbox not found', function () {
    $tenant = Tenant::factory()->create();

    $found = $this->service->findByMailbox($tenant->id, '9999');

    expect($found)->toBeNull();
});
