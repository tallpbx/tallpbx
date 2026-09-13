<?xml version="1.0" encoding="UTF-8"?>
<!-- Algo Device Configuration -->
<!-- MAC: {{ $device->mac_address }} -->
<!-- Generated: {{ now() }} -->
<algoConfig>
    <sip>
        <sipEnabled>1</sipEnabled>
        <sipServer>{{ config('freeswitch.server', 'pbx.example.com') }}</sipServer>
        <sipPort>5060</sipPort>
        <sipUser>{{ $settings['sip_username'] ?? '' }}</sipUser>
        <sipPassword>{{ $settings['sip_password'] ?? '' }}</sipPassword>
        <sipAuthId>{{ $settings['sip_username'] ?? '' }}</sipAuthId>
        <sipDisplayName>{{ $settings['display_name'] ?? 'Algo Device' }}</sipDisplayName>
    </sip>
    <network>
        <dhcp>{{ $settings['dhcp'] ?? 1 }}</dhcp>
        <vlan>{{ $settings['vlan'] ?? 0 }}</vlan>
    </network>
    <audio>
        <volume>{{ $settings['volume'] ?? 50 }}</volume>
    </audio>
</algoConfig>
# algo Device Configuration
# MAC: {{ $device->mac_address }}
# Model: {{ $device->model }}
# Generated: {{ now() }}
