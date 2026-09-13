# PBX "Hello World": Your First Call on TallPBX

A step-by-step guide to creating your first SIP extension, connecting a softphone or hardware IP phone, and running an audio loopback echo test.

---

## What is the PBX Equivalent of "Hello World"?

In software development, printing `"Hello, World!"` proves your compiler, runtime, and output pipe function correctly.

In telephony and VoIP PBX systems, the **"Hello World"** is the **Echo Test (`*9196`)**.

Making a successful echo test call proves the entire VoIP pipeline is operational:

```
┌─────────────────┐         SIP Signaling (UDP 5060)         ┌─────────────────┐
│                 │ ───────────────────────────────────────> │                 │
│   SIP Client    │          Digest Authentication           │     TallPBX     │
│   (Softphone)   │ <─────────────────────────────────────── │   (FreeSWITCH)  │
│                 │                                          │                 │
│                 │         RTP Media Stream (Audio)         │                 │
│  Mic / Speaker  │ ═══════════════════════════════════════> │   echo() App    │
│                 │ <═══════════════════════════════════════ │ (Audio Loopback)│
└─────────────────┘                                          └─────────────────┘
```

1. **Authentication**: The client authenticates against TallPBX's dynamic database directory via `mod_xml_curl`.
2. **SIP Registration**: FreeSWITCH registers the client's network contact endpoint (`sofia_contact`).
3. **Dialplan Routing**: The dialed destination (`*9196`) matches the tenant's internal dialplan rules.
4. **Media Negotiation**: Audio codecs (Opus, G.711u / PCMU, G.711a / PCMA) negotiate between client and server.
5. **Bi-Directional Audio**: 2-way RTP media streams travel over UDP without NAT or firewall blockage, echoing your voice straight back to your speakers in real-time.

---

## Prerequisites

Before starting, ensure you have:

