<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Create an empty directory for a health-check fixture.
 *
 * @return string Absolute path to the directory
 */
function cdrHealthDir(): string
{
    $path = sys_get_temp_dir().'/cdr-health-'.Str::random(8);
    mkdir($path);

    return $path;
}

/**
 * Create a temporary file with the given lines.
 *
 * @param  array<int, string>  $lines  Lines to write, newline appended
 * @return string Absolute path to the file
 */
function cdrHealthFile(array $lines): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'cdrhealth');
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

it('passes when the spool is empty, the stream is empty and no post failed', function (): void {
    $spool = cdrHealthDir();
    $log = cdrHealthFile(['2026/11/08 10:00:00 [error] 1#1: connect() failed while connecting to upstream, request: "GET /panel/login HTTP/1.1"']);

    $this->artisan('cdr:health', [
        '--spool-dir' => $spool,
        '--stream' => 'pbx:cdr:stream:test-'.Str::random(6),
        '--nginx-error-log' => $log,
    ])
        ->expectsOutputToContain('spooled_posts=0')
        ->expectsOutputToContain('backlog=0')
        ->expectsOutputToContain('failed_posts=0')
        ->assertSuccessful();

    array_map('unlink', glob($spool.'/*') ?: []);
    rmdir($spool);
    unlink($log);
});

it('fails and names the spool when records are waiting for the drain', function (): void {
    $spool = cdrHealthDir();
    file_put_contents($spool.'/cdr-1.json', '{}');
    file_put_contents($spool.'/cdr-2.json', '{}');

    $this->artisan('cdr:health', [
        '--spool-dir' => $spool,
        '--stream' => 'pbx:cdr:stream:test-'.Str::random(6),
        '--nginx-error-log' => '/nonexistent/nginx-error.log',
    ])
        ->expectsOutputToContain('spooled_posts=2')
        ->assertFailed();

    array_map('unlink', glob($spool.'/*') ?: []);
    rmdir($spool);
});

it('fails when the stream backlog crosses the warning threshold', function (): void {
    $spool = cdrHealthDir();
    $stream = 'pbx:cdr:stream:test-'.Str::random(6);

    $redis = Redis::connection('cdr');
    $redis->xadd($stream, '*', ['payload' => '{}']);
    $redis->xadd($stream, '*', ['payload' => '{}']);

    $this->artisan('cdr:health', [
        '--spool-dir' => $spool,
        '--stream' => $stream,
        '--warn-pending' => 2,
        '--nginx-error-log' => '/nonexistent/nginx-error.log',
    ])
        ->expectsOutputToContain('backlog=2')
        ->assertFailed();

    $redis->del($stream);
    rmdir($spool);
});

it('fails when the nginx log records a failed post to the cdr endpoint', function (): void {
    $spool = cdrHealthDir();
    $log = cdrHealthFile([
        gmdate('Y/m/d H:i:s').' [error] 1#1: upstream timed out (110: Connection timed out) while reading response header from upstream, request: "POST /api/v1/cdr HTTP/1.1", upstream: "fastcgi://unix:/run/php/php8.5-fpm.sock"',
    ]);

    $this->artisan('cdr:health', [
        '--spool-dir' => $spool,
        '--stream' => 'pbx:cdr:stream:test-'.Str::random(6),
        '--nginx-error-log' => $log,
    ])
        ->expectsOutputToContain('failed_posts=1')
        ->assertFailed();

    rmdir($spool);
    unlink($log);
});

it('ignores failed posts older than the window', function (): void {
    // The error log keeps history; an alert that counted every old line would
    // fire for days after a single bad afternoon.
    $spool = cdrHealthDir();
    $log = cdrHealthFile([
        '2026/11/04 07:17:58 [error] 1#1: recv() failed (104: Connection reset by peer) while reading response header from upstream, request: "POST /api/v1/cdr HTTP/1.1"',
    ]);

    $this->artisan('cdr:health', [
        '--spool-dir' => $spool,
        '--stream' => 'pbx:cdr:stream:test-'.Str::random(6),
        '--nginx-error-log' => $log,
    ])
        ->expectsOutputToContain('failed_posts=0')
        ->assertSuccessful();

    rmdir($spool);
    unlink($log);
});
