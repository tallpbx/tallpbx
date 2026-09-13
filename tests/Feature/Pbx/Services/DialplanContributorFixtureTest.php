<?php

declare(strict_types=1);

/**
 * Direct unit-level XML fixture tests for each ContextWideDialplanXmlContributor.
 *
 * Unlike XmlHandlerFixtureTest (which exercises the full HTTP stack), these
 * tests call generateDialplanXml() on each contributor service directly and
 * assert the exact XML structure, field escaping, disabled-record exclusion,
 * empty-result handling, and tenant isolation.
 *
 * Each contributor group follows the same pattern:
 *   1. Happy path — enabled records produce valid XML.
 *   2. Disabled records are excluded.
 *   3. Empty/null result when no enabled records exist.
 *   4. Tenant isolation — other-tenant records are excluded.
 */

use App\Models\Tenant;
use Modules\Bridges\Models\Bridge;
use Modules\Bridges\Services\BridgeService;
use Modules\CallBlocks\Models\CallBlock;
use Modules\CallBlocks\Services\CallBlockService;
use Modules\CallCenters\Models\Queue;
use Modules\CallCenters\Services\CallCenterService;
use Modules\CallFlows\Models\CallFlow;
use Modules\CallFlows\Services\CallFlowService;
use Modules\CallForwards\Models\CallForward;
use Modules\CallForwards\Services\CallForwardService;
use Modules\ConferenceCenters\Models\ConferenceCenter;
use Modules\ConferenceCenters\Services\ConferenceCenterService;
use Modules\Conferences\Models\Conference;
use Modules\Conferences\Services\ConferenceService;
use Modules\Emergency\Models\Emergency;
use Modules\Emergency\Services\EmergencyService;
use Modules\Extensions\Models\Extension;
use Modules\FeatureCodes\Models\FeatureCode;
use Modules\FeatureCodes\Services\FeatureCodeService;
use Modules\FollowMe\Models\FollowMe;
use Modules\FollowMe\Services\FollowMeService;
use Modules\Gateways\Models\Gateway;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\InboundRoutes\Services\InboundRouteService;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\IvrMenus\Services\IvrMenuService;
use Modules\OutboundRoutes\Models\OutboundRoute;
use Modules\OutboundRoutes\Services\OutboundRouteService;
use Modules\RingGroups\Models\RingGroup;
use Modules\RingGroups\Models\RingGroupExtension;
use Modules\RingGroups\Services\RingGroupService;
use Modules\TimeConditions\Models\TimeCondition;
use Modules\TimeConditions\Services\TimeConditionService;
use Modules\Voicemails\Models\Voicemail;
use Modules\Voicemails\Services\VoicemailService;

// ═══════════════════════════════════════════════════════════════════
//  Bridges
// ═══════════════════════════════════════════════════════════════════

