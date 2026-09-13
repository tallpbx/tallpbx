# Yealink Device Configuration
# Generated: {{ now() }}
# MAC: {{ $device->mac_address }}

account.1.enable = 1
account.1.label = SIP Account
account.1.display_name = {{ $settings['display_name'] ?? 'IP Phone' }}
account.1.user_name = {{ $settings['sip_username'] ?? '' }}
account.1.auth_name = {{ $settings['sip_username'] ?? '' }}
account.1.password = {{ $settings['sip_password'] ?? '' }}
account.1.sip_server.1.address = {{ config('freeswitch.server', 'pbx.example.com') }}
account.1.sip_server.1.port = 5060
account.1.sip_server.2.address =
account.1.sip_server.2.port = 0
account.1.transport = 0
account.1.dnd = {{ $settings['dnd'] ?? 0 }}
account.1.call_waiting = {{ $settings['call_waiting'] ?? 1 }}
account.1.dtmf.type = {{ $settings['dtmf'] ?? 'RFC2833' }}

network.dhcp = {{ $settings['dhcp'] ?? 1 }}
network.vlan.enable = {{ $settings['vlan'] ?? 0 }}
network.vlan.id = {{ $settings['vlan'] ?? 0 }}

timezone = {{ $settings['timezone'] ?? 'America/New_York' }}
# yealink Device Configuration
# MAC: {{ $device->mac_address }}
# Model: {{ $device->model }}
# Generated: {{ now() }}
