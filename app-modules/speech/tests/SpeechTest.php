<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Speech\Livewire\SpeechEdit;
use Modules\Speech\Livewire\SpeechList;
use Modules\Speech\Models\SpeechConfig;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('renders the list component', function () {
    SpeechConfig::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SpeechList::class)
        ->assertOk()
        ->assertSee('Speech')
        ->assertViewHas('configs', fn ($items) => $items->count() === 2);
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SpeechEdit::class)
        ->assertOk()
        ->assertSee('Create');
});

it('creates a speech config', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SpeechEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('engine', 'google')
        ->set('voice', 'en-US-Standard-A')
        ->set('language', 'en-US')
        ->set('rate', 1.0)
        ->call('save')
        ->assertRedirect(route('panel.speech.index'));

    expect(SpeechConfig::count())->toBe(1);
});

it('validates required fields', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SpeechEdit::class)
        ->call('save')
        ->assertHasErrors(['tenantId', 'engine']);
});

it('deletes a speech config', function () {
    $config = SpeechConfig::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SpeechList::class)
        ->call('confirmConfigDeletion', $config->id)
        ->assertSet('pendingDeletionId', $config->id)
        ->call('deleteConfig')
        ->assertSet('operationalMessage', 'Speech configuration deleted.')
        ->assertOk();

    expect(SpeechConfig::count())->toBe(0);
});

it('opens the shared confirmation modal before deleting a speech configuration', function (): void {
    $config = SpeechConfig::factory()->create(['engine' => 'google', 'voice' => 'en-US-Standard-A']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SpeechList::class)
        ->call('confirmConfigDeletion', $config->id)
        ->assertSet('pendingDeletionId', $config->id)
        ->assertSet('pendingDeletionName', 'google / en-US-Standard-A')
        ->assertSee('Delete Speech Configuration?');
});
