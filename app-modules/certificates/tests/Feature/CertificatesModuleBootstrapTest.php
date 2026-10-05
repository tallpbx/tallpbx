<?php

declare(strict_types=1);

namespace Modules\Certificates\Tests\Feature;

use App\Models\Module;
use App\Services\ModuleState;
use Illuminate\Support\Facades\Schema;
use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Modules\Certificates\Services\CertificateExecutor;

it('registers certificates module in module registry and state', function (): void {
    $this->artisan('module:sync --only-local');

    $module = Module::where('name', 'certificates')->first();

    expect($module)->not->toBeNull()
        ->and($module->display_name)->toBe('Certificates')
        ->and(app(ModuleState::class)->isEnabled('certificates'))->toBeTrue();
});

it('resolves CertificateExecutorInterface to CertificateExecutor from service container', function (): void {
    $executor = app(CertificateExecutorInterface::class);

    expect($executor)->toBeInstanceOf(CertificateExecutor::class);
});

it('has all certificate tables created in database schema', function (): void {
    expect(Schema::hasTable('certificates'))->toBeTrue()
        ->and(Schema::hasTable('certificate_dns_credentials'))->toBeTrue()
        ->and(Schema::hasTable('certificate_audit_logs'))->toBeTrue();
});
