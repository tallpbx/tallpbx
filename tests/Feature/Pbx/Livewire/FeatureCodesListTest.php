<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\FeatureCodes\Livewire\FeatureCodesList;
use Modules\FeatureCodes\Models\FeatureCode;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
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
    Livewire::actingAs($this->admin, 'admin')->test(FeatureCodesList::class)->call('confirmCodeDeletion', $code->id)->assertSet('pendingDeletionId', $code->id)->assertSet('pendingDeletionName', 'Voicemail')->assertSee('Delete Feature Code?');
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
