<?php

declare(strict_types=1);

use App\Services\GitUpdateService;
use App\Support\GitTarget;
use App\Support\SystemProcessRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * A tag is not a remote branch. The updater must resolve the selected target
 * to an explicit ref before switching, and an unresolvable target must report
 * "unknown" instead of a fabricated zero commits behind.
 */

/**
 * Build a disposable worktree backed by a bare origin with one branch and one tag.
 *
 * @return array{root: string, work: string}
 */
function disposableUpdateRepo(): array
{
    $root = sys_get_temp_dir().'/git-update-target-'.Str::random(8);
    $origin = $root.'/origin.git';
    $work = $root.'/work';

    File::ensureDirectoryExists($root);

    $run = function (array $command): void {
        $process = new Process($command);
        $process->mustRun();
    };

    $run(['git', 'init', '--bare', '--initial-branch=5.x', $origin]);
    $run(['git', 'clone', $origin, $work]);
    $run(['git', '-C', $work, 'config', 'user.email', 'test@example.test']);
    $run(['git', '-C', $work, 'config', 'user.name', 'Test']);
    File::put($work.'/README.md', "update fixture\n");
    $run(['git', '-C', $work, 'add', 'README.md']);
    $run(['git', '-C', $work, 'commit', '-m', 'initial']);
    $run(['git', '-C', $work, 'push', '-u', 'origin', '5.x']);
    $run(['git', '-C', $work, 'tag', 'v9.9.9']);
    $run(['git', '-C', $work, 'push', 'origin', 'v9.9.9']);

    // Re-clone so the worktree tracks the remote branch like production does.
    File::deleteDirectory($work);
    $run(['git', 'clone', $origin, $work]);
    $run(['git', '-C', $work, 'config', 'user.email', 'test@example.test']);
    $run(['git', '-C', $work, 'config', 'user.name', 'Test']);

    return ['root' => $root, 'work' => $work];
}

/**
 * Point a fresh service at one disposable worktree.
 */
function disposableUpdateService(string $work, string $originalBasePath): GitUpdateService
{
    app()->setBasePath($work);

    return new GitUpdateService(new SystemProcessRunner);
}

beforeEach(function (): void {
    $this->originalBasePath = base_path();
});

afterEach(function (): void {
    app()->setBasePath($this->originalBasePath);

    if (isset($this->repoRoot)) {
        File::deleteDirectory($this->repoRoot);
    }
});

it('switches to a tag by commit instead of a nonexistent remote branch', function (): void {
    $repo = disposableUpdateRepo();
    $this->repoRoot = $repo['root'];

    $service = disposableUpdateService($repo['work'], $this->originalBasePath);

    $target = $service->resolveTarget('v9.9.9');

    expect($target)->toBeInstanceOf(GitTarget::class)
        ->and($target->kind)->toBe('tag')
        ->and($target->ref)->toBe('refs/tags/v9.9.9')
        ->and($service->pull('v9.9.9'))->toBeTrue();

    $head = trim((string) shell_exec('git -C '.escapeshellarg($repo['work']).' rev-parse --abbrev-ref HEAD'));
    expect($head)->toBe('HEAD');
});

it('resolves a branch to its remote-tracking ref', function (): void {
    $repo = disposableUpdateRepo();
    $this->repoRoot = $repo['root'];

    $service = disposableUpdateService($repo['work'], $this->originalBasePath);

    expect($service->resolveTarget('5.x'))
        ->toBeInstanceOf(GitTarget::class)
        ->and($service->resolveTarget('5.x')->kind)->toBe('branch')
        ->and($service->resolveTarget('5.x')->ref)->toBe('refs/remotes/origin/5.x');
});

it('reports an unknown target as unresolvable rather than zero commits', function (): void {
    $repo = disposableUpdateRepo();
    $this->repoRoot = $repo['root'];

    $service = disposableUpdateService($repo['work'], $this->originalBasePath);

    expect($service->resolveTarget('does-not-exist'))->toBeNull()
        ->and($service->commitsBehind('does-not-exist'))->toBeNull();
});

it('counts zero incoming commits only for a resolved target', function (): void {
    $repo = disposableUpdateRepo();
    $this->repoRoot = $repo['root'];

    $service = disposableUpdateService($repo['work'], $this->originalBasePath);

    expect($service->commitsBehind('5.x'))->toBe(0)
        ->and($service->incomingCommits('5.x'))->toBe([])
        ->and($service->commitsBehind('v9.9.9'))->toBe(0);
});
