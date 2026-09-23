<?php

declare(strict_types=1);

use App\Services\FreeSwitchServiceInterface;
use App\Services\FreeSwitchSoundManager;

it('lists supported freeswitch sound prompt languages', function (): void {
    $this->artisan('pbx:sounds:list')
        ->assertSuccessful()
        ->expectsOutputToContain('FreeSWITCH Sound Prompt Languages')
        ->expectsOutputToContain('English')
        ->expectsOutputToContain('Spanish')
        ->expectsOutputToContain('French');
});

it('rejects unsupported language codes in default command', function (): void {
    $this->artisan('pbx:sounds:default', ['language' => 'de'])
        ->assertFailed()
        ->expectsOutputToContain("Unsupported language code 'de'");
});

it('rejects unsupported language codes in install command', function (): void {
    $this->artisan('pbx:sounds:install', ['language' => 'it'])
        ->assertFailed()
        ->expectsOutputToContain("Unsupported language code 'it'");
});

it('updates vars.xml with selected default language settings', function (): void {
    $tempDir = sys_get_temp_dir().'/tallpbx-sound-test-'.bin2hex(random_bytes(6));
    mkdir($tempDir, 0755, true);
    mkdir($tempDir.'/autoload_configs', 0755, true);

    $initialVars = <<<'XML'
<include>
  <X-PRE-PROCESS cmd="set" data="default_language=en"/>
  <X-PRE-PROCESS cmd="set" data="default_dialect=us"/>
  <X-PRE-PROCESS cmd="set" data="default_voice=callie"/>
  <X-PRE-PROCESS cmd="set" data="sound_prefix=$${sounds_dir}/en/us/callie"/>
</include>
XML;

    file_put_contents($tempDir.'/vars.xml', $initialVars);
    file_put_contents($tempDir.'/autoload_configs/modules.conf.xml', "<modules>\n  <load module=\"mod_say_en\"/>\n</modules>\n");

    $fsMock = Mockery::mock(FreeSwitchServiceInterface::class);
    $fsMock->shouldReceive('api')->with('reloadxml')->andReturn('+OK');
    $fsMock->shouldReceive('api')->with('load mod_say_es')->andReturn('+OK');

    $manager = new FreeSwitchSoundManager(
        freeSwitch: $fsMock,
        soundsDirectory: $tempDir.'/sounds',
        confDirectory: $tempDir,
    );

    expect($manager->getCurrentDefault()['code'])->toBe('en');

    $updated = $manager->updateVarsXml('es');
    expect($updated)->toBeTrue();

    $current = $manager->getCurrentDefault();
    expect($current['code'])->toBe('es')
        ->and($current['dialect'])->toBe('ar')
        ->and($current['voice'])->toBe('mario')
        ->and($current['sound_prefix'])->toBe('$${sounds_dir}/es/ar/mario');

    // Modules conf update
    $manager->updateModulesConf('mod_say_es');
    $modulesContent = (string) file_get_contents($tempDir.'/autoload_configs/modules.conf.xml');
    expect($modulesContent)->toContain('<load module="mod_say_es"/>');

    // Clean up
    @unlink($tempDir.'/vars.xml');
    @unlink($tempDir.'/autoload_configs/modules.conf.xml');
    @rmdir($tempDir.'/autoload_configs');
    @rmdir($tempDir);
});

it('adds default language preprocessor lines if vars.xml had none', function (): void {
    $tempDir = sys_get_temp_dir().'/tallpbx-sound-test-'.bin2hex(random_bytes(6));
    mkdir($tempDir, 0755, true);

    file_put_contents($tempDir.'/vars.xml', "<include>\n</include>\n");

    $manager = new FreeSwitchSoundManager(
        freeSwitch: null,
        soundsDirectory: $tempDir.'/sounds',
        confDirectory: $tempDir,
    );

    $manager->updateVarsXml('fr');
    $current = $manager->getCurrentDefault();

    expect($current['code'])->toBe('fr')
        ->and($current['dialect'])->toBe('ca')
        ->and($current['voice'])->toBe('june')
        ->and($current['sound_prefix'])->toBe('$${sounds_dir}/fr/ca/june');

    @unlink($tempDir.'/vars.xml');
    @rmdir($tempDir);
});