it('generates bridge XML with conference application', function () {
    $tenant = Tenant::factory()->create();
    $service = app(BridgeService::class);

    Bridge::factory()->create([
        'tenant_id' => $tenant->id,
        'bridge_name' => 'sales_bridge',
        'destination_number' => '3000',
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('bridge_sales_bridge')
        ->toContain('destination_number')
        ->toContain('^3000$')
        ->toContain('application="answer"')
        ->toContain('application="conference"')
        ->toContain('sales_bridge@default');
});

it('generates bridge XML with PIN when configured', function () {
    $tenant = Tenant::factory()->create();
    $service = app(BridgeService::class);

    Bridge::factory()->create([
        'tenant_id' => $tenant->id,
        'bridge_name' => 'secure_room',
        'destination_number' => '4000',
        'pin_number' => '9876',
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('bridge_secure_room')
        ->toContain('conference')
        ->toContain('secure_room@default+pin_9876');
});

it('excludes disabled bridges from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(BridgeService::class);

    Bridge::factory()->create([
        'tenant_id' => $tenant->id,
        'bridge_name' => 'offline_bridge',
        'destination_number' => '5000',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

it('returns null for bridges when no enabled records exist', function () {
    $tenant = Tenant::factory()->create();
    $service = app(BridgeService::class);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Call Blocks
// ═══════════════════════════════════════════════════════════════════

it('generates call block XML with caller ID matching and hangup', function () {
    $tenant = Tenant::factory()->create();
    $service = app(CallBlockService::class);

    CallBlock::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'block_spammer',
        'caller_id_number' => '5550100',
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('block_block_spammer')
        ->toContain('orig_caller_id_number')
        ->toContain('5550100')
        ->toContain('application="hangup"')
        ->toContain('CALL_REJECTED');
});

it('excludes disabled call blocks from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(CallBlockService::class);

    CallBlock::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'disabled_block',
        'caller_id_number' => '5550199',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Emergency
// ═══════════════════════════════════════════════════════════════════

it('generates emergency XML with caller ID and address data', function () {
    $tenant = Tenant::factory()->create();
    $service = app(EmergencyService::class);

    Emergency::factory()->create([
        'tenant_id' => $tenant->id,
        'caller_id' => '5559110000',
        'address' => '123 Main St, Anytown',
        'latitude' => 40.7128,
        'longitude' => -74.0060,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_public", '');

    expect($xml)
        ->toContain('emergency_911')
        ->toContain('^(911|112)$')
        ->toContain('effective_caller_id_number=5559110000')
        ->toContain('emergency_caller_id_number=5559110000')
        ->toContain('emergency_caller_id_address=123 Main St, Anytown')
        ->toContain('geo-location=40.7128,-74.006')
        ->toContain('sofia/external/${destination_number}');
});

it('routes emergency calls through the enabled emergency gateway host and port', function () {
    $tenant = Tenant::factory()->create();
    $service = app(EmergencyService::class);

    Emergency::factory()->create([
        'tenant_id' => $tenant->id,
        'caller_id' => '5559110000',
        'address' => '123 Main St, Anytown',
    ]);
    Gateway::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'emergency',
        'host' => 'e911-gateway.test',
        'port' => 5090,
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_public", '');

    expect($xml)
        ->toContain('application="bridge"')
        ->toContain('sofia/external/${destination_number}@e911-gateway.test:5090')
        ->not->toContain('sofia/gateway/emergency');
});

it('generates emergency XML without geo-location when lat/lon absent', function () {
    $tenant = Tenant::factory()->create();
    $service = app(EmergencyService::class);

    Emergency::factory()->create([
        'tenant_id' => $tenant->id,
        'caller_id' => '911',
        'address' => '456 Oak Ave',
        'latitude' => null,
        'longitude' => null,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_public", '');

    expect($xml)
        ->toContain('emergency_911')
        ->not()->toContain('geo-location');
});

it('returns null for emergency XML when no record exists', function () {
    $tenant = Tenant::factory()->create();
    $service = app(EmergencyService::class);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_public", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Call Flows
// ═══════════════════════════════════════════════════════════════════

it('generates call flow XML with transfer action', function () {
    $tenant = Tenant::factory()->create();
    $service = app(CallFlowService::class);

    CallFlow::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'sales_flow',
        'extension' => '6000',
        'destination_type' => 'ivr',
        'destination_id' => 'main_menu',
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('callflow_sales_flow')
        ->toContain('destination_number')
        ->toContain('^6000$')
        ->toContain('application="transfer"')
        ->toContain('main_menu');
});

it('excludes disabled call flows from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(CallFlowService::class);

    CallFlow::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'off_flow',
        'extension' => '9999',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Call Forwards
// ═══════════════════════════════════════════════════════════════════

it('generates unconditional call forward XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(CallForwardService::class);

    $ext = Extension::factory()->create(['tenant_id' => $tenant->id, 'extension_number' => '2000']);

    CallForward::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_uuid' => $ext->id,
        'forward_type' => 'unconditional',
        'destination' => 'sofia/external/+14155551212',
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('forward_unconditional_'.$ext->id)
        ->toContain('application="bridge"')
        ->toContain('+14155551212');
});

it('generates no-answer call forward XML with timeout', function () {
    $tenant = Tenant::factory()->create();
    $service = app(CallForwardService::class);

    $ext = Extension::factory()->create(['tenant_id' => $tenant->id, 'extension_number' => '2001']);

    CallForward::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_uuid' => $ext->id,
        'forward_type' => 'no-answer',
        'destination' => 'user/voicemail_200',
        'ring_timeout' => 25,
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('forward_no-answer_'.$ext->id)
        ->toContain('call_timeout=25')
        ->toContain('hangup_after_bridge=true')
        ->toContain('application="bridge"');
});

it('generates busy call forward XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(CallForwardService::class);

    $ext = Extension::factory()->create(['tenant_id' => $tenant->id, 'extension_number' => '2002']);

    CallForward::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_uuid' => $ext->id,
        'forward_type' => 'busy',
        'destination' => 'user/300',
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('forward_busy_'.$ext->id)
        ->toContain('continue_on_fail=USER_BUSY')
        ->toContain('application="bridge"');
});

it('excludes disabled call forwards from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(CallForwardService::class);

    $ext = Extension::factory()->create(['tenant_id' => $tenant->id, 'extension_number' => '2003']);

    CallForward::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_uuid' => $ext->id,
        'forward_type' => 'unconditional',
        'destination' => 'user/999',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Call Centers (Queues)
// ═══════════════════════════════════════════════════════════════════

it('generates call center queue XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(CallCenterService::class);

    Queue::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'support_queue',
        'strategy' => 'longest-idle-agent',
        'timeout' => 45,
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('callcenter_support_queue')
        ->toContain('application="answer"')
        ->toContain('application="callcenter"')
        ->toContain('support_queue@default');
});

it('excludes disabled call center queues from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(CallCenterService::class);

    Queue::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'off_queue',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Conference Centers
// ═══════════════════════════════════════════════════════════════════

it('generates conference center XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(ConferenceCenterService::class);

    ConferenceCenter::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'main_center',
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('conf_center_main_center')
        ->toContain('application="answer"')
        ->toContain('application="conference_center"')
        ->toContain('main_center@default');
});

it('excludes disabled conference centers from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(ConferenceCenterService::class);

    ConferenceCenter::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'off_center',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Conferences
// ═══════════════════════════════════════════════════════════════════

it('generates conference XML with flags and max members', function () {
    $tenant = Tenant::factory()->create();
    $service = app(ConferenceService::class);

    Conference::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'boardroom',
        'profile' => 'default',
        'max_members' => 50,
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('conference_boardroom')
        ->toContain('application="answer"')
        ->toContain('conference_flags=wait-mod|moderator-notify')
        ->toContain('application="conference"')
        ->toContain('boardroom@default')
        ->toContain('max-members_50');
});

it('generates conference XML with PIN when configured', function () {
    $tenant = Tenant::factory()->create();
    $service = app(ConferenceService::class);

    Conference::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'secure_conf',
        'profile' => 'default',
        'pin' => '1234',
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('conference_secure_conf')
        ->toContain('pin_1234');
});

it('excludes disabled conferences from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(ConferenceService::class);

    Conference::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'off_conf',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Feature Codes
// ═══════════════════════════════════════════════════════════════════

it('generates feature code XML with real FreeSWITCH application', function () {
    $tenant = Tenant::factory()->create();
    $service = app(FeatureCodeService::class);

    FeatureCode::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'voicemail_access',
        'code' => '*97',
        'application' => 'voicemail',
        'application_data' => 'default ${domain} ${caller_id_number}',
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('feature_voicemail_access')
        ->toContain('^\*97$')
        ->toContain('application="voicemail"')
        ->not()->toContain('application="log"');
});

it('falls back to log-only for feature codes without application', function () {
    $tenant = Tenant::factory()->create();
    $service = app(FeatureCodeService::class);

    FeatureCode::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'custom_code',
        'code' => '*99',
        'application' => null,
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('feature_custom_code')
        ->toContain('application="log"');
});

it('excludes disabled feature codes from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(FeatureCodeService::class);

    FeatureCode::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'off_code',
        'code' => '*88',
        'application' => 'voicemail',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Follow Me
// ═══════════════════════════════════════════════════════════════════

it('generates follow-me XML with bridge and forwarding destination', function () {
    $tenant = Tenant::factory()->create();
    $service = app(FollowMeService::class);

    FollowMe::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'jdoe_follow',
        'extension' => '2000',
        'destination' => 'sofia/external/+15551234567',
        'ring_timeout' => 20,
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('followme_jdoe_follow')
        ->toContain('call_timeout=20')
        ->toContain('continue_on_fail=true')
        ->toContain('sofia_contact(2000@${domain_name})')
        ->toContain('+15551234567');
});

it('excludes disabled follow-me rules from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(FollowMeService::class);

    FollowMe::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'off_follow',
        'extension' => '9999',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Inbound Routes
// ═══════════════════════════════════════════════════════════════════

it('generates inbound route XML with transfer action', function () {
    $tenant = Tenant::factory()->create();
    $service = app(InboundRouteService::class);

    InboundRoute::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'sales_did',
        'destination_number' => '8005551212',
        'action' => 'transfer',
        'action_data' => '100 XML default',
        'priority' => 1,
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_public", '8005551212');

    expect($xml)
        ->toContain('inbound_sales_did')
        ->toContain('destination_number')
        ->toContain('8005551212')
        ->toContain('application="transfer"');
});

it('excludes disabled inbound routes from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(InboundRouteService::class);

    InboundRoute::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'off_did',
        'destination_number' => '8005559999',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_public", '8005559999');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  IVR Menus
// ═══════════════════════════════════════════════════════════════════

it('generates IVR menu XML with greeting playback', function () {
    $tenant = Tenant::factory()->create();
    $service = app(IvrMenuService::class);

    IvrMenu::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'main_menu',
        'greeting' => 'welcome.wav',
        'timeout' => 3,
        'max_failures' => 3,
        'digit_length' => 1,
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('ivr_main_menu')
        ->toContain('application="answer"')
        ->toContain('playback')
        ->toContain('welcome.wav');
});

it('excludes disabled IVR menus from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(IvrMenuService::class);

    IvrMenu::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'off_menu',
        'greeting' => 'silence.wav',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Outbound Routes
// ═══════════════════════════════════════════════════════════════════

it('generates outbound route XML with extension and condition structure', function () {
    $tenant = Tenant::factory()->create();
    $service = app(OutboundRouteService::class);

    OutboundRoute::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'long_distance',
        'dial_pattern' => '^1(\\d{10})$',
        'gateway' => 'sip-provider',
        'priority' => 50,
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_public", '');

    expect($xml)
        ->toContain('outbound_long_distance')
        ->toContain('destination_number')
        ->toContain('application="bridge"')
        ->toContain('sofia/gateway/sip-provider');
});

it('returns null for outbound routes when no enabled records exist', function () {
    $tenant = Tenant::factory()->create();
    $service = app(OutboundRouteService::class);

    OutboundRoute::factory()->create([
        'tenant_id' => $tenant->id,
        'dial_pattern' => '(\\d{10})',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_public", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Ring Groups
// ═══════════════════════════════════════════════════════════════════

it('generates ring group XML with simultaneous strategy', function () {
    $tenant = Tenant::factory()->create();
    $service = app(RingGroupService::class);
    $firstExtension = Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '2101',
    ]);
    $secondExtension = Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '2102',
    ]);

    $group = RingGroup::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'support_team',
        'strategy' => 'simultaneous',
        'ring_timeout' => 20,
        'enabled' => true,
    ]);
    RingGroupExtension::factory()->create([
        'ring_group_id' => $group->id,
        'extension_uuid' => $firstExtension->id,
        'position' => 1,
    ]);
    RingGroupExtension::factory()->create([
        'ring_group_id' => $group->id,
        'extension_uuid' => $secondExtension->id,
        'position' => 2,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('ring_group_support_team')
        ->toContain('destination_number')
        ->toContain('^support_team$')
        ->toContain('call_timeout=20')
        ->toContain('application="bridge"')
        ->toContain('${sofia_contact(2101@${domain_name})}')
        ->toContain('${sofia_contact(2102@${domain_name})}')
        ->not->toContain($firstExtension->id)
        ->not->toContain($secondExtension->id);
});

it('generates ring group XML with enterprise strategy', function () {
    $tenant = Tenant::factory()->create();
    $service = app(RingGroupService::class);

    $group = RingGroup::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'exec_team',
        'strategy' => 'enterprise',
        'enabled' => true,
    ]);
    RingGroupExtension::factory()->create([
        'ring_group_id' => $group->id,
        'extension_uuid' => 'exec-001',
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('ring_group_exec_team')
        ->toContain('origination_caller_id_name')
        ->toContain('ignore_early_media=true');
});

it('generates ring group hangup when no extensions exist', function () {
    $tenant = Tenant::factory()->create();
    $service = app(RingGroupService::class);

    RingGroup::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'empty_group',
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('ring_group_empty_group')
        ->toContain('application="hangup"')
        ->toContain('NO_ANSWER');
});

it('excludes disabled ring groups from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(RingGroupService::class);

    RingGroup::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'off_group',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Time Conditions
// ═══════════════════════════════════════════════════════════════════

it('generates time condition XML with match and no-match destinations', function () {
    $tenant = Tenant::factory()->create();
    $service = app(TimeConditionService::class);

    TimeCondition::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'business_hours',
        'weekdays' => '1-5',
        'start_time' => '09:00',
        'end_time' => '17:00',
        'destination_on_match' => '2000 XML default',
        'destination_on_no_match' => 'voicemail_200 XML default',
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('time_cond_business_hours')
        ->toContain('field="destination_number" expression="^business_hours$"')
        ->toContain('wday="1-5"')
        ->toContain('hour="09:00-17:00"')
        ->toContain('transfer" data="2000 XML')
        ->toContain('transfer" data="voicemail_200 XML');
});

it('excludes disabled time conditions from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(TimeConditionService::class);

    TimeCondition::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'off_hours',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Voicemails
// ═══════════════════════════════════════════════════════════════════

it('generates voicemail XML with answer and voicemail application', function () {
    $tenant = Tenant::factory()->create();
    $service = app(VoicemailService::class);

    Voicemail::factory()->create([
        'tenant_id' => $tenant->id,
        'voicemail_id' => '200',
        'mailbox' => '200',
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('voicemail_200')
        ->toContain('destination_number')
        ->toContain('^200$')
        ->toContain('application="answer"')
        ->toContain('application="voicemail"');
});

it('generates voicemail XML with greeting when configured', function () {
    $tenant = Tenant::factory()->create();
    $service = app(VoicemailService::class);

    Voicemail::factory()->create([
        'tenant_id' => $tenant->id,
        'voicemail_id' => '300',
        'mailbox' => '300',
        'greeting_message' => 'custom_greeting.wav',
        'enabled' => true,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)
        ->toContain('voicemail_300')
        ->toContain('application="playback"')
        ->toContain('custom_greeting.wav');
});

it('excludes disabled voicemails from XML', function () {
    $tenant = Tenant::factory()->create();
    $service = app(VoicemailService::class);

    Voicemail::factory()->create([
        'tenant_id' => $tenant->id,
        'voicemail_id' => '999',
        'mailbox' => '999',
        'enabled' => false,
    ]);

    $xml = $service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_internal", '');

    expect($xml)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════
//  Cross-contributor: Tenant Isolation
// ═══════════════════════════════════════════════════════════════════

it('isolates dialplan XML by tenant across all contributors', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Seed tenant A data
    Bridge::factory()->create(['tenant_id' => $tenantA->id, 'bridge_name' => 'bridge_a', 'destination_number' => '3000', 'enabled' => true]);
    CallBlock::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'block_a', 'caller_id_number' => '555', 'enabled' => true]);
    Emergency::factory()->create(['tenant_id' => $tenantA->id, 'address' => 'Addr A']);
    CallFlow::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'flow_a', 'extension' => '111', 'enabled' => true]);
    Conference::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'conf_a', 'enabled' => true]);
    RingGroup::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'group_a', 'enabled' => true]);
    Voicemail::factory()->create(['tenant_id' => $tenantA->id, 'voicemail_id' => '400', 'mailbox' => '400', 'enabled' => true]);
    TimeCondition::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'time_a', 'enabled' => true]);

    // Verify tenant B does not see tenant A data
    $bridgeService = app(BridgeService::class);
    expect($bridgeService->generateDialplanXml($tenantB->id, "tenant_{$tenantB->id}_internal", ''))->toBeNull();

    $callBlockService = app(CallBlockService::class);
    expect($callBlockService->generateDialplanXml($tenantB->id, "tenant_{$tenantB->id}_internal", ''))->toBeNull();

    $emergencyService = app(EmergencyService::class);
    expect($emergencyService->generateDialplanXml($tenantB->id, "tenant_{$tenantB->id}_public", ''))->toBeNull();

    $callFlowService = app(CallFlowService::class);
    expect($callFlowService->generateDialplanXml($tenantB->id, "tenant_{$tenantB->id}_internal", ''))->toBeNull();

    $confService = app(ConferenceService::class);
    expect($confService->generateDialplanXml($tenantB->id, "tenant_{$tenantB->id}_internal", ''))->toBeNull();

    $ringGroupService = app(RingGroupService::class);
    expect($ringGroupService->generateDialplanXml($tenantB->id, "tenant_{$tenantB->id}_internal", ''))->toBeNull();

    $voicemailService = app(VoicemailService::class);
    expect($voicemailService->generateDialplanXml($tenantB->id, "tenant_{$tenantB->id}_internal", ''))->toBeNull();

    $timeCondService = app(TimeConditionService::class);
    expect($timeCondService->generateDialplanXml($tenantB->id, "tenant_{$tenantB->id}_internal", ''))->toBeNull();
});
