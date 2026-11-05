# SIPp End-To-End Validation

- Started: 2026-11-05T00:54:36+00:00
- PBX target: x.x.x.138:5060
- Load generator IP: x.x.x.55
- Tenant: load-test-beta
- SIP realm: x.x.x.138
- CSV: /var/www/tallpbx/storage/app/load-tests/sipp-users.csv
- SIPp auth CSV: /var/www/tallpbx/storage/app/load-tests/refresh-20261103/h1-1c1g/v-lua-fast/sip-cps5/sipp-users-auth.csv
- Register rate/count: 5/20
- Call rate/limit/count: 5/300/300
- Outbound call rate/limit/count: 1/2/1
- Seed skipped: 1
- Media flow enabled: 0
- Extended parity scenarios: 0

- register: passed
- extension-registered-uas: started as pid 23932
- outbound-gateway-uas: started as pid 23936
- extension-calls: passed
- outbound-calls: passed

- Finished: 2026-11-05T00:56:28+00:00
- Artifacts: /var/www/tallpbx/storage/app/load-tests/refresh-20261103/h1-1c1g/v-lua-fast/sip-cps5
