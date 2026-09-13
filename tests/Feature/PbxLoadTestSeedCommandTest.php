<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Support\Facades\File;
use Modules\CallCenters\Models\Queue;
use Modules\Extensions\Models\Extension;
use Modules\Gateways\Models\Gateway;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\OutboundRoutes\Models\OutboundRoute;
use Modules\SipAccounts\Models\SipAccount;

const PBX_LOAD_TEST_SEED_COMMAND_CSV_PATH = 'storage/framework/testing/load-tests/seed-command-sipp-users.csv';

beforeEach(function () {
    File::delete(base_path(PBX_LOAD_TEST_SEED_COMMAND_CSV_PATH));
});

afterEach(function () {
    File::delete(base_path(PBX_LOAD_TEST_SEED_COMMAND_CSV_PATH));
});

it('creates repeatable PBX load test data and a SIPp CSV', function () {
    $this->artisan('pbx:load-test:seed', [
        '--tenant' => 'load-test-feature',
        '--domain' => 'load-feature.test',
        '--extensions' => '4',
        '--start' => '3000',
        '--password' => 'Secret1234!',
        '--output' => PBX_LOAD_TEST_SEED_COMMAND_CSV_PATH,
    ])->assertSuccessful();

    $tenant = Tenant::where('slug', 'load-test-feature')->firstOrFail();
    $domain = TenantDomain::where('tenant_id', $tenant->id)->where('domain', 'load-feature.test')->firstOrFail();
    $gateway = Gateway::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'sipp-load-uac')->firstOrFail();
    $outboundRoute = OutboundRoute::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'SIPp outbound 9-prefix')->firstOrFail();

    expect(Extension::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(4)
        ->and(SipAccount::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(4)
        ->and(InboundRoute::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('destination_number', '15551230000')->exists())->toBeTrue()
        ->and($outboundRoute->gateway_id)->toBeNull()
        ->and($outboundRoute->gateway)->toBe('sofia/external/$1@127.0.0.1:5088')
        ->and($domain->purpose)->toBe('sip_realm');

    $csv = (string) file_get_contents(base_path(PBX_LOAD_TEST_SEED_COMMAND_CSV_PATH));

    expect($csv)->toStartWith("SEQUENTIAL\n")
        ->and($csv)->toContain('3000;Secret1234!;load-feature.test;3001;3000')
        ->and($csv)->toContain('3003;Secret1234!;load-feature.test;3000;3003');
});

it('resets only the named synthetic tenant before recreating data', function () {
    $otherTenant = Tenant::factory()->create(['slug' => 'real-tenant']);

    $baseOptions = [
        '--tenant' => 'load-test-feature',
        '--domain' => 'load-feature.test',
        '--extensions' => '3',
        '--start' => '3100',
        '--output' => PBX_LOAD_TEST_SEED_COMMAND_CSV_PATH,
    ];

    $this->artisan('pbx:load-test:seed', $baseOptions)->assertSuccessful();
    $firstTenantId = Tenant::where('slug', 'load-test-feature')->value('id');

    $this->artisan('pbx:load-test:seed', [...$baseOptions, '--reset' => true])->assertSuccessful();
    $secondTenantId = Tenant::where('slug', 'load-test-feature')->value('id');

    expect($secondTenantId)->not->toBe($firstTenantId)
        ->and(Tenant::whereKey($otherTenant->id)->exists())->toBeTrue()
        ->and(Extension::withoutGlobalScope('tenant')->where('tenant_id', $secondTenantId)->count())->toBe(3);
});

it('can seed optional media-flow fixtures for SIPp RTP validation', function () {
    $this->artisan('pbx:load-test:seed', [
        '--tenant' => 'load-test-media',
        '--domain' => 'load-media.test',
        '--extensions' => '3',
        '--start' => '3200',
        '--output' => PBX_LOAD_TEST_SEED_COMMAND_CSV_PATH,
        '--include-media-fixtures' => true,
    ])->assertSuccessful();

    $tenant = Tenant::where('slug', 'load-test-media')->firstOrFail();
    $queue = Queue::withoutGlobalScope('tenant')
        ->where('tenant_id', $tenant->id)
        ->where('name', 'load_test_moh')
        ->firstOrFail();
    $menu = IvrMenu::withoutGlobalScope('tenant')
        ->where('tenant_id', $tenant->id)
        ->where('name', 'load_test_announcement')
        ->firstOrFail();

    expect($queue->music_on_hold)->toBe('local_stream://moh')
        ->and($queue->enabled)->toBeTrue()
        ->and($menu->greeting)->toBe('$${sounds_dir}/en/us/callie/ivr/8000/ivr-thank_you_for_calling.wav')
        ->and($menu->timeout)->toBe(3)
        ->and($menu->enabled)->toBeTrue();
});
