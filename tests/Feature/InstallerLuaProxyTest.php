<?php

declare(strict_types=1);

/*
 * The Lua directory cache is optional and off by default, but its files must
 * reach every fresh install so enabling it is one .env change plus a restart.
 * These assertions guard the deployment wiring: the handler, both templates,
 * the module load order, and the fact that the installer never enables it.
 */

it('deploys the optional lua directory cache without enabling it', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/freeswitch.sh'));

    expect($script)->toContain('configure_lua_directory_cache()')
        ->and($script)->toContain('tallpbx_xml_handler.lua')
        ->and($script)->toContain('lua.conf.xml')
        ->and($script)->toContain('hiredis.conf.xml')
        ->and($script)->toContain('ensure_freeswitch_module_enabled "$modules_conf" "mod_lua"')
        // Called from both the configure-only path and the full install path.
        ->and(substr_count($script, 'configure_lua_directory_cache'))->toBeGreaterThanOrEqual(3)
        // Enabling is an operator decision, never an installer side effect.
        ->and($script)->not->toContain('FS_LUA_PROXY_ENABLED=true');
});

it('preserves an operator supplied hiredis password across installer re-runs', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/freeswitch.sh'));

    // Only an active parameter may be carried over; the template ships the
    // same element commented out, which must not be mistaken for a value.
    expect($script)->toContain('^[[:space:]]*<param name="password" value="[^"]*"')
        ->and($script)->toContain('CHANGE_ME');
});
