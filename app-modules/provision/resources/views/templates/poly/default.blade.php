<?xml version="1.0" encoding="UTF-8"?>
<!-- Poly Device Configuration -->
<!-- MAC: {{ $device->mac_address }} -->
<!-- Generated: {{ now() }} -->
<config>
    <sip>
        <account index="1">
            <enable>1</enable>
            <server>{{ config('freeswitch.server', 'pbx.example.com') }}</server>
            <port>5060</port>
            <user>{{ $settings['sip_username'] ?? '' }}</user>
            <password>{{ $settings['sip_password'] ?? '' }}</password>
            <authName>{{ $settings['sip_username'] ?? '' }}</authName>
            <displayName>{{ $settings['display_name'] ?? 'IP Phone' }}</displayName>
            <callWaiting>{{ $settings['call_waiting'] ?? 1 }}</callWaiting>
            <dnd>{{ $settings['dnd'] ?? 0 }}</dnd>
        </account>
    </sip>
    <network>
        <dhcp>{{ $settings['dhcp'] ?? 1 }}</dhcp>
        <vlan>{{ $settings['vlan'] ?? 0 }}</vlan>
    </network>
</config>
