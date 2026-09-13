<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\InitialAdminProvisioner;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->seed(AdminSeeder::class);
});

it('prepares a browser activation code without creating an administrator', function (): void {
    $exitCode = Artisan::call('initial-admin:configure', [
        'mode' => InitialAdminProvisioner::MODE_ACTIVATION_CODE,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('One-time administrator activation code:')
        ->and(Setting::system()->where('key', 'initial_admin.setup')->value('value'))->toContain('activation-code');
});
