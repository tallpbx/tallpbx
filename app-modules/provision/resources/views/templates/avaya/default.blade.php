<?xml version="1.0" encoding="UTF-8"?>
<!-- Avaya Device Configuration -->
<!-- MAC: {{ $device->mac_address }} -->
<!-- Generated: {{ now() }} -->
<phoneConfig>
    <line n="1">
        <enable>1</enable>
        <proxy>{{ config('freeswitch.server', 'pbx.example.com') }}</proxy>
        <proxyPort>5060</proxyPort>
        <userId>{{ $settings['sip_username'] ?? '' }}</userId>
        <password>{{ $settings['sip_password'] ?? '' }}</password>
        <authName>{{ $settings['sip_username'] ?? '' }}</authName>
        <displayName>{{ $settings['display_name'] ?? 'IP Phone' }}</displayName>
        <callWaiting>{{ $settings['call_waiting'] ?? 1 }}</callWaiting>
        <dnd>{{ $settings['dnd'] ?? 0 }}</dnd>
    </line>
    <network>
        <dhcp>{{ $settings['dhcp'] ?? 1 }}</dhcp>
        <vlan>{{ $settings['vlan'] ?? 0 }}</vlan>
    </network>
    <timezone>{{ $settings['timezone'] ?? 'America/New_York' }}</timezone>
</phoneConfig>
# avaya Device Configuration
# MAC: {{ $device->mac_address }}
# Model: {{ $device->model }}
# Generated: {{ now() }}
