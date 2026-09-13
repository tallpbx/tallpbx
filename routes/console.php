<?php

use App\Console\Commands\FreeSwitchListenCommand;
use App\Console\Commands\MakeModuleCommand;
use App\Console\Commands\ModuleCacheCommand;
use App\Console\Commands\ModuleClearCommand;
use App\Console\Commands\ModuleListCommand;
use App\Console\Commands\ModuleSyncCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('module:cache', function () {
    $this->call(ModuleCacheCommand::class);
})->purpose('Generate module cache files');

Artisan::command('module:clear', function () {
    $this->call(ModuleClearCommand::class);
})->purpose('Remove module cache files');

Artisan::command('modules:cache', function () {
    $this->call(ModuleCacheCommand::class);
})->purpose('Generate module cache files');

Artisan::command('modules:clear', function () {
    $this->call(ModuleClearCommand::class);
})->purpose('Remove module cache files');

Artisan::command('modules:list', function () {
    $this->call(ModuleListCommand::class);
})->purpose('List registered modules');

Artisan::command('modules:sync {--only-local}', function () {
    $this->call(ModuleSyncCommand::class, [
        '--only-local' => $this->option('only-local'),
    ]);
})->purpose('Synchronize module manifests from the filesystem into the database');

Artisan::command('make:module {name} {--display-name=} {--description=} {--category=}', function () {
    $this->call(MakeModuleCommand::class, [
        'name' => $this->argument('name'),
        '--display-name' => $this->option('display-name'),
        '--description' => $this->option('description'),
        '--category' => $this->option('category'),
    ]);
})->purpose('Scaffold a new module');

Artisan::command('freeswitch:listen {--once} {--timeout=0}', function () {
    $this->call(FreeSwitchListenCommand::class, [
        '--once' => $this->option('once'),
        '--timeout' => $this->option('timeout'),
    ]);
})->purpose('Listen for FreeSWITCH ESL events and dispatch as Laravel events');

Schedule::command('media:reconcile --retry-failed --delete-orphans')
    ->daily()
    ->withoutOverlapping();

Schedule::command('broadcast:reconcile-outcomes')
    ->everyFiveMinutes()
    ->withoutOverlapping();
