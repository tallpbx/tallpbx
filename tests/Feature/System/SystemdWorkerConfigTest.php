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
        ->and($script)->toContain('tallpbx-xml-worker.service')
        ->and($script)->toContain('/etc/systemd/system/tallpbx-xml-worker.service')
        ->and($script)->toContain('systemctl enable tallpbx-xml-worker')
        ->and($script)->toContain('systemctl daemon-reload');
});

it('configures xml_curl.conf.xml with the octane roadrunner worker url on port 8090', function (): void {
    $xmlCurlPath = '/etc/freeswitch/autoload_configs/xml_curl.conf.xml';

    if (! file_exists($xmlCurlPath)) {
        $this->markTestSkipped('FreeSWITCH xml_curl configuration not present on this host');
    }

    $xml = (string) file_get_contents($xmlCurlPath);

    expect($xml)->toContain('http://127.0.0.1:8090')
        ->and($xml)->toContain('bindings="directory|dialplan|configuration"')
        ->and($xml)->toContain('name="timeout" value="5"');
});
