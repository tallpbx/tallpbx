<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * The worker-restart helper is the only privileged action the web updater can
 * take. It accepts no arguments, names a compiled-in unit allowlist, and
 * never touches a core service. These checks are static or run against a fake
 * systemctl; the live systemd state is never touched.
 */

it('ships a syntactically valid helper and installer', function (): void {
    foreach (['scripts/resources/tallpbx-update-workers', 'scripts/resources/install-update-workers.sh'] as $script) {
        $process = new Process(['bash', '-n', base_path($script)]);
        $process->run();

        expect($process->getExitCode())->toBe(0, $script.': '.$process->getErrorOutput());
    }
});

it('refuses every argument before touching systemctl', function (): void {
    $process = new Process(['bash', base_path('scripts/resources/tallpbx-update-workers'), 'mariadb']);
    $process->run();

    expect($process->getExitCode())->toBe(64);
});

it('reports an optional XML worker restart failure without failing required workers', function (): void {
    // The exported shell function intercepts every systemctl call. This test
    // does not inspect or change any live service on the host.
    $script = base_path('scripts/resources/tallpbx-update-workers');
    $process = new Process(['bash', '-c', 'systemctl() { if [ "$1" = restart ] && [ "$2" = tallpbx-xml-worker ]; then return 1; fi; return 0; }; export -f systemctl; bash "$1"', 'test', $script]);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('warning tallpbx-xml-worker')
        ->not->toContain('failed tallpbx-xml-worker');
});

it('restarts exactly the allowlisted PHP units and never a core service', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/tallpbx-update-workers'));

    foreach (['freeswitch-listener', 'tallpbx-queue', 'tallpbx-scheduler', 'tallpbx-cdr-worker', 'tallpbx-reverb'] as $unit) {
        expect($script)->toContain($unit);
    }

    // Optional unit is restarted only when it was already active.
    expect($script)->toContain('xml_worker_was_active')
        ->and($script)->toContain('skipped $unit');

    foreach (['mariadb', 'redis-server', 'nginx', 'php-fpm'] as $forbidden) {
        expect($script)->not->toContain('restart "'.$forbidden.'"');
    }
});

it('forbids arguments through the sudoers drop-in', function (): void {
    $sudoers = (string) file_get_contents(base_path('scripts/resources/tallpbx-update-workers.sudoers'));

    expect($sudoers)->toContain('/usr/local/sbin/tallpbx-update-workers ""')
        ->and($sudoers)->toContain('NOPASSWD:');
});

it('installs the helper root-owned and validates the sudoers drop-in', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/resources/install-update-workers.sh'));

    expect($installer)->toContain('install -m 0750 -o root -g www-data')
        ->and($installer)->toContain('install -m 0440 -o root -g root')
        ->and($installer)->toContain('visudo -c -f')
        ->and($installer)->toContain('/usr/local/sbin/tallpbx-update-workers')
        ->and($installer)->toContain('/etc/sudoers.d/tallpbx-update-workers');
});

it('is invoked by the installer next to the other helpers', function (): void {
    expect((string) file_get_contents(base_path('scripts/resources/tall.sh')))
        ->toContain('install-update-workers.sh');
});
