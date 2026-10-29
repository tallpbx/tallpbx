<?php

declare(strict_types=1);

use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\DB;

it('executes zero permission sync writes and zero Gate definitions on mod_xml_curl requests', function () {
    $this->seed(AdminSeeder::class);
    config()->set('freeswitch.xml_handler.auth', false);

    // Warm permission sync stamp once
    $this->artisan('permissions:sync')->assertExitCode(0);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->post('/api/v1/xml-handler', [
        'section' => 'dialplan',
        'Caller-Context' => 'nonexistent_context',
        'Caller-Destination-Number' => '1000',
    ]);

    $response->assertOk();

    $permissionQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'permissions'));

    expect($permissionQueries)->toBeEmpty();
});
