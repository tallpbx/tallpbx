<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\NumberTranslations\Livewire\NumberTranslationsEdit;
use Modules\NumberTranslations\Livewire\NumberTranslationsList;
use Modules\NumberTranslations\Models\NumberTranslation;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('renders the list component', function () {
    NumberTranslation::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(NumberTranslationsList::class)
        ->assertOk()
        ->assertSee('Number Translations')
        ->assertViewHas('translations', fn ($items) => $items->count() === 2);
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(NumberTranslationsEdit::class)
        ->assertOk()
        ->assertSee('Create');
});

it('renders the edit form', function () {
    $translation = NumberTranslation::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(NumberTranslationsEdit::class, ['translationId' => $translation->id])
        ->assertOk()
        ->assertSee('Edit');
});

it('creates a number translation', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(NumberTranslationsEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Strip International Prefix')
        ->set('matchPattern', '^011(\\d+)$')
        ->set('replacePattern', '$1')
        ->set('direction', 'outbound')
        ->call('save')
        ->assertRedirect(route('panel.number-translations.index'));

    expect(NumberTranslation::count())->toBe(1);
});

it('validates required fields', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(NumberTranslationsEdit::class)
        ->call('save')
        ->assertHasErrors(['tenantId', 'name', 'matchPattern']);
});

it('deletes a number translation', function () {
    $translation = NumberTranslation::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(NumberTranslationsList::class)
        ->call('deleteTranslation', $translation->id)
        ->assertOk();

    expect(NumberTranslation::count())->toBe(0);
});

it('opens the shared confirmation modal before deleting a number translation', function (): void {
    $translation = NumberTranslation::factory()->create(['name' => 'US normalization']);
    Livewire::actingAs($this->admin, 'admin')->test(NumberTranslationsList::class)->call('confirmTranslationDeletion', $translation->id)->assertSet('pendingDeletionId', $translation->id)->assertSet('pendingDeletionName', 'US normalization')->assertSee('Delete Number Translation?');
});

it('shows empty state when no translations exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(NumberTranslationsList::class)
        ->assertSee('No translations found');
});
