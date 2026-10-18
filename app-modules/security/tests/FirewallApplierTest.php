<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Models\Admin;
use Database\Seeders\AdminSeeder;
use Database\Seeders\SecurityServiceSeeder;
use Illuminate\Support\Facades\Event;
use Mockery;
use Modules\Security\Contracts\FirewallApplierInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Events\FirewallRulesetUpdated;
use Modules\Security\Exceptions\LockoutException;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\Firewall\FirewallApplier;
use Modules\Security\Services\Firewall\FirewallApplyResult;
use Modules\Security\Services\Firewall\LockoutGuardService;
use Modules\Security\Services\Firewall\SecurityConfigGenerator;

beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->seed(SecurityServiceSeeder::class);

    $this->lockoutGuard = Mockery::mock(LockoutGuardService::class);
    $this->generator = Mockery::mock(SecurityConfigGenerator::class);
    $this->executor = Mockery::mock(SecurityExecutorInterface::class);

    $this->app->instance(LockoutGuardService::class, $this->lockoutGuard);
    $this->app->instance(SecurityConfigGenerator::class, $this->generator);
    $this->app->instance(SecurityExecutorInterface::class, $this->executor);

    $this->applier = new FirewallApplier(
        $this->lockoutGuard,
        $this->generator,
        $this->executor,
    );
});

it('resolves FirewallApplier from container as FirewallApplierInterface', function (): void {
    $applier = app(FirewallApplierInterface::class);

    expect($applier)->toBeInstanceOf(FirewallApplier::class);
});

it('executes full 5-step apply pipeline successfully', function (): void {
    Event::fake([FirewallRulesetUpdated::class]);

    SecuritySetting::updateOrCreate(['key' => 'pending_changes_count'], ['value' => '5']);

    $this->lockoutGuard->shouldReceive('assertSafe')
        ->with('198.51.100.10', 'drop')
        ->once();

    $this->lockoutGuard->shouldReceive('assertLocalServicesSafe')
        ->once();

    $pendingFile = '/etc/tallpbx/firewall.nft.pending';
    $this->generator->shouldReceive('writePending')
        ->once()
        ->andReturn($pendingFile);

    $this->generator->shouldReceive('validateSyntax')
        ->with($pendingFile)
        ->once()
        ->andReturnTrue();

    $this->executor->shouldReceive('apply')
        ->once()
        ->andReturnTrue();

    $admin = Admin::factory()->create();

    $result = $this->applier->apply(
        adminIp: '198.51.100.10',
        policy: 'drop',
        source: 'ui',
        adminId: $admin->id,
    );

    expect($result)->toBeInstanceOf(FirewallApplyResult::class)
        ->and($result->successful)->toBeTrue()
        ->and($result->failed())->toBeFalse()
        ->and($result->stage)->toBeNull()
        ->and($result->errorMessage)->toBeNull()
        ->and($result->pendingFile)->toBe($pendingFile)
        ->and($result->auditLogged)->toBeTrue()
        ->and($result->broadcast)->toBeTrue();

    expect(SecuritySetting::get('pending_changes_count'))->toBe('0');

    expect(SecurityAuditLog::where('action', 'firewall_applied_ui')
        ->where('ip_address', '198.51.100.10')
        ->where('admin_id', $admin->id)
        ->exists())->toBeTrue();

    Event::assertDispatched(FirewallRulesetUpdated::class, fn ($e) => $e->source === 'ui');
});

it('aborts at step 1 and returns lockout_guard stage when admin IP lockout check fails', function (): void {
    Event::fake([FirewallRulesetUpdated::class]);

    $this->lockoutGuard->shouldReceive('assertSafe')
        ->with('198.51.100.10', 'drop')
        ->once()
        ->andThrow(new LockoutException('Zero-Lockout Safety Alert: Lockout detected.'));

    $this->lockoutGuard->shouldNotReceive('assertLocalServicesSafe');
    $this->generator->shouldNotReceive('writePending');
    $this->generator->shouldNotReceive('validateSyntax');
    $this->executor->shouldNotReceive('apply');

    $result = $this->applier->apply(
        adminIp: '198.51.100.10',
        policy: 'drop',
    );

    expect($result->successful)->toBeFalse()
        ->and($result->failed())->toBeTrue()
        ->and($result->stage)->toBe('lockout_guard')
        ->and($result->errorMessage)->toBe('Zero-Lockout Safety Alert: Lockout detected.')
        ->and($result->auditLogged)->toBeFalse()
        ->and($result->broadcast)->toBeFalse();

    expect(SecurityAuditLog::count())->toBe(0);
    Event::assertNotDispatched(FirewallRulesetUpdated::class);
});

it('aborts at step 1 and returns lockout_guard stage when local services check fails', function (): void {
    Event::fake([FirewallRulesetUpdated::class]);

    $this->lockoutGuard->shouldReceive('assertSafe')->once();
    $this->lockoutGuard->shouldReceive('assertLocalServicesSafe')
        ->once()
        ->andThrow(new LockoutException('Local service safety alert: loopback would be dropped.'));

    $this->generator->shouldNotReceive('writePending');
    $this->executor->shouldNotReceive('apply');

    $result = $this->applier->apply(
        adminIp: '198.51.100.10',
        policy: 'drop',
    );

    expect($result->successful)->toBeFalse()
        ->and($result->stage)->toBe('lockout_guard')
        ->and($result->errorMessage)->toBe('Local service safety alert: loopback would be dropped.');

    expect(SecurityAuditLog::count())->toBe(0);
    Event::assertNotDispatched(FirewallRulesetUpdated::class);
});

