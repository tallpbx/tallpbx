<?php

declare(strict_types=1);

/*
 * mod_json_cdr is the ingestion source of the CDR pipeline: one JSON document
 * per call leg, HTTP-POSTed to the application. These assertions guard the
 * deployment wiring: the versioned template, the per-install token, the
 * failure-only spool directories, and the module/package installation.
 *
 * Verified against the FreeSWITCH module reference:
 *   - encode=false posts the raw JSON document,
 *   - encode-values=false keeps string values readable instead of percent-encoded,
 *   - omitting log-dir disables disk logging when a url is set, so only failed
 *     deliveries reach err-log-dir,
 *   - the module appends ?uuid=<uuid> to the URL itself, so the shared secret
 *     travels in the Basic auth header (cred), not the query string.
 */

/**
 * Run one helper from the JSON CDR script and return its output.
 *
 * @param  string  $command  Shell snippet naming the helper and its arguments
 */
function runJsonCdrHelper(string $command): string
{
    $script = base_path('scripts/resources/freeswitch-json-cdr.sh');

    return (string) shell_exec(sprintf('bash -c %s 2>&1', escapeshellarg('. "'.$script.'"; '.$command)));
}

it('ships a json_cdr template with raw bodies, both legs, and a failure spool', function (): void {
    $template = (string) file_get_contents(base_path('scripts/freeswitch/json_cdr.conf.xml'));

    expect($template)->toContain('http://127.0.0.1/api/v1/cdr')
        ->and($template)->toContain('name="cred" value="tallpbx:__TALLPBX_CDR_TOKEN__"')
        ->and($template)->toContain('name="encode" value="false"')
        ->and($template)->toContain('name="encode-values" value="false"')
        ->and($template)->toContain('name="log-b-leg" value="true"')
        ->and($template)->toContain('name="retries" value="3"')
        ->and($template)->toContain('name="delay" value="1"')
        ->and($template)->toContain('name="err-log-dir" value="/var/log/freeswitch/json_cdr/failed"')
        // Successful posts must never touch disk, so the template carries no
        // log-dir at all; only the failure destination is configured.
        ->and($template)->not->toMatch('/name="log-dir"/')
        // A literal token here would publish the ingestion credential.
        ->and($template)->not->toMatch('/[0-9a-f]{64}/');
});

it('generates the cdr token in the app environment step', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/tall.sh'));

    expect($script)->toContain('FREESWITCH_CDR_TOKEN=$(php -r \'echo bin2hex(random_bytes(32));\')')
        // The key is appended when missing and refilled when present but empty,
        // mirroring the FS_XML_HANDLER_TOKEN provisioning block.
        ->and($script)->toMatch('/grep -q "\^FREESWITCH_CDR_TOKEN=" \.env/')
        ->and($script)->toMatch('/s\/\^FREESWITCH_CDR_TOKEN=\.\*\//');
});

it('installs and configures mod_json_cdr from the freeswitch step', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/freeswitch.sh'));

    expect($script)->toContain('. ./freeswitch-json-cdr.sh')
        ->and($script)->toContain('configure_json_cdr()')
        // Called from both the configure-only path and the full install path.
        ->and(substr_count($script, 'configure_json_cdr'))->toBeGreaterThanOrEqual(3)
        ->and($script)->toContain('apt_get_with_lock_wait install -y freeswitch-mod-json-cdr')
        ->and($script)->toContain('ensure_freeswitch_module_enabled "$modules_conf" "mod_json_cdr"')
        ->and($script)->toContain('ensure_json_cdr_spool_dirs')
        ->and($script)->toContain('render_json_cdr_config')
        // No configuration is deployed without a token: the rendered file
        // would otherwise authenticate nobody and every post would 401.
        ->and($script)->toContain('FREESWITCH_CDR_TOKEN is empty; skipping json_cdr.conf.xml generation');
});

it('renders the versioned template with the token and refuses an empty one', function (): void {
    $template = base_path('scripts/freeswitch/json_cdr.conf.xml');
    $target = sys_get_temp_dir().'/json-cdr-'.uniqid().'.conf';

    $rendered = runJsonCdrHelper(sprintf(
        'render_json_cdr_config %s %s %s',
        escapeshellarg($template),
        escapeshellarg($target),
        escapeshellarg(str_repeat('a', 64)),
    ));
    $contents = (string) file_get_contents($target);

    expect($rendered)->toBe('')
        ->and($contents)->toContain('tallpbx:'.str_repeat('a', 64))
        ->and($contents)->not->toContain('__TALLPBX_CDR_TOKEN__');

    // Fail closed: an empty token must not deploy an unauthenticated file.
    $refusedTarget = $target.'.refused';
    $refused = runJsonCdrHelper(sprintf(
        'render_json_cdr_config %s %s ""',
        escapeshellarg($template),
        escapeshellarg($refusedTarget),
    ));

    expect($refused)->toContain('refusing')
        ->and(file_exists($refusedTarget))->toBeFalse();
});

