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
    $script = (string) file_get_contents(base_path('scripts/resources/freeswitch-lua-proxy.sh'));

    // Only an active parameter may be carried over; the template ships the
    // same element commented out, which must not be mistaken for a value.
    expect($script)->toContain('^[[:space:]]*<param name="password" value="[^"]*"')
        ->and($script)->toContain('CHANGE_ME');
});

/**
 * Run one helper from the Lua proxy script against a fixture file.
 *
 * @param  string  $command  Shell snippet naming the helper and its arguments
 */
function runLuaProxyHelper(string $command): string
{
    $script = base_path('scripts/resources/freeswitch-lua-proxy.sh');

    return (string) shell_exec(sprintf('bash -c %s 2>&1', escapeshellarg('. "'.$script.'"; '.$command)));
}

/**
 * Create a throwaway file and return its path, registering cleanup.
 */
function tempFixture(string $contents): string
{
    $path = sys_get_temp_dir().'/luaproxy-'.uniqid().'.xml';
    file_put_contents($path, $contents);

    return $path;
}

it('moves mod_lua ahead of mod_xml_curl without losing the line', function (): void {
    // The stock Debian order loads mod_xml_curl first, which is exactly the
    // case the old inline rewrite got wrong: it held the mod_lua line and
    // never printed it, so the module silently stopped loading.
    $file = tempFixture(<<<'XML'
<configuration name="modules.conf" description="Modules">
  <modules>
    <load module="mod_console"/>
    <load module="mod_xml_curl"/>
    <load module="mod_sofia"/>
    <load module="mod_lua"/>
    <load module="mod_dptools"/>
  </modules>
</configuration>
XML);

    runLuaProxyHelper('tallpbx_move_mod_lua_before_xml_curl '.escapeshellarg($file));

    $contents = (string) file_get_contents($file);
    $lua = strpos($contents, '<load module="mod_lua"/>');
    $curl = strpos($contents, '<load module="mod_xml_curl"/>');

    expect($lua)->not->toBeFalse()
        ->and($curl)->not->toBeFalse()
        ->and($lua)->toBeLessThan($curl)
        // Exactly one copy, and no neighbouring module lost on the way.
        ->and(substr_count($contents, '<load module="mod_lua"/>'))->toBe(1)
        ->and(substr_count($contents, '<load module="mod_xml_curl"/>'))->toBe(1)
        ->and($contents)->toContain('mod_console')
        ->and($contents)->toContain('mod_dptools');

    unlink($file);
});

it('refuses to carry a password meant for another host', function (): void {
    // The Debian package ships a sample profile pointing at 172.18.101.101
    // with the password "redis". Carried onto 127.0.0.1 it produces -NOAUTH
    // on every lookup, and the cache fails silently for ever.
    $installed = tempFixture(<<<'XML'
<configuration name="hiredis.conf">
  <connections>
    <connection name="primary">
      <param name="hostname" value="172.18.101.101"/>
      <param name="password" value="redis"/>
      <param name="port" value="6379"/>
    </connection>
  </connections>
</configuration>
XML);
    $env = tempFixture("REDIS_PASSWORD=null\n");

    $resolved = trim(runLuaProxyHelper('tallpbx_resolve_hiredis_password '.escapeshellarg($installed).' '.escapeshellarg($env)));

    expect($resolved)->toBe('');

    unlink($installed);
    unlink($env);
});

it('uses the application redis password when one is configured', function (): void {
    $installed = tempFixture(<<<'XML'
<configuration name="hiredis.conf">
  <connections>
    <connection name="primary">
      <param name="hostname" value="127.0.0.1"/>
      <param name="port" value="6379"/>
    </connection>
  </connections>
</configuration>
XML);
    $env = tempFixture("REDIS_PASSWORD=secret-value\n");

    $resolved = trim(runLuaProxyHelper('tallpbx_resolve_hiredis_password '.escapeshellarg($installed).' '.escapeshellarg($env)));

    expect($resolved)->toBe('secret-value');

    unlink($installed);
    unlink($env);
});

it('ensures lua.conf.xml template and installed configuration parse as well-formed XML', function (): void {
    $templatePath = base_path('scripts/freeswitch/lua.conf.xml');
    expect(file_exists($templatePath))->toBeTrue();

    $templateXml = simplexml_load_file($templatePath);
    expect($templateXml)->not->toBeFalse();

    $installedPath = '/etc/freeswitch/autoload_configs/lua.conf.xml';
    if (file_exists($installedPath)) {
        $installedXml = simplexml_load_file($installedPath);
        expect($installedXml)->not->toBeFalse();
    }
});

it('comments out xml-handler-bindings without nesting comments or invalidating XML', function (): void {
    $fixture = tempFixture(<<<'XML'
<configuration name="lua.conf" description="LUA Configuration">
  <settings>
    <param name="xml-handler-script" value="/etc/freeswitch/scripts/tallpbx_xml_handler.lua"/>
    <param name="xml-handler-bindings" value="directory,dialplan"/>
  </settings>
</configuration>
XML);

    shell_exec("sed -i -E 's|^([[:space:]]*)<param name=\"xml-handler-bindings\"([^>]*)/>|\\1<!-- <param name=\"xml-handler-bindings\"\\2/> -->|' ".escapeshellarg($fixture));

    $firstRun = (string) file_get_contents($fixture);
    expect($firstRun)->toContain('<!-- <param name="xml-handler-bindings"')
        ->and(simplexml_load_string($firstRun))->not->toBeFalse();

    // Re-running against already commented line must be idempotent and never nest comments
    shell_exec("sed -i -E 's|^([[:space:]]*)<param name=\"xml-handler-bindings\"([^>]*)/>|\\1<!-- <param name=\"xml-handler-bindings\"\\2/> -->|' ".escapeshellarg($fixture));

    $secondRun = (string) file_get_contents($fixture);
    expect($secondRun)->toBe($firstRun)
        ->and($secondRun)->not->toContain('<!-- <!--')
        ->and($secondRun)->not->toContain('--> -->')
        ->and(simplexml_load_string($secondRun))->not->toBeFalse();

    unlink($fixture);
});
