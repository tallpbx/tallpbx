# Aastra Device Configuration
# Generated: {{ now() }}
# MAC: {{ $device->mac_address }}

sip proxy ip: {{ config('freeswitch.server', 'pbx.example.com') }}
sip proxy port: 5060
sip registrar ip: {{ config('freeswitch.server', 'pbx.example.com') }}
sip registrar port: 5060
sip user name: {{ $settings['sip_username'] ?? '' }}
sip auth name: {{ $settings['sip_username'] ?? '' }}
sip password: {{ $settings['sip_password'] ?? '' }}
sip display name: {{ $settings['display_name'] ?? 'IP Phone' }}
sip line: 1

network dhcp: {{ $settings['dhcp'] ?? 1 }}
network vlan: {{ $settings['vlan'] ?? 0 }}

dnd: {{ $settings['dnd'] ?? 0 }}
call waiting: {{ $settings['call_waiting'] ?? 1 }}
# aastra Device Configuration
# MAC: {{ $device->mac_address }}
# Model: {{ $device->model }}
# Generated: {{ now() }}