it('creates the spool and quarantine directories idempotently', function (): void {
    $root = sys_get_temp_dir().'/json-cdr-spool-'.uniqid();
    $command = sprintf('ensure_json_cdr_spool_dirs %s', escapeshellarg($root));

    runJsonCdrHelper($command);
    runJsonCdrHelper($command);

    expect(is_dir($root.'/failed'))->toBeTrue()
        ->and(is_dir($root.'/quarantine'))->toBeTrue();
});

it('forwards the authorization header to php-fpm', function (): void {
    // Basic auth from mod_json_cdr only reaches Laravel when nginx passes the
    // header; stock fastcgi_params drop it.
    $script = (string) file_get_contents(base_path('scripts/resources/nginx.sh'));

    // The heredoc escapes the nginx variable, so assert on the parameter
    // name rather than the dollar form.
    expect($script)->toContain('fastcgi_param HTTP_AUTHORIZATION');
});

it('supervises the cdr worker with a sandboxed systemd unit', function (): void {
    $unit = (string) file_get_contents(base_path('scripts/tallpbx-cdr-worker.service'));
    $tall = (string) file_get_contents(base_path('scripts/resources/tall.sh'));

    expect($unit)->toContain('ExecStart=/usr/bin/php artisan cdr:work')
        ->and($unit)->toContain('Restart=always')
        ->and($unit)->toContain('User=www-data')
        // The worker never elevates privileges, unlike the scheduler.
        ->and($unit)->toContain('NoNewPrivileges=yes')
        ->and($unit)->toContain('ProtectSystem=strict')
        ->and($unit)->toContain('/var/log/freeswitch/json_cdr')
        // The installer deploys and enables the unit alongside the queue
        // and scheduler services.
        ->and($tall)->toContain('tallpbx-cdr-worker.service /etc/systemd/system/')
        ->and($tall)->toContain('systemctl enable tallpbx-cdr-worker');
});

it('lets the scheduler reach the spool and runs the drain from the schedule', function (): void {
    // A-R2: the scheduler's ProtectSystem=strict sandbox must whitelist the
    // spool tree, or drain-spool appears healthy while files pile up.
    $scheduler = (string) file_get_contents(base_path('scripts/tallpbx-scheduler.service'));
    $console = (string) file_get_contents(base_path('routes/console.php'));

    expect($scheduler)->toContain('ReadWritePaths=')
        ->and($scheduler)->toMatch('/ReadWritePaths=[^\n]*\/var\/log\/freeswitch\/json_cdr/')
        ->and($console)->toContain("Schedule::command('cdr:drain-spool')")
        ->and($console)->toContain('everyFiveMinutes()')
        ->and($console)->toContain('withoutOverlapping()');
});

it('routes cdr posts through the fast entry in every nginx server block', function (): void {
    // The fast CDR entry is only reachable through nginx. Both templates must
    // point /api/v1/cdr at cdr-fast.php, forward the Authorization header the
    // Basic handshake needs, and ship the stock block commented underneath so
    // rollback is a copy and a reload with no FreeSWITCH change.
    $templates = [
        'scripts/resources/nginx.sh' => 1,
        'scripts/resources/letsencrypt.sh' => 2,
    ];

    foreach ($templates as $path => $expectedBlocks) {
        $source = (string) file_get_contents(base_path($path));

        // Anchored at the line start so the commented stock block underneath
        // is not counted as an active location.
        preg_match_all('/^[ \t]*location = \/api\/v1\/cdr \{[^}]*\}/m', $source, $matches);
        $blocks = $matches[0] ?? [];

        expect($blocks)->toHaveCount(
            $expectedBlocks,
            "{$path} must declare the fast CDR location in every server block",
        );

        foreach ($blocks as $block) {
            expect($block)->toContain('fastcgi_param SCRIPT_FILENAME \\$realpath_root/cdr-fast.php;')
                ->and($block)->toContain('fastcgi_param HTTP_AUTHORIZATION \\$http_authorization;')
                ->and($block)->toContain('fastcgi_read_timeout 2s;');
        }

        expect(substr_count($source, '# location = /api/v1/cdr {'))->toBe($expectedBlocks);
    }
});

it('supports mod_json_cdr in source-build mode and package uninstallation in freeswitch.sh', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/freeswitch.sh'));

    // Upstream FreeSWITCH build/modules.conf.in places mod_json_cdr under
    // event_handlers/mod_json_cdr (src/mod/event_handlers/mod_json_cdr).
    expect($script)
        ->toContain('if [ "${freeswitch_install_method:-packages}" != "source" ] && ! source_build_is_installed; then')
        ->toContain('event_handlers/mod_json_cdr')
        ->toContain('freeswitch-mod-json-cdr \\');
});
