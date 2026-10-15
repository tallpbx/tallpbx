<?php

declare(strict_types=1);

use App\Services\ModuleLifecycleService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/pbx-uninstall-cmd-'.bin2hex(random_bytes(8));

    // A core module: the command must refuse it outright.
    File::makeDirectory($this->sandbox.'/app-modules/demo-module', 0755, true);
    File::put($this->sandbox.'/app-modules/demo-module/module.json', json_encode([
        'name' => 'demo-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
        'source' => 'core',
    ]));

    // A custom extension: deletable with typed confirmation.
    File::makeDirectory($this->sandbox.'/custom-modules/rogue-tool', 0755, true);
    File::put($this->sandbox.'/custom-modules/rogue-tool/module.json', json_encode([
        'name' => 'rogue-tool', 'version' => '1.0.0',
        'namespace' => 'CustomModules\\RogueTool', 'display_name' => 'Rogue Tool',
        'source' => 'custom',
    ]));

    File::put($this->sandbox.'/composer.json', json_encode(['require' => []]));

    $this->composerCalls = [];

    app()->instance(ModuleLifecycleService::class, new ModuleLifecycleService(
        app(),
        app(Filesystem::class),
        $this->sandbox,
        fn (array $args): bool => (bool) ($this->composerCalls[] = implode(' ', $args)),
    ));
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('refuses built-in core modules outright', function (): void {
    $this->artisan('module:uninstall demo-module --confirm="UNINSTALL demo-module"')->assertFailed();

    expect(File::exists($this->sandbox.'/app-modules/demo-module/module.json'))->toBeTrue()
        ->and($this->composerCalls)->toBe([]);
});

it('uninstalls a custom extension with the exact confirmation phrase', function (): void {
    $this->artisan('module:uninstall rogue-tool --confirm="UNINSTALL rogue-tool"')
        ->expectsOutputToContain('Uninstalled module [rogue-tool]')
        ->assertSuccessful();

    expect(File::exists($this->sandbox.'/custom-modules/rogue-tool'))->toBeFalse()
        ->and($this->composerCalls)->toBe([]);
});

it('refuses without the exact confirmation phrase', function (): void {
    $this->artisan('module:uninstall rogue-tool --confirm="nope"')->assertFailed();

    expect(File::exists($this->sandbox.'/custom-modules/rogue-tool'))->toBeTrue();
});

it('refuses unknown modules', function (): void {
    $this->artisan('module:uninstall ghost-module --confirm="UNINSTALL ghost-module"')->assertFailed();
});

it('surfaces partial cleanup failures instead of claiming a clean uninstall', function (): void {
    // A filesystem that refuses to delete leaves the extension directory
    // behind; the command must warn instead of presenting a clean uninstall.
    $stubbornFiles = new class extends Filesystem
    {
        /**
         * Pretend the target directory cannot be removed.
         */
        public function deleteDirectory($directory, $preserve = false): bool
        {
            return false;
        }
    };

    app()->instance(ModuleLifecycleService::class, new ModuleLifecycleService(
        app(),
        $stubbornFiles,
        $this->sandbox,
        fn (array $args): bool => true,
    ));

    // The lifecycle service runs nested Artisan calls (optimize:clear), so
    // capture this command's output in a dedicated buffer. Console components
    // wrap long lines, so assert on whitespace-normalized output.
    $buffer = new BufferedOutput;
    $exitCode = Artisan::call('module:uninstall', [
        'name' => 'rogue-tool',
        '--confirm' => 'UNINSTALL rogue-tool',
    ], $buffer);
    $output = preg_replace('/\s+/', ' ', (string) $buffer->fetch());

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('need manual attention')
        ->and($output)->toContain('could not be deleted');

    expect(File::exists($this->sandbox.'/custom-modules/rogue-tool'))->toBeTrue();
});