- **TallPBX installed and running** (accessible via browser at `http://<your-server-ip>/panel`).
- **An administrator or tenant account** to log in to the TallPBX Panel.
- **A SIP softphone client** installed on your computer or mobile device. Recommended free clients:
  - **Windows**: [MicroSIP](https://www.microsip.org/) (lightweight, portable)
  - **Linux / macOS / Windows**: [Linphone](https://www.linphone.org/)
  - **iOS / Android**: **Linphone** or **Grandstream Wave**
  - **Hardware Desk Phone**: Yealink, Grandstream, Polycom, Fanvil, Cisco, etc.

---

## Step 1: Create an Extension in TallPBX

1. Open your browser and log in to the TallPBX Panel at `http://<your-server-ip>/panel/login`.
2. In the navigation sidebar (or top menu in horizontal mode), go to **PBX → Extensions** (`/panel/extensions`).
3. Click the **+ Create Extension** button in the top right corner.
4. Fill in the required fields:

| Field | Recommended Value | Description |
|---|---|---|
| **Extension Number** | `1001` | The 3- to 6-digit number assigned to this phone. |
| **Effective Caller ID Name** | `My First Extension` | The caller name displayed when making internal calls. |
| **Effective Caller ID Number** | `1001` | The caller ID number sent with outbound calls. |
| **SIP Password** | *(Enter a secure password)* | Password the SIP device will use to register and authenticate. |
| **Voicemail Enabled** | Checked (Optional) | Creates a voicemail box for this extension. |
| **Status / Enabled** | Checked | Ensures the extension is active in the directory. |

5. Click **Save**.

TallPBX instantly generates the directory mapping for FreeSWITCH. No server reload or restart is required.

---

## Step 2: Configure Your SIP Device or Softphone

Open your softphone application (e.g. **MicroSIP** or **Linphone**).

### Configuration Example: MicroSIP (Windows)

1. Open MicroSIP and click the down arrow in the top-right corner → **Add Account...**
2. Configure the following fields:

- **Account Name**: `TallPBX 1001` *(arbitrary label)*
- **SIP Server**: `<your-server-ip>` *(e.g. `192.168.1.76`)*
- **SIP Proxy**: *(leave blank)*
- **User**: `1001`
- **Domain**: `<your-server-ip>` *(e.g. `192.168.1.76`)*
- **Login**: `1001`
- **Password**: `<the password you entered in Step 1>`
- **Display Name**: `Extension 1001`
- **Transport**: `UDP` *(default port 5060)*

3. Click **Save**.

### Configuration Example: Linphone (Linux / macOS / Windows / Mobile)

1. Open Linphone and navigate to **Assistant → Use a SIP Account**.
2. Fill in:
   - **Username**: `1001`
   - **SIP Domain**: `<your-server-ip>` *(e.g. `192.168.1.76`)*
   - **Password**: `<the password you entered in Step 1>`
   - **Transport**: `UDP`
3. Click **Use**.

---

## Step 3: Verify SIP Registration

### 1. On the Softphone
Within a second or two, your softphone's status icon should change from grey/red to **green** with the status **"Online"** or **"Connected"**.

### 2. In the TallPBX Panel
1. In the TallPBX panel, navigate to **PBX → Registrations** (`/panel/registrations`).
2. You will see extension `1001` listed with:
   - **User**: `1001@<server-ip>`
   - **IP Address**: Your client device IP
   - **Agent / Client**: Softphone software name and version (e.g., `MicroSIP/3.21.3`)
   - **Status**: `Registered`

### 3. From the Terminal (Optional)
You can also verify registration directly in FreeSWITCH via the CLI:

```bash
fs_cli -x "sofia status profile internal reg"
```

You will see output showing `1001` registered on the internal profile:

```
Call-ID:      ...
User:         1001@192.168.1.76
Contact:      "1001" <sip:1001@192.168.1.65:5060;transport=udp>
Agent:        MicroSIP/3.21.3
Status:       Registered(UDP) (EXP: 120)
```

---

## Step 4: The "Hello World" Call — Echo Test (`*9196`)

Now for the moment of truth:

1. Bring up your softphone's dialpad.
2. Dial **`*9196`** (or **`9196`**).
3. Press **Call** (the green telephone button).

### What to Expect
1. The call will connect and be answered **immediately** (no ringing tone).
2. Speak into your microphone:
   > *"Hello, World! TallPBX is working."*
3. You should hear your exact words echoed back through your headphones or speakers with a fraction-of-a-second delay.
4. Press **Hang Up** (the red telephone button) to terminate the call.

Congratulations! You have completed the PBX equivalent of "Hello World". Your PBX is actively authenticating endpoints, processing dialplans, and streaming full-duplex RTP media.

---

## Step 5: What Next?

Now that basic signaling and audio are verified:

1. **Extension-to-Extension Calls**:
   - Create a second extension (e.g., `1002`).
   - Register a second softphone (on your phone or another laptop).
   - Dial `1002` from `1001`. Hear the phone ring and speak across extensions.

2. **Voicemail Access**:
   - Dial **`*97`** to enter your extension's personal voicemail box.
   - Follow the voice prompts to record your greeting or listen to messages.

3. **Explore Feature Codes**:
   - Go to **PBX → Feature Codes** (`/panel/feature-codes`) to see standard dial codes:
     - `*9196` — Echo Test
     - `*97` — Check Voicemail
     - `*732` / `*733` — Start / Stop On-Demand Call Recording
     - `*72` — Toggle Follow-Me Call Routing
     - `*8` — Intercom / Group Paging
     - `*55` — Conference Bridge

4. **Connect External Phone Numbers (DIDs)**:
   - Add a SIP trunk gateway in **PBX → Gateways**.
   - Create an **Inbound Route** to direct incoming calls to your extension or an IVR menu.

---

## Troubleshooting

| Symptom | Probable Cause | Resolution |
|---|---|---|
| **Registration Timeout / Could not connect** | Firewall blocking SIP signaling on UDP port 5060. | Ensure firewall allows traffic: `sudo ufw allow 5060/udp`. Check that server IP is reachable via `ping <server-ip>`. |
| **403 Forbidden / Bad Credentials** | Extension number or password mismatch. | Verify the username is only the extension digits (`1001`), and re-enter the password in softphone settings. |
| **Call connects, but no sound is heard** | RTP media UDP ports blocked or asymmetric NAT. | FreeSWITCH uses RTP ports `16384-32768` (UDP). Ensure these ports are open in the server firewall: `sudo ufw allow 16384:32768/udp`. |
| **Call rejected with "User not found" or "No Route"** | Extension or dialplan context misconfiguration. | Verify the tenant has baseline dialplans provisioned under **PBX → Dialplans**. |
