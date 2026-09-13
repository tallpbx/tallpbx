<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\DialplanContext;
use App\Services\TenantDefaultsService;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Modules\CallCenters\Models\Queue;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\IvrMenus\Models\IvrMenuOption;

use function Pest\Laravel\get;

beforeEach(function (): void {
    config([
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.dialplan_cache_store' => 'array',
        'freeswitch.xml_handler.dialplan_cache_ttl' => 0,
        'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 0,
    ]);

    Cache::store('array')->flush();
    app(TenantManager::class)->clear();
});

it('serves call recording start and stop dialplan XML from tenant defaults', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantDefaultsService::class)->provision($tenant);

    $internalContext = app(DialplanContext::class)->internal((string) $tenant->id);
    $recordingSpool = config('media-storage.spool_root').'/'.$tenant->id.'/call-recording/${uuid}.wav';

    featureMediaDialplanXml($internalContext, '*732')
        ->assertOk()
        ->assertSee('<extension name="recording-start">', false)
        ->assertSee('application="answer"', false)
        ->assertSee('application="record_session"', false)
        ->assertSee('data="'.$recordingSpool.'"', false)
        ->assertDontSee('${recordings_dir}/${uuid}.wav', false)
        ->assertDontSee('application="hangup"', false);

    featureMediaDialplanXml($internalContext, '*733')
        ->assertOk()
        ->assertSee('<extension name="recording-stop">', false)
        ->assertSee('application="stop_record_session"', false)
        ->assertSee('data="'.$recordingSpool.'"', false)
        ->assertDontSee('${recordings_dir}/${uuid}.wav', false)
        ->assertDontSee('application="hangup"', false);
});

it('serves music-on-hold queue configuration XML using local stream media', function (): void {
    [$tenant, $domain] = featureMediaTenantWithDomain('moh-validation.example.com');

    Queue::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'support_queue',
        'strategy' => 'longest-idle-agent',
        'timeout' => 45,
        'music_on_hold' => 'local_stream://moh',
        'enabled' => true,
    ]);

    featureMediaConfigurationXml('callcenter.conf', $domain->domain)
        ->assertOk()
        ->assertSee('<configuration name="callcenter.conf"', false)
        ->assertSee('<queue name="support_queue@default">', false)
        ->assertSee('name="moh-sound" value="local_stream://moh"', false)
        ->assertSee('name="timeout" value="45"', false);
});

it('serves announcement playback through IVR dialplan and configuration XML', function (): void {
    [$tenant, $domain] = featureMediaTenantWithDomain('announcement-validation.example.com');

    $menu = IvrMenu::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'main_announcement',
        'greeting' => '/var/lib/freeswitch/recordings/welcome.wav',
        'digit_length' => 1,
        'timeout' => 5,
        'max_failures' => 3,
        'enabled' => true,
    ]);

    IvrMenuOption::factory()->create([
        'ivr_menu_id' => $menu->id,
        'digit' => '1',
        'action' => 'playback',
        'action_data' => '/var/lib/freeswitch/recordings/hours.wav',
        'enabled' => true,
    ]);

    $internalContext = app(DialplanContext::class)->internal((string) $tenant->id);

    featureMediaDialplanXml($internalContext, 'main_announcement')
        ->assertOk()
        ->assertSee('<extension name="ivr_main_announcement">', false)
        ->assertSee('application="playback" data="/var/lib/freeswitch/recordings/welcome.wav"', false)
        ->assertSee('application="bind_digit_action" data="1~~playback~~/var/lib/freeswitch/recordings/hours.wav"', false)
        ->assertSee('application="read"', false);

    featureMediaConfigurationXml('ivr.conf', $domain->domain)
        ->assertOk()
        ->assertSee('<configuration name="ivr.conf"', false)
        ->assertSee('<menu name="main_announcement"', false)
        ->assertSee('greet-long="/var/lib/freeswitch/recordings/welcome.wav"', false)
        ->assertSee('param="playback /var/lib/freeswitch/recordings/hours.wav"', false);
});

it('serves announcement-only IVR dialplan XML without waiting for digit input', function (): void {
    [$tenant] = featureMediaTenantWithDomain('announcement-only-validation.example.com');

    IvrMenu::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'load_test_announcement',
        'greeting' => '/var/lib/freeswitch/sounds/en/us/callie/ivr/8000/ivr-thank_you_for_calling.wav',
        'digit_length' => 1,
        'timeout' => 3,
        'max_failures' => 1,
        'enabled' => true,
    ]);

    $internalContext = app(DialplanContext::class)->internal((string) $tenant->id);

    featureMediaDialplanXml($internalContext, 'load_test_announcement')
        ->assertOk()
        ->assertSee('<extension name="ivr_load_test_announcement">', false)
        ->assertSee('application="answer"', false)
        ->assertSee('application="playback" data="/var/lib/freeswitch/sounds/en/us/callie/ivr/8000/ivr-thank_you_for_calling.wav"', false)
        ->assertSee('application="hangup" data="NORMAL_CLEARING"', false)
        ->assertDontSee('application="sleep" data="1000"', false)
        ->assertDontSee('application="read"', false)
        ->assertDontSee('application="bind_digit_action"', false);
});

/**
 * Create a tenant and SIP domain for configuration XML resolution.
 *
 * @return array{0: Tenant, 1: TenantDomain}
 */
function featureMediaTenantWithDomain(string $domain): array
{
    $tenant = Tenant::factory()->create();
    app(TenantDefaultsService::class)->provision($tenant);

    $tenantDomain = TenantDomain::create([
        'tenant_id' => $tenant->id,
        'domain' => $domain,
        'purpose' => 'sip_realm',
        'enabled' => true,
    ]);

    return [$tenant, $tenantDomain];
}

function featureMediaDialplanXml(string $context, string $destination): TestResponse
{
    return get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => $destination,
        'Caller-Caller-ID-Number' => '1000',
    ]));
}

function featureMediaConfigurationXml(string $configuration, string $domain): TestResponse
{
    return get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => $configuration,
        'domain' => $domain,
    ]));
}
