# Flyingvoice Device Configuration
# Generated: {{ now() }}
# MAC: {{ $device->mac_address }}

account.1.enable = 1
account.1.display_name = {{ $settings['display_name'] ?? 'IP Phone' }}
account.1.sip_server = {{ config('freeswitch.server', 'pbx.example.com') }}
account.1.sip_port = 5060
account.1.username = {{ $settings['sip_username'] ?? '' }}
account.1.password = {{ $settings['sip_password'] ?? '' }}
account.1.auth_name = {{ $settings['sip_username'] ?? '' }}
account.1.call_waiting = {{ $settings['call_waiting'] ?? 1 }}
account.1.dnd = {{ $settings['dnd'] ?? 0 }}

network.dhcp = {{ $settings['dhcp'] ?? 1 }}
network.vlan = {{ $settings['vlan'] ?? 0 }}
