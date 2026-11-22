# Desk-Phone Provisioning Guide

How to make a supported desk phone configure itself from TallPBX, and how to
diagnose the two failures you will actually hit. Provisioning is optional: a
manual softphone account remains the shortest path to a first call (see
[PBX "Hello World"](pbx-hello-world.md)).

## 1. Enable provisioning

Provisioning is off by default: `/provision/{mac}` answers `404` until the
master switch is on. Set the key and clear the configuration cache:

```bash
if grep -q '^PROVISIONING_ENABLED=' /var/www/tallpbx/.env; then
    sudo sed -i 's/^PROVISIONING_ENABLED=.*/PROVISIONING_ENABLED=true/' /var/www/tallpbx/.env
else
    echo 'PROVISIONING_ENABLED=true' | sudo tee -a /var/www/tallpbx/.env >/dev/null
fi
cd /var/www/tallpbx && tpbx optimize:clear
```

The endpoint is served by the `provision` module route:

| Setting | Config key | Effect |
| :--- | :--- | :--- |
| `PROVISIONING_ENABLED` | `provisioning.enabled` | Master gate. `false` makes the endpoint behave as if it does not exist. |
| `PROVISIONING_HTTP_AUTH_USERNAME` | `provisioning.http_auth_username` | HTTP **Basic** username. Each filled factor is required; blank disables that factor. |
| `PROVISIONING_HTTP_AUTH_PASSWORD` | `provisioning.http_auth_password` | HTTP **Basic** password. |
| `PROVISIONING_CIDR` | `provisioning.cidr` | Comma-separated CIDRs allowed to request a configuration. Other addresses get `403`. |

Before enabling provisioning in production, set `PROVISIONING_CIDR` in
`/var/www/tallpbx/.env` to the phone network. A phone that can fetch its
configuration could fetch someone else's if it guessed a MAC.

Only Basic authentication is enforced. There is no Digest path in the
middleware, so a phone configured for Digest must be pointed at Basic instead.

## 2. Assign a template and a SIP account

1. Open **Accounts → Devices** and edit (or create) the device for the phone's MAC
   address. The MAC is the identifier in the provisioning URL, so it must
   match the sticker exactly.
2. Choose a **provisioning template** for the vendor. Built-in templates ship
   for 12 vendors (`aastra`, `algo`, `avaya`, `cisco`, `fanvil`, `flyingvoice`,
   `grandstream`, `htek`, `poly`, `polycom`, `snom`, and `yealink`); manage
   template records and file paths under **Features → Device Provisioning**.
3. Select the **SIP account** the phone registers with. A device without a SIP
   account downloads a configuration with no credentials and will not register.
4. Save. The generated template embeds `freeswitch.server` as the SIP registrar
   — a bare hostname or IP. A URL-shaped `FS_SERVER` is normalised to its host,
   so the phone never receives `http://…` in the registrar field.

## 3. Point the phone at the endpoint

On the phone's provisioning/server field, enter:

```
http://<pbx-host>/provision/<mac>
```

Use HTTPS if the appliance has a certificate the phone trusts. If Basic
authentication is configured, set the same username and password on the phone's
provisioning page.

Reboot or re-request provisioning on the phone.

## 4. Expected result

- The phone downloads the configuration and reboots once.
- The account registers: **Monitoring → Registrations** shows the device registered, and the
  extension's SIP account shows a contact in `fs_cli -x "sofia status profile internal reg"`.
- An internal call to the extension rings the handset.

## 5. Troubleshooting

Provisioning settings are in `/var/www/tallpbx/.env`.

| Symptom | Cause | Fix |
| :--- | :--- | :--- |
| `404` on `/provision/<mac>` | Provisioning switch is off, or the MAC is not registered in **Devices** | Set `PROVISIONING_ENABLED=true`, run `tpbx optimize:clear`, and confirm the device exists |
| `401 Unauthorized` | Basic credentials are configured but the phone sends none or the wrong pair | Match `PROVISIONING_HTTP_AUTH_USERNAME` / `..._PASSWORD` exactly, or blank both to disable the factor |
| `403 Forbidden` | The phone's address is outside `PROVISIONING_CIDR` | Add the phone network to the CIDR list |
| `429 Too Many Attempts` | Five failed authentication attempts from that address within a minute | Wait a minute, then correct the credentials |
| Configuration downloads, phone does not register | Device has no SIP account assigned, or the extension form was saved without a SIP password | Assign an account and set a password on the extension form (see [PBX Concepts](pbx-concepts.md)) |
| Registrar shows a URL | An old `.env` still hands a URL to a template that predates the normalisation | Run `tpbx optimize:clear` so `config/freeswitch.php` re-reads the environment |

## Related Documentation

- [PBX Concepts](pbx-concepts.md) — how users, extensions, SIP accounts, and devices relate.
- [PBX "Hello World"](pbx-hello-world.md) — the shortest first-call path with a softphone.
- [Environment Reference](env-reference.md) — every `PROVISIONING_*` key with its default.
