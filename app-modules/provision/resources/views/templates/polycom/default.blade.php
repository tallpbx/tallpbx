<?xml version="1.0" encoding="UTF-8"?>
<!-- Polycom Device Configuration -->
<!-- MAC: {{ $device->mac_address }} -->
<!-- Generated: {{ now() }} -->
<polycomConfig>
    <reg>
        <reg.1.address>{{ $settings['sip_username'] ?? '' }}</reg.1.address>
        <reg.1.auth.password>{{ $settings['sip_password'] ?? '' }}</reg.1.auth.password>
        <reg.1.auth.userId>{{ $settings['sip_username'] ?? '' }}</reg.1.auth.userId>
        <reg.1.label>{{ $settings['display_name'] ?? 'IP Phone' }}</reg.1.label>
        <reg.1.server.1.address>{{ config('freeswitch.server', 'pbx.example.com') }}</reg.1.server.1.address>
        <reg.1.server.1.port>5060</reg.1.server.1.port>
        <reg.1.transport>0</reg.1.transport>
        <reg.1.thirdPartyName>{{ $settings['display_name'] ?? 'IP Phone' }}</reg.1.thirdPartyName>
    </reg>
    <tcpIpApp>
        <tcpIpApp.dhcp.enabled>{{ $settings['dhcp'] ?? 1 }}</tcpIpApp.dhcp.enabled>
        <tcpIpApp.vlan.discovery-enabled>{{ $settings['vlan'] ?? 0 }}</tcpIpApp.vlan.discovery-enabled>
    </tcpIpApp>
    <call>
        <call.dnd.available>{{ $settings['dnd'] ?? 0 }}</call.dnd.available>
    </call>
</polycomConfig>
# polycom Device Configuration
# MAC: {{ $device->mac_address }}
# Model: {{ $device->model }}
# Generated: {{ now() }}
