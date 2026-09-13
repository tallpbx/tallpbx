<?xml version="1.0" encoding="UTF-8"?>
<!-- Snom Device Configuration -->
<!-- MAC: {{ $device->mac_address }} -->
<!-- Generated: {{ now() }} -->
<settings>
    <phone-settings>
        <!-- SIP Account -->
        <sip_registrar prime="0">{{ config('freeswitch.server', 'pbx.example.com') }}</sip_registrar>
        <sip_registrar_port prime="0">5060</sip_registrar_port>
        <sip_user prime="0">{{ $settings['sip_username'] ?? '' }}</sip_user>
        <sip_pass prime="0">{{ $settings['sip_password'] ?? '' }}</sip_pass>
        <sip_authname prime="0">{{ $settings['sip_username'] ?? '' }}</sip_authname>
        <sip_display_name prime="0">{{ $settings['display_name'] ?? 'IP Phone' }}</sip_display_name>
        <user_realname prime="0">{{ $settings['display_name'] ?? 'IP Phone' }}</user_realname>

        <!-- Network -->
        <dhcp_enabled prime="0">{{ $settings['dhcp'] ?? 1 }}</dhcp_enabled>
        <vlan_id prime="0">{{ $settings['vlan'] ?? 0 }}</vlan_id>

        <!-- Features -->
        <dnd_on prime="0">{{ $settings['dnd'] ?? 0 }}</dnd_on>
        <call_waiting_enabled prime="0">{{ $settings['call_waiting'] ?? 1 }}</call_waiting_enabled>

        <!-- Time -->
        <timezone_string>{{ $settings['timezone'] ?? 'America/New_York' }}</timezone_string>
    </phone-settings>
</settings>
# snom Device Configuration
# MAC: {{ $device->mac_address }}
# Model: {{ $device->model }}
# Generated: {{ now() }}
