<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\SettingServiceInterface;
use Livewire\Livewire;
use Modules\EventGuard\Livewire\EventGuardSettings;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the event guard settings form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(EventGuardSettings::class)
        ->assertOk()
        ->assertSee('Rate Limits');
});

it('loads default values when no settings exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(EventGuardSettings::class)
        ->assertSet('maxEventsPerMinute', 300)
        ->assertSet('burstLimit', 50)
        ->assertSet('blockDuration', 60);
});

it('loads saved values from settings', function () {
    $settings = app(SettingServiceInterface::class);
    $settings->set('event_guard.max_events_per_minute', 100, 'integer');
    $settings->set('event_guard.burst_limit', 25, 'integer');
    $settings->set('event_guard.block_duration', 120, 'integer');

    Livewire::actingAs($this->admin, 'admin')
        ->test(EventGuardSettings::class)
        ->assertSet('maxEventsPerMinute', 100)
        ->assertSet('burstLimit', 25)
        ->assertSet('blockDuration', 120);
});

it('saves settings', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(EventGuardSettings::class)
        ->set('maxEventsPerMinute', 200)
        ->set('burstLimit', 40)
        ->set('blockDuration', 90)
        ->call('save')
        ->assertOk()
        ->assertDispatched('notify');

    $settings = app(SettingServiceInterface::class);
    expect($settings->get('event_guard.max_events_per_minute'))->toBe(200);
    expect($settings->get('event_guard.burst_limit'))->toBe(40);
    expect($settings->get('event_guard.block_duration'))->toBe(90);
});

it('validates numeric fields', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(EventGuardSettings::class)
        ->set('maxEventsPerMinute', -1)
        ->call('save')
        ->assertHasErrors(['maxEventsPerMinute']);
});
