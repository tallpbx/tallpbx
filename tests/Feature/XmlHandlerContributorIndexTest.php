<?php

use Illuminate\Support\Facades\Schema;

it('has composite indexes for XML handler contributor lookups', function (string $table, string $indexName, array $columns): void {
    $index = collect(Schema::getIndexes($table))->firstWhere('name', $indexName);

    expect($index)->not->toBeNull()
        ->and($index['columns'])->toBe($columns);
})->with([
    'bridges' => ['bridges', 'bridges_xml_tenant_enabled_name_idx', ['tenant_id', 'enabled', 'bridge_name']],
    'call blocks' => ['call_blocks', 'call_blocks_xml_tenant_enabled_name_idx', ['tenant_id', 'enabled', 'name']],
    'call center queues' => ['call_center_queues', 'call_center_queues_xml_tenant_enabled_name_idx', ['tenant_id', 'enabled', 'name']],
    'call flows' => ['call_flows', 'call_flows_xml_tenant_enabled_extension_idx', ['tenant_id', 'enabled', 'extension']],
    'call forwards' => ['call_forwards', 'call_forwards_xml_tenant_enabled_extension_idx', ['tenant_id', 'enabled', 'extension_uuid']],
    'conference centers' => ['conference_centers', 'conference_centers_xml_tenant_enabled_name_idx', ['tenant_id', 'enabled', 'name']],
    'conferences' => ['conferences', 'conferences_xml_tenant_enabled_name_idx', ['tenant_id', 'enabled', 'name']],
    'emergency config' => ['emergency_config', 'emergency_config_xml_tenant_idx', ['tenant_id']],
    'feature codes' => ['feature_codes', 'feature_codes_xml_tenant_enabled_code_idx', ['tenant_id', 'enabled', 'code']],
    'follow me' => ['follow_me', 'follow_me_xml_tenant_enabled_extension_idx', ['tenant_id', 'enabled', 'extension']],
    'inbound routes' => ['inbound_routes', 'inbound_routes_xml_tenant_enabled_priority_idx', ['tenant_id', 'enabled', 'priority']],
    'ivr menus' => ['ivr_menus', 'ivr_menus_xml_tenant_enabled_name_idx', ['tenant_id', 'enabled', 'name']],
    'ivr menu options' => ['ivr_menu_options', 'ivr_menu_options_xml_menu_order_idx', ['ivr_menu_id', 'order']],
    'outbound routes' => ['outbound_routes', 'outbound_routes_xml_tenant_enabled_priority_idx', ['tenant_id', 'enabled', 'priority']],
    'ring groups' => ['ring_groups', 'ring_groups_xml_tenant_enabled_name_idx', ['tenant_id', 'enabled', 'name']],
    'ring group extensions' => ['ring_group_extensions', 'ring_group_extensions_xml_group_position_idx', ['ring_group_id', 'position']],
    'time conditions' => ['time_conditions', 'time_conditions_xml_tenant_enabled_name_idx', ['tenant_id', 'enabled', 'name']],
    'voicemails' => ['voicemails', 'voicemails_xml_tenant_enabled_mailbox_idx', ['tenant_id', 'enabled', 'voicemail_id']],
]);
