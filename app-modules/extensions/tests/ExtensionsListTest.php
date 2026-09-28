<?php

declare(strict_types=1);

use App\Models\Admin;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Extensions\Livewire\ExtensionsList;
use Modules\Extensions\Models\Extension;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the extensions list component', function () {
    Extension::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionsList::class)
        ->assertOk()
        ->assertSee('Extensions')
        ->assertSee('A logical dialable endpoint and identity on the PBX')
        ->assertSee('Create Multiple Extensions')
        ->assertViewHas('extensions', function ($extensions) {
            return $extensions->count() === 3;
        });
});

it('displays extension number and display name', function () {
    Extension::factory()->create([
        'extension_number' => '101',
        'display_name' => 'John Doe',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionsList::class)
        ->assertSee('101')
        ->assertSee('John Doe');
});

it('deletes an extension', function () {
    $extension = Extension::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionsList::class)
        ->call('confirmExtensionDeletion', $extension->id)
        ->assertSet('pendingDeletionId', $extension->id)
        ->call('deleteExtension')
        ->assertSet('operationalMessage', 'Extension deleted.')
        ->assertDispatched('extension-deleted');

    $this->assertModelMissing($extension);
});

it('opens the shared confirmation modal before deleting an extension', function (): void {
    $extension = Extension::factory()->create(['extension_number' => '101']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionsList::class)
        ->call('confirmExtensionDeletion', $extension->id)
        ->assertSet('pendingDeletionId', $extension->id)
        ->assertSet('pendingDeletionName', '101')
        ->assertSee('Delete Extension?');
});

it('shows empty state when no extensions exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionsList::class)
        ->assertSee('No extensions found');
});

it('renders when the create multiple extensions route is not loaded', function () {
    $originalRoutes = Route::getRoutes();

    $routes = new RouteCollection;
    $routes->add(
        (new Illuminate\Routing\Route(['GET', 'HEAD'], 'panel/extensions/create', ['uses' => fn (): string => '']))
            ->name('panel.extensions.create')
    );

    app('router')->setRoutes($routes);
    app('url')->setRoutes($routes);

    try {
        $html = view('extensions::extensions-list', [
            'extensions' => new EloquentCollection,
            'operationalMessage' => null,
            'operationalMessageType' => null,
            'pendingDeletionId' => null,
            'pendingDeletionName' => '',
        ])->render();

        expect($html)
            ->toContain('Create Extension')
            ->not->toContain('Create Multiple Extensions');
    } finally {
        app('router')->setRoutes($originalRoutes);
        app('url')->setRoutes($originalRoutes);
    }
});

it('shows voicemail status badge', function () {
    Extension::factory()->create([
        'extension_number' => '101',
        'voicemail_enabled' => true,
    ]);
    Extension::factory()->create([
        'extension_number' => '102',
        'voicemail_enabled' => false,
    ]);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionsList::class);

    $extensions = $component->viewData('extensions');
    $vmEnabled = $extensions->firstWhere('extension_number', '101');
    $vmDisabled = $extensions->firstWhere('extension_number', '102');

    expect($vmEnabled->voicemail_enabled)->toBeTrue()
        ->and($vmDisabled->voicemail_enabled)->toBeFalse();
});
