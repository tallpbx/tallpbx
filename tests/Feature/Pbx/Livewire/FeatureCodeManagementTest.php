<?php

declare(strict_types=1);

use App\Models\Tenant;
use Livewire\Livewire;
use Modules\FeatureCodes\Livewire\FeatureCodesEdit;
use Modules\FeatureCodes\Livewire\FeatureCodesList;
use Modules\FeatureCodes\Models\FeatureCode;

beforeEach(function () {
    $this->admin = grantAdminPermissions(permissions: ['feature-codes.view', 'feature-codes.create', 'feature-codes.edit', 'feature-codes.delete']);
    $this->tenant = Tenant::factory()->create();
});

it('renders the feature codes list component', function () {
    FeatureCode::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(FeatureCodesList::class)
        ->assertOk()
        ->assertSee('Feature Codes')
        ->assertViewHas('codes', function ($codes) {
            return $codes->count() === 3;
        });
});

it('displays feature name and code', function () {
    FeatureCode::factory()->create([
        'name' => 'Voicemail',
        'code' => '*97',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FeatureCodesList::class)
        ->assertSee('Voicemail')
        ->assertSee('*97');
});

it('deletes a feature code', function () {
    $code = FeatureCode::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(FeatureCodesList::class)
        ->call('deleteCode', $code->id)
        ->assertDispatched('code-deleted');

    $this->assertModelMissing($code);
});

it('opens the shared confirmation modal before deleting a feature code', function (): void {
    $code = FeatureCode::factory()->create(['name' => 'Voicemail']);
    Livewire::actingAs($this->admin, 'admin')
        ->test(FeatureCodesList::class)
        ->call('confirmCodeDeletion', $code->id)
        ->assertSet('pendingDeletionId', $code->id)
        ->assertSet('pendingDeletionName', 'Voicemail')
        ->assertSee('Delete Feature Code?');
});

it('shows empty state when no feature codes exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(FeatureCodesList::class)
        ->assertSee('No feature codes found');
});

it('displays description column and renders description text box', function () {
    FeatureCode::factory()->create([
        'name' => 'Voicemail',
        'code' => '*97',
        'description' => 'Check voicemail messages and greeting options.',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FeatureCodesList::class)
        ->assertSee('Description')
        ->assertSee('Check voicemail messages and greeting options.');
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(FeatureCodesEdit::class)
        ->assertOk()
        ->assertSee(__('client.create').' '.__('admin.feature_code'))
        ->assertSet('name', '')
        ->assertSet('code', '');
});

it('renders the edit form with existing data', function () {
    $code = FeatureCode::factory()->create([
        'name' => 'Voicemail',
        'code' => '*97',
        'description' => 'Access voicemail',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FeatureCodesEdit::class, ['codeId' => $code->id])
        ->assertOk()
        ->assertSee(__('client.edit').' '.__('admin.feature_code'))
        ->assertSet('name', 'Voicemail')
        ->assertSet('code', '*97');
});

it('creates a new feature code', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(FeatureCodesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Echo Test')
        ->set('code', '*43')
        ->set('description', 'Echo test')
        ->call('save')
        ->assertRedirect(route('panel.feature-codes.index'));

    $this->assertDatabaseHas('feature_codes', [
        'name' => 'Echo Test',
        'code' => '*43',
    ]);
});

it('updates an existing feature code', function () {
    $code = FeatureCode::factory()->create(['name' => 'Old Name', 'code' => '*98']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FeatureCodesEdit::class, ['codeId' => $code->id])
        ->set('name', 'Updated Name')
        ->set('code', '*99')
        ->call('save')
        ->assertRedirect(route('panel.feature-codes.index'));

    $this->assertDatabaseHas('feature_codes', [
        'id' => $code->id,
        'name' => 'Updated Name',
        'code' => '*99',
    ]);
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(FeatureCodesEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('validates code is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(FeatureCodesEdit::class)
        ->set('name', 'Test')
        ->set('code', '')
        ->call('save')
        ->assertHasErrors(['code' => 'required']);
});
