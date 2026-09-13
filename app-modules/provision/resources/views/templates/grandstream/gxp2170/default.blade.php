# Grandstream GXP2170 Device Configuration
# Generated: {{ now() }}
# MAC: {{ $device->mac_address }}

[account0]
account_active = 1
account_name = SIP Account
sip_server = {{ config('freeswitch.server', 'pbx.example.com') }}
sip_server2 = 
sip_user_id = {{ $settings['sip_username'] ?? '' }}
sip_password = {{ $settings['sip_password'] ?? '' }}
sip_auth_id = {{ $settings['sip_username'] ?? '' }}
time_zone = {{ $settings['timezone'] ?? 'America/New_York' }}
date_format = 3
name = {{ $settings['display_name'] ?? 'IP Phone' }}
primary_sip_port = 5060
use_session_timers = 0
dnd = {{ $settings['dnd'] ?? 0 }}
call_waiting = {{ $settings['call_waiting'] ?? 1 }}
active_protocol = 0

[account1]
account_active = 0

[network]
dhcp = {{ $settings['dhcp'] ?? 1 }}
vlan = {{ $settings['vlan'] ?? 0 }}
pc_port_vlan = {{ $settings['pc_vlan'] ?? 0 }}

[multipurpose_keys]
linekey.0 = 1
linekey.0.mode = 0
