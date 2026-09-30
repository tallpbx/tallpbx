<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Models\Admin;
use App\Models\Group;
use Database\Seeders\AdminSeeder;
use Database\Seeders\SecurityServiceSeeder;
use Livewire\Livewire;
use Mockery;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Livewire\SecurityManager;
use Modules\Security\Services\SecurityConfigGenerator;
use Modules\Security\Services\SecurityExecutor;

/**
 * Call-budget tests for the Security Center's live kernel status reads.
 *
 * Reading the kernel firewall status is the slowest operation on the page
 * (the helper lists the whole nftables table). These tests pin the budget:
 * mounting, rendering, and switching tabs on the security page must query
 * the kernel helper at most once per cache window, no matter how many
 * component steps consume its output.
 */
beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($this->superAdminGroup->id);
    $this->seed(SecurityServiceSeeder::class);

    // Counting stub helper: every real kernel query appends one line, so the
    // test can observe exactly how often the page reaches for kernel state.
    $this->stubDir = sys_get_temp_dir().'/tallpbx_status_budget_'.uniqid();
    mkdir($this->stubDir, 0700, true);
    $this->counterFile = $this->stubDir.'/runs.log';
    $this->stubHelper = $this->stubDir.'/stub-helper';
    file_put_contents(
        $this->stubHelper,
        "#!/bin/bash\n".
        "if [ \"\$1\" = \"status\" ]; then\n".
        "    echo run >> {$this->counterFile}\n".
        "    echo 'table inet tallpbx_filter {'\n".
        "    echo '}'\n".
        "    exit 0\n".
        "fi\n".
        "exit 1\n"
    );
    chmod($this->stubHelper, 0755);

    // Real executor (over the stub helper) and real sync verifier, so the
    // full production call chain runs and every helper query is observable.
    $this->app->instance(SecurityExecutorInterface::class, new SecurityExecutor($this->stubHelper));

    // Keep the generator's file-writing steps off the test host while letting
    // the real digest computation run (mirrors the sibling UI tests).
    $generator = Mockery::mock(SecurityConfigGenerator::class)->makePartial();
    $generator->shouldReceive('writePending')->andReturn(sys_get_temp_dir().'/tallpbx-test-firewall.nft.pending');
    $generator->shouldReceive('validateSyntax')->andReturn(true);
    $this->app->instance(SecurityConfigGenerator::class, $generator);
});

afterEach(function (): void {
    @unlink($this->stubHelper);
    @unlink($this->counterFile);
    @rmdir($this->stubDir);
});

it('queries the kernel status helper at most once when rendering and switching tabs', function (): void {
    $helperRuns = fn (): int => is_file($this->counterFile)
        ? substr_count((string) file_get_contents($this->counterFile), 'run')
        : 0;

    // Full page load: mount() + sync verification + render() all read live
    // kernel state — together they must share a single helper query.
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertOk();

    expect($helperRuns())->toBeLessThanOrEqual(1);

    // Tab switches are view-state changes; inside the cache window they must
    // never re-query the kernel. (Every helper round-trip here used to cost
    // multiple seconds on a live PBX, which made the tabs feel dead.)
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'firewall-rules')
        ->assertOk()
        ->set('activeTab', 'attackers')
        ->assertOk();

    expect($helperRuns())->toBeLessThanOrEqual(1);
});