it('bypasses step 1 lockout checks when force is true', function (): void {
    $this->lockoutGuard->shouldNotReceive('assertSafe');
    $this->lockoutGuard->shouldNotReceive('assertLocalServicesSafe');

    $this->generator->shouldReceive('writePending')->once()->andReturn('/tmp/pending.nft');
    $this->generator->shouldReceive('validateSyntax')->once()->andReturnTrue();
    $this->executor->shouldReceive('apply')->once()->andReturnTrue();

    $result = $this->applier->apply(
        adminIp: '198.51.100.10',
        force: true,
    );

    expect($result->successful)->toBeTrue();
});

it('aborts at step 2 and returns generator stage when writing pending config throws', function (): void {
    $this->lockoutGuard->shouldReceive('assertSafe')->once();
    $this->lockoutGuard->shouldReceive('assertLocalServicesSafe')->once();

    $this->generator->shouldReceive('writePending')
        ->once()
        ->andThrow(new \RuntimeException('Disk quota exceeded'));

    $this->generator->shouldNotReceive('validateSyntax');
    $this->executor->shouldNotReceive('apply');

    $result = $this->applier->apply(adminIp: '127.0.0.1');

    expect($result->successful)->toBeFalse()
        ->and($result->stage)->toBe('generator')
        ->and($result->errorMessage)->toContain('Disk quota exceeded');
});

it('aborts at step 3 and returns syntax_validation stage when syntax preflight fails', function (): void {
    $this->lockoutGuard->shouldReceive('assertSafe')->once();
    $this->lockoutGuard->shouldReceive('assertLocalServicesSafe')->once();

    $pendingFile = '/etc/tallpbx/firewall.nft.pending';
    $this->generator->shouldReceive('writePending')->once()->andReturn($pendingFile);
    $this->generator->shouldReceive('validateSyntax')
        ->with($pendingFile)
        ->once()
        ->andReturnFalse();

    $this->executor->shouldNotReceive('apply');

    $result = $this->applier->apply(adminIp: '127.0.0.1');

    expect($result->successful)->toBeFalse()
        ->and($result->stage)->toBe('syntax_validation')
        ->and($result->pendingFile)->toBe($pendingFile)
        ->and($result->errorMessage)->toContain('nftables syntax validation');

    expect(SecurityAuditLog::count())->toBe(0);
});

it('aborts at step 4 and returns executor stage when bounded helper apply fails', function (): void {
    $this->lockoutGuard->shouldReceive('assertSafe')->once();
    $this->lockoutGuard->shouldReceive('assertLocalServicesSafe')->once();

    $pendingFile = '/etc/tallpbx/firewall.nft.pending';
    $this->generator->shouldReceive('writePending')->once()->andReturn($pendingFile);
    $this->generator->shouldReceive('validateSyntax')->once()->andReturnTrue();
    $this->executor->shouldReceive('apply')->once()->andReturnFalse();

    $result = $this->applier->apply(adminIp: '127.0.0.1');

    expect($result->successful)->toBeFalse()
        ->and($result->stage)->toBe('executor')
        ->and($result->pendingFile)->toBe($pendingFile)
        ->and($result->errorMessage)->toBe('Failed to apply firewall ruleset via bounded helper.');

    expect(SecurityAuditLog::count())->toBe(0);
});

it('honors dryRun mode without executing kernel apply, audit logging, or event dispatch', function (): void {
    Event::fake([FirewallRulesetUpdated::class]);

    $this->lockoutGuard->shouldReceive('assertSafe')->once();
    $this->lockoutGuard->shouldReceive('assertLocalServicesSafe')->once();

    $pendingFile = '/etc/tallpbx/firewall.nft.pending';
    $this->generator->shouldReceive('writePending')->once()->andReturn($pendingFile);
    $this->generator->shouldReceive('validateSyntax')->once()->andReturnTrue();
    $this->executor->shouldNotReceive('apply');

    $result = $this->applier->apply(
        adminIp: '127.0.0.1',
        dryRun: true,
    );

    expect($result->successful)->toBeTrue()
        ->and($result->auditLogged)->toBeFalse()
        ->and($result->broadcast)->toBeFalse();

    expect(SecurityAuditLog::count())->toBe(0);
    Event::assertNotDispatched(FirewallRulesetUpdated::class);
});

it('formats audit logs and event sources according to invocation origin', function (): void {
    Event::fake([FirewallRulesetUpdated::class]);

    $this->lockoutGuard->shouldReceive('assertSafe')->times(2);
    $this->lockoutGuard->shouldReceive('assertLocalServicesSafe')->times(2);

    $this->generator->shouldReceive('writePending')->andReturn('/tmp/pending.nft');
    $this->generator->shouldReceive('validateSyntax')->andReturnTrue();
    $this->executor->shouldReceive('apply')->andReturnTrue();

    // CLI invocation
    $this->applier->apply(adminIp: '127.0.0.1', source: 'cli');
    expect(SecurityAuditLog::where('action', 'firewall_applied_cli')->exists())->toBeTrue();
    Event::assertDispatched(FirewallRulesetUpdated::class, fn ($e) => $e->source === 'cli');

    // Boot recovery invocation
    $this->applier->apply(adminIp: '127.0.0.1', source: 'boot_recovery');
    expect(SecurityAuditLog::where('action', 'firewall_boot_recovered')->exists())->toBeTrue();
    Event::assertDispatched(FirewallRulesetUpdated::class, fn ($e) => $e->source === 'boot_recovery');
});
