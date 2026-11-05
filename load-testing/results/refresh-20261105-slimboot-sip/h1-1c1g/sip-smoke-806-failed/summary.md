# SIPp End-To-End Validation

- Started: 2026-11-05T11:31:07+00:00
- PBX target: x.x.x.138:5060
- Load generator IP: x.x.x.55
- Tenant: load-test-beta
- SIP realm: x.x.x.138
- CSV: /var/www/tallpbx/storage/app/load-tests/sipp-users.csv
- SIPp auth CSV: /var/www/tallpbx/storage/app/load-tests/refresh-20261105-slimboot-sip/h1-1c1g/sip-smoke/sipp-users-auth.csv
- Register rate/count: 5/1
- Call rate/limit/count: 2/1/1
- Outbound call rate/limit/count: 1/1/1
- Seed skipped: 1
- Media flow enabled: 0
- Extended parity scenarios: 0

- register: passed
- extension-registered-uas: started as pid 31481
- outbound-gateway-uas: started as pid 31485
- extension-calls: failed with exit code 1
