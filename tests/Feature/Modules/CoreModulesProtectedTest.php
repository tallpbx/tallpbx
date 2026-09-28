<?php

declare(strict_types=1);

it('marks the core PBX modules as protected or required', function (): void {
    $core = [
        'admin', 'auth', 'tenant',
        'extensions', 'devices', 'sip-accounts', 'destinations',
        'dialplans', 'dialplan-tools', 'gateways', 'sip-profiles',
        'inbound-routes', 'outbound-routes',
    ];

    foreach ($core as $name) {
        $manifest = json_decode((string) file_get_contents(base_path("app-modules/{$name}/module.json")), true);

        expect(($manifest['protected'] ?? false) || ($manifest['required'] ?? false))
            ->toBeTrue("Module [{$name}] must be protected or required");
    }
});
