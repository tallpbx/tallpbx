# SIPp End-To-End Validation

- Started: 2026-11-05T12:26:41+00:00
- PBX target: x.x.x.138:5060
- Load generator IP: x.x.x.55
- Tenant: load-test-beta
- SIP realm: x.x.x.138
- CSV: /var/www/tallpbx/storage/app/load-tests/sipp-users.csv
- SIPp auth CSV: /var/www/tallpbx/storage/app/load-tests/refresh-20261105-slimboot-sip-octdbg/h1-1c1g/sip-smoke/sipp-users-auth.csv
- Register rate/count: 5/2
- Call rate/limit/count: 2/1/1
- Outbound call rate/limit/count: 1/1/1
- Seed skipped: 1
- Media flow enabled: 0
- Extended parity scenarios: 0

- register: passed
- extension-registered-uas: started as pid 32952
- outbound-gateway-uas: started as pid 32956
- extension-calls: passed
- outbound-calls: passed

- Finished: 2026-11-05T12:28:01+00:00
- Artifacts: /var/www/tallpbx/storage/app/load-tests/refresh-20261105-slimboot-sip-octdbg/h1-1c1g/sip-smoke
