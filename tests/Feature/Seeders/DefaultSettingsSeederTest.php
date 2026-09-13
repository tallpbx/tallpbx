<?php

declare(strict_types=1);

use App\Models\Setting;
use Database\Seeders\DefaultSettingsSeeder;

it('seeds filtered FusionPBX global settings with typed values', function () {
    $this->seed(DefaultSettingsSeeder::class);

    expect(Setting::whereNull('tenant_id')->count())->toBeGreaterThanOrEqual(70);

    $domainTemplate = Setting::where('key', 'domain.template')->firstOrFail();
    expect($domainTemplate->value)->toBe('default')
        ->and($domainTemplate->type)->toBe('string');

    $passwordNumber = Setting::where('key', 'extension.password_number')->firstOrFail();
    expect($passwordNumber->value)->toBe('1')
        ->and($passwordNumber->type)->toBe('boolean');

    $messageMaxLength = Setting::where('key', 'voicemail.message_max_length')->firstOrFail();
    expect($messageMaxLength->value)->toBe('300')
        ->and($messageMaxLength->type)->toBe('integer');

    foreach (['voicemail.storage_type', 'recordings.storage_type', 'fax.storage_type'] as $mediaStorageKey) {
        $setting = Setting::where('key', $mediaStorageKey)->firstOrFail();

        expect($setting->value)->toBe('file')
            ->and($setting->type)->toBe('string');
    }

    expect(Setting::where('key', 'theme.dashboard_chart_border_color')->exists())->toBeFalse();
});

it('is idempotent', function () {
    $this->seed(DefaultSettingsSeeder::class);
    $count = Setting::whereNull('tenant_id')->count();

    $this->seed(DefaultSettingsSeeder::class);

    expect(Setting::whereNull('tenant_id')->count())->toBe($count);
});
