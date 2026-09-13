<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\Voicemails\Livewire\VoicemailsEdit;
use Modules\Voicemails\Models\Voicemail;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailsEdit::class)
        ->assertOk()
        ->assertSee('Create Voicemail')
        ->assertSet('voicemailId', '')
        ->assertSet('name', '');
});

it('renders the edit form with existing voicemail data', function () {
    $voicemail = Voicemail::factory()->create([
        'voicemail_id' => '1000',
        'name' => 'John\'s Voicemail',
        'require_password' => true,
        'forward_to_email' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailsEdit::class, ['voicemailUuid' => $voicemail->id])
        ->assertOk()
        ->assertSee('Edit Voicemail')
        ->assertSet('voicemailId', '1000')
        ->assertSet('name', 'John\'s Voicemail')
        ->assertSet('requirePassword', true)
        ->assertSet('forwardToEmail', true);
});

it('creates a new voicemail', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailsEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('voicemailId', '2000')
        ->set('mailbox', '2000')
        ->set('name', 'Jane\'s Voicemail')
        ->set('email', 'jane@example.com')
        ->set('password', '5678')
        ->call('save')
        ->assertRedirect(route('panel.voicemails.index'));

    $this->assertDatabaseHas('voicemails', [
        'voicemail_id' => '2000',
        'name' => 'Jane\'s Voicemail',
        'email' => 'jane@example.com',
    ]);
});

it('updates an existing voicemail', function () {
    $voicemail = Voicemail::factory()->create([
        'voicemail_id' => '1000',
        'name' => 'Old Name',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailsEdit::class, ['voicemailUuid' => $voicemail->id])
        ->set('voicemailId', '1001')
        ->set('name', 'Updated Name')
        ->call('save')
        ->assertRedirect(route('panel.voicemails.index'));

    $this->assertDatabaseHas('voicemails', [
        'id' => $voicemail->id,
        'voicemail_id' => '1001',
        'name' => 'Updated Name',
    ]);
});

it('validates voicemail ID is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailsEdit::class)
        ->set('voicemailId', '')
        ->call('save')
        ->assertHasErrors(['voicemailId' => 'required']);
});

it('validates voicemail ID uniqueness per tenant', function () {
    Voicemail::factory()->create([
        'tenant_id' => $this->tenant->id,
        'voicemail_id' => '1000',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailsEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('voicemailId', '1000')
        ->set('mailbox', '1000')
        ->set('name', 'Duplicate')
        ->call('save')
        ->assertHasErrors(['voicemailId']);
});

it('validates email format', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailsEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('voicemailId', '3000')
        ->set('email', 'not-an-email')
        ->call('save')
        ->assertHasErrors(['email' => 'email']);
});
