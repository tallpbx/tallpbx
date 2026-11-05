# SIPp End-To-End Validation

- Started: 2026-11-05T07:44:55+00:00
- PBX target: x.x.x.138:5060
- Load generator IP: x.x.x.55
- Tenant: load-test-beta
- SIP realm: x.x.x.138
- CSV: /var/www/tallpbx/storage/app/load-tests/sipp-users.csv
- SIPp auth CSV: /var/www/tallpbx/storage/app/load-tests/refresh-20261103-cdr-off/h1-1c1g/v-lua-stock/sip-cps10/sipp-users-auth.csv
- Register rate/count: 5/20
- Call rate/limit/count: 10/600/600
- Outbound call rate/limit/count: 1/2/1
- Seed skipped: 1
- Media flow enabled: 0
- Extended parity scenarios: 0

- register: passed
- extension-registered-uas: started as pid 29982
- outbound-gateway-uas: started as pid 29986
- extension-calls: passed
- outbound-calls: passed

- Finished: 2026-11-05T07:46:42+00:00
- Artifacts: /var/www/tallpbx/storage/app/load-tests/refresh-20261103-cdr-off/h1-1c1g/v-lua-stock/sip-cps10
