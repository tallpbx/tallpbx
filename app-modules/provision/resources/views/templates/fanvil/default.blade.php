# Fanvil Device Configuration
# Generated: {{ now() }}
# MAC: {{ $device->mac_address }}

[line0]
active = 1
sip_server = {{ config('freeswitch.server', 'pbx.example.com') }}
sip_port = 5060
username = {{ $settings['sip_username'] ?? '' }}
password = {{ $settings['sip_password'] ?? '' }}
auth_name = {{ $settings['sip_username'] ?? '' }}
display_name = {{ $settings['display_name'] ?? 'IP Phone' }}
dtmf_mode = {{ $settings['dtmf'] ?? 'rfc2833' }}
call_waiting = {{ $settings['call_waiting'] ?? 1 }}
dnd = {{ $settings['dnd'] ?? 0 }}

[network]
dhcp = {{ $settings['dhcp'] ?? 1 }}
vlan = {{ $settings['vlan'] ?? 0 }}
# fanvil Device Configuration
# MAC: {{ $device->mac_address }}
# Model: {{ $device->model }}
# Generated: {{ now() }}
