<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Module;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Modules\Admin\Livewire\ModulesList;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the modules list', function () {
    Module::create(['name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0', 'enabled' => true]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->assertOk()
        ->assertSee('Modules')
        ->assertSee('extensions')
        ->assertSee('Extensions');
});

it('shows module enabled status', function () {
    Module::create(['name' => 'admin', 'display_name' => 'Admin', 'version' => '1.0', 'enabled' => true]);
    Module::create(['name' => 'gateways', 'display_name' => 'Gateways', 'version' => '1.0', 'enabled' => false]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->assertSee('Admin')
        ->assertSee('Gateways');
});

it('toggles module enabled status', function () {
    $module = Module::create(['name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0', 'enabled' => true]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('optimize:clear')
        ->andReturn(0);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->call('toggleEnabled', $module->id)
        ->assertDispatched('module-toggled');

    $this->assertDatabaseHas('modules', [
        'id' => $module->id,
        'enabled' => false,
        'status' => Module::StatusDisabled,
    ]);
});

it('prevents disabling protected modules', function () {
    $module = Module::create(['name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0', 'enabled' => true, 'protected' => true]);

    Artisan::shouldReceive('call')->never();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->call('toggleEnabled', $module->id);

    $this->assertDatabaseHas('modules', [
        'id' => $module->id,
        'enabled' => true,
    ]);
});

it('can re-enable disabled modules', function () {
    $module = Module::create(['name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0', 'enabled' => false]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('optimize:clear')
        ->andReturn(0);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->call('toggleEnabled', $module->id)
        ->assertDispatched('module-toggled');

    $this->assertDatabaseHas('modules', [
        'id' => $module->id,
        'enabled' => true,
        'status' => Module::StatusEnabled,
    ]);
});

it('clears framework caches when module state changes', function () {
    $module = Module::create(['name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0', 'enabled' => true]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('optimize:clear')
        ->andReturn(0);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->call('toggleEnabled', $module->id);
});

it('prevents disabling required modules', function () {
    $module = Module::create(['name' => 'admin', 'display_name' => 'Admin', 'version' => '1.0', 'enabled' => true, 'required' => true]);

    Artisan::shouldReceive('call')->never();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->call('toggleEnabled', $module->id);

    $this->assertDatabaseHas('modules', [
        'id' => $module->id,
        'enabled' => true,
    ]);
});

it('shows a CLI restore hint for uninstalled modules without destructive buttons', function () {
    $module = Module::create([
        'name' => 'extensions',
        'display_name' => 'Extensions',
        'version' => '1.0',
        'enabled' => false,
        'status' => Module::StatusUninstalled,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->assertSee('php artisan module:restore extensions')
        ->assertDontSee('prepareUninstall')
        ->assertDontSee('reinstallModule');
});
