<?php

declare(strict_types=1);

use App\Contracts\ProcessRunner;
use App\Services\GitUpdateService;
use App\Support\ProcessResult;
use App\Support\SystemProcessRunner;

beforeEach(function (): void {
    // The service reads from the actual git repository at base_path().
});

it('detects the current branch', function (): void {
    $service = app(GitUpdateService::class);
    $branch = $service->currentBranch();

    expect($branch)->toBeString()->not->toBeEmpty();
});

it('detects clean git state when no changes are pending', function (): void {
    $service = app(GitUpdateService::class);
    $isClean = $service->isClean();

    // In CI or committed state, this should be true
    expect($isClean)->toBeBool();
});

it('lists available branches from the remote', function (): void {
    $service = app(GitUpdateService::class);
    $branches = $service->remoteBranches();

    expect($branches)->toBeArray();
    // Should at least include 'main' or 'master'
    expect($branches)->not->toBeEmpty();
});

it('lists available tags', function (): void {
    $service = app(GitUpdateService::class);
    $tags = $service->remoteTags();

    expect($tags)->toBeArray();
});

it('returns the remote URL', function (): void {
    $service = app(GitUpdateService::class);
    $remote = $service->remoteUrl();

    expect($remote)->toBeString()->not->toBeEmpty();
    expect($remote)->toContain('github.com');
});

it('can perform a dry-run fetch', function (): void {
    $service = app(GitUpdateService::class);
    $result = $service->fetch();

    expect($result)->toBeBool();
});

it('returns empty string when currentBranch git command fails', function (): void {
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->andReturn(new ProcessResult(128, 'fatal: not a git repository'));
    $service = new GitUpdateService($runner);

    expect($service->currentBranch())->toBe('');
});

it('returns empty string when remoteUrl git command fails', function (): void {
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->andReturn(new ProcessResult(128, 'fatal: not a git repository'));
    $service = new GitUpdateService($runner);

    expect($service->remoteUrl())->toBe('');
});

it('returns false when isClean git command fails', function (): void {
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->andReturn(new ProcessResult(128, 'fatal: not a git repository'));
    $service = new GitUpdateService($runner);

    expect($service->isClean())->toBeFalse();
});

it('categorizes branches into stable, development, and other', function (): void {
    $runner = Mockery::mock(ProcessRunner::class);
    $service = new GitUpdateService($runner);

    $branches = [
        'main',
        'master',
        'stable',
        '1.0',
        'v1.1',
        '2.0',
        '3.x',
        '4.x',
        'release/2.0',
        'feature/cool-stuff',
        'codex/branch',
    ];

    $categorized = $service->categorizeBranches($branches);

    expect($categorized['development'])->toContain('main', 'master')
        ->and($categorized['stable'])->toContain('stable', '4.x', '3.x', '2.0', '1.0', 'v1.1', 'release/2.0')
        ->and($categorized['other'])->toContain('feature/cool-stuff', 'codex/branch')
        ->and($categorized['stable'][0])->toBe('stable')
        ->and($categorized['stable'][1])->toBe('4.x')
        ->and($categorized['stable'][2])->toBe('3.x');
});

it('returns incoming commits parsed correctly', function (): void {
    $runner = Mockery::mock(ProcessRunner::class);
    $output = "abc1234|Jane Doe|2 hours ago|Fix telephony routing\ndef5678|John Smith|1 day ago|Add PBX feature\n";
    $runner->shouldReceive('run')
        ->once()
        ->andReturn(new ProcessResult(0, $output));

    $service = new GitUpdateService($runner);
    $commits = $service->incomingCommits('main', 5);

    expect($commits)->toHaveCount(2)
        ->and($commits[0]['hash'])->toBe('abc1234')
        ->and($commits[0]['author'])->toBe('Jane Doe')
        ->and($commits[0]['time'])->toBe('2 hours ago')
        ->and($commits[0]['message'])->toBe('Fix telephony routing');
});

it('returns commits behind count', function (): void {
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')
        ->once()
        ->andReturn(new ProcessResult(0, "4\n"));

    $service = new GitUpdateService($runner);
    expect($service->commitsBehind('main'))->toBe(4);
});

it('executes checkout -B when pulling a target different from current branch', function (): void {
    $runner = Mockery::mock(ProcessRunner::class);
    // 1. currentBranch check
    $runner->shouldReceive('run')
        ->with(Mockery::on(fn (array $argv): bool => in_array('branch', $argv, true)))
        ->andReturn(new ProcessResult(0, "origin/main\norigin/1.0\n"));
    $runner->shouldReceive('run')
        ->with(Mockery::on(fn (array $argv): bool => in_array('tag', $argv, true)))
        ->andReturn(new ProcessResult(0, ''));
    $runner->shouldReceive('run')
        ->with(Mockery::on(fn (array $argv): bool => in_array('--abbrev-ref', $argv, true)))
        ->andReturn(new ProcessResult(0, "main\n"));
    $runner->shouldReceive('run')
        ->with(Mockery::on(fn (array $argv): bool => in_array('checkout', $argv, true) && in_array('1.0', $argv, true) && in_array('origin/1.0', $argv, true)))
        ->once()
        ->andReturn(new ProcessResult(0, "Switched to a new branch '1.0'\n"));

    $service = new GitUpdateService($runner);
    $result = $service->pull('1.0');

    expect($result)->toBeTrue();
});

describe('system process runner argv execution', function (): void {
    it('executes argv array commands and returns success', function (): void {
        $runner = new SystemProcessRunner;
        $result = $runner->run(['git', 'status', '--porcelain']);

        expect($result->exitCode)->toBe(0)
            ->and($result->output)->toBeString();
    });

    it('preserves shell metacharacters as literal inert arguments', function (): void {
        $testFile = '/tmp/fake_danger_'.uniqid();
        if (file_exists($testFile)) {
            unlink($testFile);
        }

        $runner = new SystemProcessRunner;
        $result = $runner->run(['echo', '; touch '.$testFile]);

        expect($result->exitCode)->toBe(0)
            ->and($result->output)->toContain('; touch '.$testFile)
            ->and(file_exists($testFile))->toBeFalse();
    });

    it('returns exit code 124 when a command exceeds timeout', function (): void {
        $runner = new SystemProcessRunner;
        $result = $runner->run(['sleep', '2'], timeoutSeconds: 1);

        expect($result->exitCode)->toBe(124)
            ->and($result->output)->toContain('timed out');
    });

    it('passes standard environment variables to the child process', function (): void {
        $runner = new SystemProcessRunner;
        $result = $runner->run([
            'php',
            '-r',
            'echo getenv("COMPOSER_NO_INTERACTION")."|".getenv("COMPOSER_ALLOW_SUPERUSER")."|".getenv("GIT_SSH_COMMAND");',
        ]);

        expect($result->exitCode)->toBe(0)
            ->and($result->output)->toContain('1|1|ssh -o StrictHostKeyChecking=accept-new');
    });
});
