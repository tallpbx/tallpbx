<?php

declare(strict_types=1);

use App\Contracts\ProcessRunner;
use App\Services\GitUpdateService;
use Tests\Feature\Services\FakeProcessRunner;

function pipelineService(FakeProcessRunner $runner): GitUpdateService
{
    return new GitUpdateService($runner);
}

function pipelineRunner(): FakeProcessRunner
{
    $runner = new FakeProcessRunner;
    // Read-only git ops succeed; lockfiles exist. isClean() checks the
    // porcelain output emptiness, so the clean state returns empty output;
    // the branch list names origin/main so the 'main' target validates.
    $runner->exitCodes = [
        'status --porcelain' => 0,
        'composer validate' => 0,
        'branch -r' => 0,
        'tag -l' => 0,
        'pull --ff-only' => 0,
        'checkout -B' => 0,
        'rev-parse --abbrev-ref HEAD' => 0,
    ];
    $runner->outputs = [
        'status --porcelain' => '',
        'branch -r' => "origin/main\norigin/1.0\n",
        'tag -l' => '',
        'rev-parse --abbrev-ref HEAD' => "main\n",
    ];
    app()->instance(ProcessRunner::class, $runner);

    return $runner;
}

it('runs the full pipeline in order on success', function (): void {
    $runner = pipelineRunner();
    $service = pipelineService($runner);

    $result = $service->update('main');

    expect($result->success)->toBeTrue();

    $joined = implode("\n", $runner->commands);
    // The artisan path is shell-quoted, so match on the argument part.
    $order = ['composer validate', 'pull --ff-only', 'composer install', 'migrate --force', 'npm ci', 'npm run build', 'permissions:repair', 'optimize:clear'];

    $positions = array_map(fn (string $needle): int|false => strpos($joined, $needle), $order);

    // Every step ran, in strictly increasing command order.
    expect($positions)->each->not->toBeFalse();
    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted)
        ->and($result->steps)->toHaveCount(count($order));
});

it('aborts on a dirty working tree before anything changes', function (): void {
    $runner = pipelineRunner();
    // isClean() checks the porcelain output emptiness, not the exit code.
    $runner->outputs['status --porcelain'] = ' M app/foo.php';
    $service = pipelineService($runner);

    $result = $service->update('main');

    expect($result->success)->toBeFalse()
        ->and($result->reason)->toContain('uncommitted')
        ->and(implode("\n", $runner->commands))->not->toContain('pull --ff-only');
});

it('aborts when the target is not a known remote ref', function (): void {
    $runner = pipelineRunner();
    $runner->exitCodes['branch -r'] = 0;
    $runner->exitCodes['tag -l'] = 0;
    $service = pipelineService($runner);

    $result = $service->update('nonexistent-target');

    expect($result->success)->toBeFalse()
        ->and($result->reason)->toContain('target');
});

it('rolls back code and assets when migrate fails', function (): void {
    $runner = pipelineRunner();
    $runner->exitCodes['migrate --force'] = 1;
    $service = pipelineService($runner);

    $result = $service->update('main');

    expect($result->success)->toBeFalse()
        ->and($result->reason)->toContain('Migrate failed');

    $joined = implode("\n", $runner->commands);
    // The rollback re-runs composer install, then rebuilds assets after reset.
    expect($joined)->toContain('reset --hard')
        ->and(substr_count($joined, 'composer install'))->toBeGreaterThanOrEqual(2)
        ->and(strpos($joined, 'reset --hard'))->toBeLessThan(strpos($joined, 'npm run build'));

    // The DB is not rolled back automatically (migrations are not
    // guaranteed reversible; the report tells the admin to reconcile).
    expect($joined)->not->toContain('migrate:rollback')
        ->and($result->rollbackReport)->toContain('manual reconciliation');
});

it('reports a failed rollback step', function (): void {
    $runner = pipelineRunner();
    $runner->exitCodes['migrate --force'] = 1;
    $runner->exitCodes['reset --hard'] = 1; // rollback git fails
    $service = pipelineService($runner);

    $result = $service->update('main');

    expect($result->success)->toBeFalse()
        ->and($result->rollbackReport)->toContain('Rollback failed');
});

