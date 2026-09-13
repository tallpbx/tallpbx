<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Services\GitUpdateService;
use App\Support\UpdateResult;
use Livewire\Livewire;
use Modules\Admin\Livewire\GitUpdate;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);

    $this->gitService = Mockery::mock(GitUpdateService::class);
    $this->gitService->shouldReceive('currentBranch')->andReturn('main')->byDefault();
    $this->gitService->shouldReceive('currentVersion')->andReturn('v1.0.0')->byDefault();
    $this->gitService->shouldReceive('remoteUrl')->andReturn('https://github.com/example/repo.git')->byDefault();
    $this->gitService->shouldReceive('isClean')->andReturn(true)->byDefault();
    $this->gitService->shouldReceive('commitsBehind')->andReturn(0)->byDefault();
    $this->gitService->shouldReceive('incomingCommits')->andReturn([])->byDefault();
    $this->gitService->shouldReceive('remoteBranches')->andReturn(['main', '1.0'])->byDefault();
    $this->gitService->shouldReceive('remoteTags')->andReturn([])->byDefault();
    $this->gitService->shouldReceive('categorizeBranches')->andReturn([
        'stable' => ['1.0'],
        'development' => ['main'],
        'other' => [],
    ])->byDefault();
    $this->gitService->shouldReceive('getStatus')->andReturn(null)->byDefault();
    $this->gitService->shouldReceive('getLog')->andReturn('')->byDefault();
    app()->instance(GitUpdateService::class, $this->gitService);
});

it('opens the typed confirmation modal before updating', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(GitUpdate::class)
        ->call('confirmUpdate')
        ->assertSet('confirmingUpdate', true)
        ->assertSee('Update TallPBX?')
        ->assertSeeHtml('type="text"');
});

it('does not pull when the typed text does not match', function (): void {
    $this->gitService->shouldNotReceive('update');

    Livewire::actingAs($this->admin, 'admin')
        ->test(GitUpdate::class)
        ->call('confirmUpdate')
        ->set('confirmTypedInput', 'update')
        ->call('updateApp')
        ->assertSet('updateConfirmationError', 'The typed text does not match. Nothing was changed.')
        ->assertSet('updateSuccess', false);
});

it('updates when the typed text matches', function (): void {
    $this->gitService->shouldReceive('update')->once()->with('main')->andReturn(
        UpdateResult::success('main', [
            ['label' => 'Pull', 'status' => 'ok', 'output' => ''],
        ]),
    );

    Livewire::actingAs($this->admin, 'admin')
        ->test(GitUpdate::class)
        ->call('confirmUpdate')
        ->set('confirmTypedInput', 'UPDATE')
        ->call('updateApp')
        ->assertSet('updateSuccess', true)
        ->assertSet('confirmingUpdate', false);
});

it('renders the update step checklist after a successful update', function (): void {
    $this->gitService->shouldReceive('update')->once()->with('main')->andReturn(
        UpdateResult::success('main', [
            ['label' => 'Pull', 'status' => 'ok', 'output' => 'Up to date.'],
            ['label' => 'Migrate', 'status' => 'ok', 'output' => 'Nothing to migrate.'],
        ]),
    );

    Livewire::actingAs($this->admin, 'admin')
        ->test(GitUpdate::class)
        ->call('confirmUpdate')
        ->set('confirmTypedInput', 'UPDATE')
        ->call('updateApp')
        ->assertSet('updateSuccess', true)
        ->assertSet('updating', false)
        ->assertSet('updateSteps', fn (array $steps): bool => count($steps) === 2 && $steps[0]['status'] === 'ok')
        ->assertSee('Pull')
        ->assertSee('Migrate');
});

it('surfaces the rollback report when the update fails', function (): void {
    $this->gitService->shouldReceive('update')->once()->with('main')->andReturn(
        UpdateResult::failed('main', 'Migrate failed.', [
            ['label' => 'Pull', 'status' => 'ok', 'output' => ''],
            ['label' => 'Migrate', 'status' => 'failed', 'output' => 'error'],
        ], 'Rolled back to abc123.'),
    );

    Livewire::actingAs($this->admin, 'admin')
        ->test(GitUpdate::class)
        ->call('confirmUpdate')
        ->set('confirmTypedInput', 'UPDATE')
        ->call('updateApp')
        ->assertSet('updateSuccess', false)
        ->assertSet('rollbackReport', 'Rolled back to abc123.')
        ->assertSee('Rolled back to abc123.');
});

