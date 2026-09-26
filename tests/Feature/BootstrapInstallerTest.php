<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('ships an active bootstrap script and retires the reference template', function (): void {
    expect(base_path('scripts/bootstrap.sh'))->toBeFile()
        ->and(base_path('scripts/bootstrap.sh.example'))->not->toBeFile();
});

it('keeps the bootstrap safe for piped one-line installs', function (): void {
    $bootstrap = (string) file_get_contents(base_path('scripts/bootstrap.sh'));
    $rootCheckPosition = strpos($bootstrap, 'EUID');
    $clonePosition = strpos($bootstrap, 'git clone --branch');

    expect($bootstrap)->toContain('set -euo pipefail')
        ->and($bootstrap)->toContain('The TallPBX bootstrap must run as root')
        ->and($bootstrap)->toContain('requested_ref="2.0"')
        ->and($bootstrap)->toContain('requested_ref="$2"')
        ->and($bootstrap)->toContain('git ls-remote --exit-code')
        ->and($bootstrap)->toContain('git -C "$application_root" merge --ff-only')
        ->and($bootstrap)->toContain('git -C "$application_root" status --porcelain')
        ->and($bootstrap)->toContain('exists but is not a Git working copy of TallPBX')
        ->and($bootstrap)->toContain('</dev/tty')
        ->and($bootstrap)->toContain('exec bash "$application_root/scripts/install.sh"')
        ->and($bootstrap)->toContain('--no-demo|--no-development')
        ->and($bootstrap)->toContain('Unknown bootstrap option:')
        ->and($rootCheckPosition)->not->toBeFalse()
        ->and($clonePosition)->not->toBeFalse()
        ->and($rootCheckPosition)->toBeLessThan($clonePosition);
});