it('shell-escapes the target in the pull command', function (): void {
    // Git permits shell metacharacters in ref names, so the validated target
    // must still be shell-quoted before interpolation (defense in depth).
    $runner = pipelineRunner();
    $runner->outputs['branch -r'] = "origin/main;id\n";
    $runner->outputs['rev-parse --abbrev-ref HEAD'] = "main;id\n";
    $service = pipelineService($runner);

    $result = $service->update('main;id');

    expect($result->success)->toBeTrue();
    $pull = collect($runner->commands)->first(fn (string $c): bool => str_contains($c, 'pull --ff-only'));

    expect($pull)->toContain("'main;id'")
        ->not->toContain('origin main;id');
});

it('refuses to run while another update holds the lock', function (): void {
    $runner = pipelineRunner();
    $service = pipelineService($runner);
    $lock = fopen(storage_path('framework/git-update.lock'), 'c');
    flock($lock, LOCK_EX);

    try {
        $result = $service->update('main');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    expect($result->success)->toBeFalse()
        ->and($result->reason)->toContain('already in progress')
        ->and($runner->commands)->toBe([]);
});

it('catches unexpected process failures and rolls back', function (): void {
    $runner = pipelineRunner();
    $runner->throwOn['migrate --force'] = true;
    $service = pipelineService($runner);

    // Must NOT throw out of update(): the exception becomes a failed step
    // and the rollback path runs.
    $result = $service->update('main');

    expect($result->success)->toBeFalse()
        ->and($result->rollbackReport)->toContain('Rolled back')
        ->and(implode("\n", $runner->commands))->toContain('reset --hard');
});

it('does not escape when the rollback itself fails', function (): void {
    $runner = pipelineRunner();
    $runner->throwOn['migrate --force'] = true;
    $runner->throwOn['reset --hard'] = true; // rollback git also throws
    $service = pipelineService($runner);

    $result = $service->update('main');

    expect($result->success)->toBeFalse()
        ->and($result->rollbackReport)->toContain('Rollback itself failed');
});

it('switches branch with checkout -B when updating to a different branch', function (): void {
    $runner = pipelineRunner();
    $service = pipelineService($runner);

    $result = $service->update('1.0');

    expect($result->success)->toBeTrue();

    $joined = implode("\n", $runner->commands);
    expect($joined)->toContain('checkout -B')
        ->and($joined)->toContain('1.0')
        ->and($joined)->not->toContain('pull --ff-only');
});

it('restores original branch on rollback when branch switch fails down the line', function (): void {
    $runner = pipelineRunner();
    $runner->throwOn['migrate --force'] = true;
    $service = pipelineService($runner);

    $result = $service->update('1.0');

    expect($result->success)->toBeFalse();

    $joined = implode("\n", $runner->commands);
    expect($joined)->toContain('checkout \'main\'')
        ->and($joined)->toContain('reset --hard');
});

it('records status file and live log during update', function (): void {
    $runner = pipelineRunner();
    $service = pipelineService($runner);

    $result = $service->update('main');

    expect($result->success)->toBeTrue();

    $status = $service->getStatus();
    expect($status)->not->toBeNull()
        ->and($status['running'])->toBeFalse()
        ->and($status['target'])->toBe('main')
        ->and($status['success'])->toBeTrue()
        ->and($status['steps'])->toHaveCount(count($result->steps));

    $log = $service->getLog();
    expect($log)->toContain('TallPBX Update started')
        ->and($log)->toContain('Pull')
        ->and($log)->toContain('TallPBX Update SUCCEEDED');
});

it('executes the app:git-update console command', function (): void {
    $runner = pipelineRunner();
    $service = pipelineService($runner);
    app()->instance(GitUpdateService::class, $service);

    $this->artisan('app:git-update', ['target' => 'main'])
        ->assertSuccessful()
        ->expectsOutputToContain('Starting TallPBX update for target: main')
        ->expectsOutputToContain('Successfully updated TallPBX to target: main');
});
