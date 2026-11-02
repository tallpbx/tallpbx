<?php

declare(strict_types=1);

/*
 * Redis holds undigested CDR stream entries (database 3) between the HTTP
 * hand-off from FreeSWITCH and the worker's MariaDB commit. A restart without
 * append-only persistence discards that window, and the kernel's heuristic
 * overcommit policy can make Redis refuse every write after a failed snapshot.
 * These assertions guard the installer wiring that prevents both failures.
 */

/**
 * Run one helper from the Redis durability script and return its output.
 *
 * @param  string  $command  Shell snippet naming the helper and its arguments
 */
function runRedisDurabilityHelper(string $command): string
{
    $script = base_path('scripts/resources/redis-durability.sh');

    return (string) shell_exec(sprintf('bash -c %s 2>&1', escapeshellarg('. "'.$script.'"; '.$command)));
}

/**
 * Create a throwaway file and return its path, registering cleanup.
 */
function redisDurabilityFixture(string $contents): string
{
    $path = sys_get_temp_dir().'/redis-durability-'.uniqid();
    file_put_contents($path, $contents);

    return $path;
}

it('enables append-only persistence with a one-second fsync window', function (): void {
    // Mirrors the commented-out defaults the Debian package ships, plus the
    // write-refusal setting that must stay enabled.
    $conf = redisDurabilityFixture(<<<'CONF'
# appendonly no
# appendfsync everysec
stop-writes-on-bgsave-error yes
CONF);

    runRedisDurabilityHelper(sprintf('configure_redis_durability %s', escapeshellarg($conf)));

    $contents = (string) file_get_contents($conf);

    expect($contents)->toContain("appendonly yes\n")
        ->and($contents)->toContain("appendfsync everysec\n")
        ->and($contents)->not->toContain('appendonly no')
        ->and($contents)->toContain('stop-writes-on-bgsave-error yes');
});

it('leaves one line per setting when the installer runs again', function (): void {
    $conf = redisDurabilityFixture(<<<'CONF'
# appendonly no
# appendfsync everysec
stop-writes-on-bgsave-error yes
CONF);

    $command = sprintf('configure_redis_durability %s', escapeshellarg($conf));
    runRedisDurabilityHelper($command);
    runRedisDurabilityHelper($command);

    $contents = (string) file_get_contents($conf);

    expect($contents)->toContain("appendonly yes\n")
        ->and($contents)->toContain("appendfsync everysec\n")
        ->and(substr_count($contents, 'appendonly'))->toBe(1)
        ->and(substr_count($contents, 'appendfsync'))->toBe(1)
        ->and(substr_count($contents, 'stop-writes-on-bgsave-error'))->toBe(1);
});

it('restores write refusal after a failed snapshot instead of accepting lost writes', function (): void {
    // An operator who disabled the refusal would make Redis accept a record it
    // cannot persist; a refused write answers 503 and spools to disk instead.
    $conf = redisDurabilityFixture("appendonly no\nstop-writes-on-bgsave-error no\n");

    runRedisDurabilityHelper(sprintf('configure_redis_durability %s', escapeshellarg($conf)));

    expect((string) file_get_contents($conf))
        ->toContain('stop-writes-on-bgsave-error yes')
        ->not->toContain('stop-writes-on-bgsave-error no');
});

it('writes the always-overcommit kernel setting once', function (): void {
    $dropIn = sys_get_temp_dir().'/tallpbx-overcommit-'.uniqid().'.conf';

    $command = sprintf('configure_redis_overcommit %s', escapeshellarg($dropIn));
    runRedisDurabilityHelper($command);
    runRedisDurabilityHelper($command);

    $contents = (string) file_get_contents($dropIn);

    expect($contents)->toContain('vm.overcommit_memory = 1')
        ->and(substr_count($contents, 'vm.overcommit_memory'))->toBe(1);
});

it('verifies the overcommit setting from the kernel view', function (): void {
    $enabled = redisDurabilityFixture("1\n");
    $disabled = redisDurabilityFixture("0\n");

    expect(trim(runRedisDurabilityHelper(sprintf('verify_redis_overcommit %s', escapeshellarg($enabled)))))->toBe('')
        ->and(runRedisDurabilityHelper(sprintf('verify_redis_overcommit %s', escapeshellarg($disabled))))->toContain('expected 1');
});

it('verifies append-only persistence from the running server', function (): void {
    // A stand-in for redis-cli keeps the assertion independent of a live server.
    $yesCli = redisDurabilityFixture("#!/bin/sh\necho yes\n");
    $noCli = redisDurabilityFixture("#!/bin/sh\necho no\n");
    chmod($yesCli, 0755);
    chmod($noCli, 0755);

    expect(trim(runRedisDurabilityHelper(sprintf('verify_redis_appendonly %s', escapeshellarg($yesCli)))))->toBe('')
        ->and(runRedisDurabilityHelper(sprintf('verify_redis_appendonly %s', escapeshellarg($noCli))))->toContain('expected yes');
});

it('wires the durability helpers into the PHP installer step', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/php.sh'));
    $durabilityPosition = strpos($script, 'configure_redis_durability /etc/redis/redis.conf');
    $restartPosition = strpos($script, 'systemctl restart redis-server');

    expect($script)->toContain('. ./redis-durability.sh')
        ->and($script)->toContain('configure_redis_durability /etc/redis/redis.conf')
        ->and($script)->toContain('configure_redis_overcommit')
        ->and($script)->toContain('apply_redis_overcommit')
        ->and($script)->toContain('verify_redis_overcommit')
        ->and($script)->toContain('verify_redis_appendonly');

    // Durability must be written before the restart picks it up.
    expect($durabilityPosition)->not->toBeFalse()
        ->and($restartPosition)->not->toBeFalse()
        ->and($durabilityPosition)->toBeLessThan($restartPosition);
});
