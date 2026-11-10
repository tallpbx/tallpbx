<?php

declare(strict_types=1);

/*
 * The persistent lean worker runs RoadRunner/Octane dedicated to FreeSWITCH
 * dynamic XML routing. These assertions verify:
 *  1. The systemd service unit definition for tallpbx-xml-worker.service
 *  2. The installer resource script integration in scripts/resources/freeswitch.sh
 *  3. The FreeSWITCH xml_curl gateway configuration targeting port 8090
 */

it('ships the tallpbx-xml-worker systemd unit with octane roadrunner telephony worker config', function (): void {
    $unitPath = base_path('scripts/resources/systemd/tallpbx-xml-worker.service');

    expect(file_exists($unitPath))->toBeTrue();

    $unit = (string) file_get_contents($unitPath);

    expect($unit)->toContain('Description=TallPBX FreeSWITCH XML Persistent Lean Worker (Octane/RoadRunner)')
        ->and($unit)->toContain('After=network.target redis-server.service mariadb.service')
        ->and($unit)->toContain('Wants=redis-server.service mariadb.service')
        ->and($unit)->toContain('Type=simple')
        ->and($unit)->toContain('User=www-data')
        ->and($unit)->toContain('Group=www-data')
        ->and($unit)->toContain('WorkingDirectory=/var/www/tallpbx')
        ->and($unit)->toContain('Environment="TALLPBX_WORKER_ROLE=telephony"')
        ->and($unit)->toContain('ExecStart=/usr/bin/php /var/www/tallpbx/artisan octane:start --server=roadrunner --host=127.0.0.1 --port=8090 --workers=auto --max-requests=10000')
        ->and($unit)->toContain('Restart=always')
        ->and($unit)->toContain('RestartSec=3')
        ->and($unit)->toContain('LimitNOFILE=65535')
        ->and($unit)->toContain('WantedBy=multi-user.target');
});

it('configures FreeSWITCH to query the persistent lean worker on port 8090 and installs the systemd unit in freeswitch.sh', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/freeswitch.sh'));

    expect($script)->toContain('gateway_url="http://127.0.0.1:8090${xml_path}?token=${xml_token}"')
        ->and($script)->not->toContain('gateway_url="http://127.0.0.1${xml_path}?token=${xml_token}"')
        ->and($script)->toContain('"${app_dir}/vendor/bin/rr" get -s -f "2024.*" -l "${app_dir}"')
        ->and($script)->toContain('tallpbx-xml-worker.service')
        ->and($script)->toContain('/etc/systemd/system/tallpbx-xml-worker.service')
        ->and($script)->toContain('systemctl daemon-reload')
        ->and($script)->toContain('<binding name="tallpbx-octane">')
        ->and($script)->toContain('<binding name="tallpbx-fallback">')
        // The ladder's third tier: FreeSWITCH re-sends the full request to a
        // stock location, so POST bodies survive - bounded by short timeouts
        // (worker 1s, fast controller 2s, stock 3s instead of 3s/5s).
        ->and($script)->toContain('<binding name="tallpbx-stock">')
        ->and($script)->toContain('${xml_path}-stock?token=${xml_token}')
        ->and($script)->toContain('<param name="timeout" value="1"/>')
        ->and($script)->toContain('<param name="timeout" value="2"/>')
        ->and($script)->toContain('<param name="timeout" value="3"/>')
        // CDR always uses PHP-FPM: the worker must stay free for XML.
        ->and($script)->not->toContain('8090/api/v1/cdr');
});

it('installs the optional worker without enabling or starting it', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/freeswitch.sh'));

    // The shipped default serves XML through the fast controller; the worker
    // is an opt-in performance mode. The installer must leave its systemd
    // state untouched so enabling it is the administrator's explicit act
    // (systemctl enable --now tallpbx-xml-worker) and re-runs never undo it.
    expect($script)->not->toContain('systemctl enable tallpbx-xml-worker')
        ->and($script)->not->toContain('systemctl start tallpbx-xml-worker')
        ->and($script)->not->toContain('systemctl restart tallpbx-xml-worker');
});

it('configures xml_curl.conf.xml with the octane roadrunner worker url on port 8090', function (): void {
    $xmlCurlPath = '/etc/freeswitch/autoload_configs/xml_curl.conf.xml';

    if (! file_exists($xmlCurlPath)) {
        $this->markTestSkipped('FreeSWITCH xml_curl configuration not present on this host');
    }

    $xml = (string) file_get_contents($xmlCurlPath);

    expect($xml)->toContain('http://127.0.0.1:8090')
        ->and($xml)->toContain('bindings="directory|dialplan|configuration"')
        ->and($xml)->toContain('<binding name="tallpbx-octane">')
        ->and($xml)->toContain('name="timeout" value="3"')
        ->and($xml)->toContain('<binding name="tallpbx-fallback">')
        ->and($xml)->toContain('name="timeout" value="5"');
});