it('classifies the application folder before touching it', function (): void {
    $root = sys_get_temp_dir().'/pbx-bootstrap-state-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);

    $bootstrap = escapeshellarg(base_path('scripts/bootstrap.sh'));
    $stateOf = static function (string $path) use ($bootstrap): string {
        $process = new Process(['bash', '-c',
            'FSPBX_BOOTSTRAP_LIB_ONLY=true; source '.$bootstrap.'; '
            .'application_root='.escapeshellarg($path).'; bootstrap_target_state',
        ], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        return trim($process->getOutput());
    };

    try {
        // No folder at all, then an empty folder: both are "missing".
        expect($stateOf($root.'/absent'))->toBe('missing');

        mkdir($root.'/empty', 0700);
        expect($stateOf($root.'/empty'))->toBe('missing');

        // A non-empty folder without Git metadata is "blocked".
        mkdir($root.'/blocked', 0700);
        file_put_contents($root.'/blocked/important.txt', "keep me\n");
        expect($stateOf($root.'/blocked'))->toBe('blocked');

        // A folder carrying .git is an existing working copy.
        mkdir($root.'/working/.git', 0700, true);
        expect($stateOf($root.'/working'))->toBe('git');
    } finally {
        File::deleteDirectory($root);
    }
});

it('rejects unsafe ref names before any git operation', function (): void {
    $bootstrap = escapeshellarg(base_path('scripts/bootstrap.sh'));

    foreach (['bad ref', '-oops', 'bad;ref', 'ref$HOME'] as $invalidRef) {
        $process = new Process(['bash', '-c',
            'FSPBX_BOOTSTRAP_LIB_ONLY=true; source '.$bootstrap.'; '
            .'requested_ref='.escapeshellarg($invalidRef).'; validate_ref',
        ], base_path());
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('Invalid --ref value');
    }
});

it('refuses to update a working copy that carries local changes', function (): void {
    $repository = sys_get_temp_dir().'/pbx-bootstrap-repo-'.bin2hex(random_bytes(8));
    mkdir($repository, 0700);

    $run = static function (array $command): void {
        $process = new Process($command);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    };

    try {
        // Build a minimal Git working copy so the safety rules run for real.
        $run(['git', 'init', '-q', '-b', '1.1', $repository]);
        $run(['git', '-C', $repository, 'config', 'user.email', 'tests@tallpbx.local']);
        $run(['git', '-C', $repository, 'config', 'user.name', 'TallPBX Tests']);
        file_put_contents($repository.'/tracked.txt', "initial\n");
        $run(['git', '-C', $repository, 'add', 'tracked.txt']);
        $run(['git', '-C', $repository, 'commit', '-q', '-m', 'initial']);

        // A local change must stop the bootstrap before any fetch or merge.
        file_put_contents($repository.'/tracked.txt', "changed\n");

        $bootstrap = escapeshellarg(base_path('scripts/bootstrap.sh'));
        $process = new Process(['bash', '-c',
            'FSPBX_BOOTSTRAP_LIB_ONLY=true; source '.$bootstrap.'; '
            .'application_root='.escapeshellarg($repository).'; requested_ref=1.1; prepare_working_copy',
        ], base_path());
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('has local changes');
    } finally {
        File::deleteDirectory($repository);
    }
});

it('refuses to switch refs when local commits exist on no remote branch', function (): void {
    $repository = sys_get_temp_dir().'/pbx-bootstrap-orphan-'.bin2hex(random_bytes(8));
    mkdir($repository, 0700);

    $run = static function (array $command): void {
        $process = new Process($command);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    };

    try {
        // Build a working copy on branch 1.1 with a local commit and an origin
        // remote that was never fetched, so the commit exists on no remote ref.
        $run(['git', 'init', '-q', '-b', '1.1', $repository]);
        $run(['git', '-C', $repository, 'config', 'user.email', 'tests@tallpbx.local']);
        $run(['git', '-C', $repository, 'config', 'user.name', 'TallPBX Tests']);
        file_put_contents($repository.'/tracked.txt', "initial\n");
        $run(['git', '-C', $repository, 'add', 'tracked.txt']);
        $run(['git', '-C', $repository, 'commit', '-q', '-m', 'initial']);
        $run(['git', '-C', $repository, 'remote', 'add', 'origin', 'https://example.invalid/tallpbx.git']);

        file_put_contents($repository.'/tracked.txt', "second\n");
        $run(['git', '-C', $repository, 'commit', '-q', '-am', 'local work']);

        $bootstrap = escapeshellarg(base_path('scripts/bootstrap.sh'));
        $process = new Process(['bash', '-c',
            'FSPBX_BOOTSTRAP_LIB_ONLY=true; source '.$bootstrap.'; '
            .'application_root='.escapeshellarg($repository).'; requested_ref=main; prepare_working_copy',
        ], base_path());
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('not on any remote branch');
    } finally {
        File::deleteDirectory($repository);
    }
});

it('refuses to touch a folder that is not a git working copy', function (): void {
    $root = sys_get_temp_dir().'/pbx-bootstrap-blocked-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    file_put_contents($root.'/important.txt', "do not delete\n");

    $bootstrap = escapeshellarg(base_path('scripts/bootstrap.sh'));
    $process = new Process(['bash', '-c',
        'FSPBX_BOOTSTRAP_LIB_ONLY=true; source '.$bootstrap.'; '
        .'application_root='.escapeshellarg($root).'; prepare_working_copy',
    ], base_path());
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('is not a Git working copy of TallPBX')
        ->and($root.'/important.txt')->toBeFile();

    File::deleteDirectory($root);
});

it('presents the one-line install as the primary method', function (): void {
    $install = (string) file_get_contents(base_path('INSTALL.md'));

    expect($install)->toContain('wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/2.0/scripts/bootstrap.sh | bash')
        ->and($install)->not->toContain('curl -fsSL')
        ->and($install)->not->toContain('sha256sum')
        ->and($install)->not->toContain('Verified Installation')
        ->and($install)->toContain('same `--ref` value every time')
        ->and($install)->not->toContain('VMware or VirtualBox')
        ->and($install)->not->toContain('Manual Installation')
        ->and($install)->not->toContain('A headless run')
        ->and($install)->not->toContain('Installer Questionnaire')
        ->and($install)->not->toContain('sets up PHP 8.5')
        ->and($install)->toContain('in-memory storage')
        ->and($install)->not->toContain('temporary storage')
        ->and($install)->not->toContain('Bridged or Host-Only')
        ->and($install)->toContain('docs/operations.md')
        ->and($install)->not->toContain('systemctl status <name>')
        ->and($install)->not->toContain('app:test')
        ->and($install)->toContain('To customize the installation, add options after `-s --`')
        ->and($install)->toContain('# Pin the stable 1.1 release branch instead of the default 2.0:')
        ->and($install)->toContain('bash -s -- --ref 1.1')
        ->and($install)->not->toContain('bootstrap.sh.example')
        ->and($install)->not->toContain('(Roadmap)')
        ->and($install)->not->toContain('bash ./scripts/install.sh --no-demo');
});

it('documents service management in the operations guide', function (): void {
    $operations = (string) file_get_contents(base_path('docs/operations.md'));

    expect($operations)->toContain('## Service Management')
        ->and($operations)->toContain('systemctl status <name>')
        ->and($operations)->toContain('tallpbx-queue')
        ->and($operations)->toContain('In-memory storage')
        ->and($operations)->toContain('php artisan app:test --smoke')
        ->and($operations)->toContain('php artisan app:test --full')
        ->and($operations)->toContain('php artisan app:test --sequential');
});
