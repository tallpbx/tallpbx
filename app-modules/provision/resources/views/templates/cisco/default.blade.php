<flat-profile>
    <!-- Cisco Device Configuration -->
    <!-- MAC: {{ $device->mac_address }} -->
    <!-- Generated: {{ now() }} -->

    <!-- SIP Account -->
    <Proxy1_>{{ config('freeswitch.server', 'pbx.example.com') }}</Proxy1_>
    <Proxy_Port1_>5060</Proxy_Port1_>
    <Display_Name1_>{{ $settings['display_name'] ?? 'IP Phone' }}</Display_Name1_>
    <User_ID1_>{{ $settings['sip_username'] ?? '' }}</User_ID1_>
    <Password1_>{{ $settings['sip_password'] ?? '' }}</Password1_>
    <Auth_ID1_>{{ $settings['sip_username'] ?? '' }}</Auth_ID1_>
    <Line_Enable1_>1</Line_Enable1_>
    <DTMF_Method_1_>{{ $settings['dtmf'] ?? 'Auto' }}</DTMF_Method_1_>

    <!-- Network -->
    <Dhcp_Enable_>{{ $settings['dhcp'] ?? 1 }}</Dhcp_Enable_>
    <VLAN_ID_>{{ $settings['vlan'] ?? 0 }}</VLAN_ID_>

    <!-- Features -->
    <DND_Enable_>{{ $settings['dnd'] ?? 0 }}</DND_Enable_>
    <Call_Waiting_Enable1_>{{ $settings['call_waiting'] ?? 1 }}</Call_Waiting_Enable1_>
</flat-profile>
# cisco Device Configuration
# MAC: {{ $device->mac_address }}
# Model: {{ $device->model }}
# Generated: {{ now() }}