it('does not start a second pipeline while one is running', function (): void {
    $this->gitService->shouldNotReceive('update');

    Livewire::actingAs($this->admin, 'admin')
        ->test(GitUpdate::class)
        ->set('confirmTypedInput', 'UPDATE')
        ->set('updating', true)
        ->call('updateApp')
        ->assertSet('updating', true);
});

it('categorizes branches and previews commits upon fetch', function (): void {
    $this->gitService->shouldReceive('fetch')->once()->andReturn(true);
    $this->gitService->shouldReceive('remoteBranches')->andReturn(['main', '1.0', 'feature/test']);
    $this->gitService->shouldReceive('remoteTags')->andReturn(['v1.0.0']);
    $this->gitService->shouldReceive('categorizeBranches')->andReturn([
        'stable' => ['1.0'],
        'development' => ['main'],
        'other' => ['feature/test'],
    ]);
    $this->gitService->shouldReceive('commitsBehind')->with('main')->andReturn(2);
    $this->gitService->shouldReceive('incomingCommits')->with('main', 5)->andReturn([
        ['hash' => 'abc1234', 'author' => 'Developer', 'time' => '10m ago', 'message' => 'New feature'],
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GitUpdate::class)
        ->call('fetch')
        ->assertSet('fetchDone', true)
        ->assertSet('stableBranches', ['1.0'])
        ->assertSet('developmentBranches', ['main'])
        ->assertSet('commitsBehind', 2)
        ->assertSee('New feature')
        ->assertSee('Stable Release');
});

it('switches channel and updates target branch', function (): void {
    $this->gitService->shouldReceive('fetch')->andReturn(true);
    $this->gitService->shouldReceive('remoteBranches')->andReturn(['main', '1.0']);
    $this->gitService->shouldReceive('remoteTags')->andReturn([]);
    $this->gitService->shouldReceive('categorizeBranches')->andReturn([
        'stable' => ['1.0'],
        'development' => ['main'],
        'other' => [],
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GitUpdate::class)
        ->call('fetch')
        ->call('selectChannel', 'stable')
        ->assertSet('selectedChannel', 'stable')
        ->assertSet('selectedTarget', '1.0');
});

it('polls and updates terminal output while an update runs', function (): void {
    $this->gitService->shouldReceive('getStatus')->andReturn([
        'running' => true,
        'target' => 'main',
        'current_step' => 'Composer install',
        'steps' => [
            ['label' => 'Pull', 'status' => 'ok', 'output' => 'Up to date.'],
        ],
        'success' => null,
        'reason' => null,
        'rollback_report' => null,
    ]);
    $this->gitService->shouldReceive('getLog')->andReturn("Pull: OK\nRunning Composer install...");

    Livewire::actingAs($this->admin, 'admin')
        ->test(GitUpdate::class)
        ->call('pollProgress')
        ->assertSet('updating', true)
        ->assertSet('currentStep', 'Composer install')
        ->assertSet('terminalOutput', "Pull: OK\nRunning Composer install...")
        ->assertSet('updateSteps', fn (array $steps): bool => count($steps) === 1 && $steps[0]['label'] === 'Pull');
});

it('finalizes state when background update completes', function (): void {
    $this->gitService->shouldReceive('getStatus')->andReturn([
        'running' => false,
        'target' => 'main',
        'current_step' => null,
        'steps' => [
            ['label' => 'Pull', 'status' => 'ok', 'output' => ''],
            ['label' => 'Optimize clear', 'status' => 'ok', 'output' => ''],
        ],
        'success' => true,
        'reason' => null,
        'rollback_report' => null,
    ]);
    $this->gitService->shouldReceive('getLog')->andReturn('TallPBX Update SUCCEEDED');

    Livewire::actingAs($this->admin, 'admin')
        ->test(GitUpdate::class)
        ->call('pollProgress')
        ->assertSet('updating', false)
        ->assertSet('updateSuccess', true)
        ->assertSet('terminalOutput', 'TallPBX Update SUCCEEDED')
        ->assertSet('updateSteps', fn (array $steps): bool => count($steps) === 2);
});
