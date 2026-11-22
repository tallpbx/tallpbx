# Release Checklist

A release ships only after every item below has been executed **on the appliance
that will run the code**. Run the release commands from `/var/www/tallpbx`.
The CI workflow (`.github/workflows/tests.yml`) is a manual environment-parity
check, not the gate; it is dispatched by hand and its result does not replace the
local run.

## 1. Scope

Record what the release contains in one or two sentences, and list the audit
findings or modules it closes (for example `C01`, `C02`, `O01`–`O08`).

- Release version: `vX.Y.Z`
- Series branch: `<branch>` (for example `5.x`)
- Scope summary:

## 2. Commit under test

```bash
git branch --show-current
git status --porcelain        # must be empty
git rev-parse HEAD            # record the full SHA below
```

- SHA under test:
- The tag must point at this exact SHA (`git rev-parse vX.Y.Z^{commit}`).

## 3. Commands run

Run each command once in this order:

```bash
tpbx optimize:clear
./vendor/bin/pint --dirty --test
tpbx test --compact --parallel
./vendor/bin/pest tests/Browser   # only for packages that change a rendered journey
```

- [ ] Every command was executed against the SHA above.
- [ ] No command was skipped "because it passed earlier".

## 4. Environment record

- PHP version (`php -v`):
- Database engine and version (`mariadb --version`):
- Redis version (`redis-server --version`):
- FreeSWITCH version (`freeswitch -version`):

## 5. Results

Record the pass/fail counts printed by each command. A red run is a stop; fix
and restart from step 2 with the new SHA.

| Command | Result | Passed | Failed | Skipped |
| :--- | :--- | :--- | :--- | :--- |
| `pint --dirty --test` | | — | — | — |
| `tpbx test --compact --parallel` | | | | |
| `pest tests/Browser` | | | | |

## 6. Disposable MariaDB exercise

Required for any change that touches restore, backup, or CDR ingestion. On a
disposable database (never the production schema):

- [ ] A backup was taken and restored into the disposable database.
- [ ] `backups:reconcile-restore-journal` recreated the projected operation row.
- [ ] `sudo tallpbx-restore-status` reported the operation without a database
  connection when the database was stopped.
- [ ] A deliberately rejected CDR row was quarantined while its neighbours
  committed.

## 7. Tagging

```bash
# After the changelog cut is committed on the series branch:
git tag -a vX.Y.Z -m "TallPBX vX.Y.Z"
git push origin <branch> --follow-tags
```

- [ ] `CHANGELOG.md` has a dated `## [X.Y.Z] - YYYY-MM-DD` section.
- [ ] `RELEASE-NOTES.md` has the matching `## vX.Y.Z (YYYY-MM-DD)` section.
- [ ] `scripts/release-notes.sh` accepts the tag locally (it refuses tags that
  are missing from either file).

## 8. Statement

Verification is local. The appliance run above is the release evidence; the CI
workflow is optional parity checking. Attach this checklist (or its filled
values) to the release record so the tested SHA, environment, and results stay
traceable.
