<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Models\Tenant;
use App\Services\SettingServiceInterface;

beforeEach(function () {
    $this->service = app(SettingServiceInterface::class);
});

it('sets a system setting', function () {
    $this->service->set('app.name', 'TallPBX', 'string');

    $setting = Setting::where('key', 'app.name')->whereNull('tenant_id')->first();

    expect($setting)->not->toBeNull()
        ->and($setting->value)->toBe('TallPBX');
});

it('gets a system setting', function () {
    Setting::create([
        'key' => 'app.name',
        'value' => 'MyPBX',
        'type' => 'string',
        'tenant_id' => null,
    ]);

    expect($this->service->get('app.name'))->toBe('MyPBX');
});

it('gets a setting with default fallback', function () {
    expect($this->service->get('nonexistent', 'fallback'))->toBe('fallback');
});

it('sets a tenant-scoped setting', function () {
    $tenant = Tenant::factory()->create();

    $this->service->set('theme', 'dark', 'string', $tenant->id);

    expect($this->service->get('theme', null, $tenant->id))->toBe('dark');
    expect($this->service->get('theme'))->toBeNull(); // not set at system level
});

it('tenant setting overrides system setting', function () {
    $tenant = Tenant::factory()->create();

    $this->service->set('max_users', '10', 'integer');
    $this->service->set('max_users', '25', 'integer', $tenant->id);

    // Cast to integer by type
    expect($this->service->get('max_users'))->toBe(10);
    expect($this->service->get('max_users', null, $tenant->id))->toBe(25);
});

it('deletes a setting', function () {
    Setting::create(['key' => 'temp.key', 'value' => 'val', 'type' => 'string']);

    $this->service->delete('temp.key');

    expect(Setting::where('key', 'temp.key')->exists())->toBeFalse();
});

it('lists all system settings', function () {
    $tenant = Tenant::factory()->create();

    Setting::create(['key' => 'a', 'value' => '1', 'type' => 'string']);
    Setting::create(['key' => 'b', 'value' => '2', 'type' => 'string']);
    Setting::create(['key' => 'c', 'value' => 'test', 'type' => 'string', 'tenant_id' => $tenant->id]);

    $all = $this->service->all();

    expect($all)->toHaveCount(2); // only system settings
});
