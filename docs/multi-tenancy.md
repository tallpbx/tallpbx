# Tenants & Multi-Tenancy

If you are new to TallPBX, the word *tenant* can sound like technical jargon. This guide explains what a tenant is, the two kinds you will meet, who can reach each one, and how administrators look around on behalf of a customer — all in plain, non-technical language.

If you are looking for the phone-side pieces (users, extensions, SIP accounts, and devices), see [Telephony Fundamentals](pbx-concepts.md). This page is about the **organizational boundary** that keeps those pieces separate for each customer.

---

## What is a Tenant?

A **tenant** is one organization or customer on the phone system — for example, a single company that uses the PBX.

A tenant is the container that holds everything belonging to that organization:

- Its **extensions** (the phone numbers people dial)
- Its **SIP accounts** (the phones that register and make calls)
- Its **dialplans, SIP profiles, and settings** (how calls are routed and handled)
- Its **users** (the people who log into the portal to manage it)

Each tenant's data is kept separate from every other tenant's. Two companies can use the same extension number (for example, both have an extension `100`) without any conflict, because each tenant's records live in their own space and never overlap.

**Why it matters:** the tenant is the boundary for privacy and access. A user in one tenant can only see and manage their own tenant's phones and settings — never another's. That isolation is what lets one phone system safely serve many separate companies at once.

### Users and tenants

A **user** is a person who logs in. A user belongs to one or more tenants and can hold a different role in each. Their permissions come from their groups and are always scoped to the active tenant — so a user with access to two tenants sees one tenant at a time.

One important safeguard: a regular tenant user can **never** be given system-administrator (`admin.*`) permissions. No group or role assignment can turn a customer into a system administrator.

---

## The Two Types of Tenants

There are two kinds of tenants: **customer** and **default**. Both are containers with the same isolation rules, but they serve different purposes.

| | Customer tenant | Default tenant |
| :--- | :--- | :--- |
| **Represents** | A company / customer | The phone system's shared space |
| **How many** | As many as you need | Exactly one |
| **Owner / primary contact** | Yes | None (intentionally empty) |
| **SIP domain** | Its own domain(s) | The server's default SIP realm |
| **Can be disabled or deleted** | Yes | No — it is permanent and always on |
| **Holds** | That company's PBX resources | Platform-wide, admin-owned resources |
| **Managed by** | Its member users (and admins) | System administrators |

### Customer tenants

These are your real, paying customers — "Acme Corporation," "Global Logistics Inc.," and so on.

- **One per organization**, created by an administrator when a new company signs up.
- **Has an owner.** Each one can have a *primary contact user* — the person you call when something breaks.
- **Owns its own world.** Its extensions, SIP accounts, dialplans, and phone domains belong to it alone.
- **Can be enabled, disabled, or deleted** as a normal administrative action.
- **Comes ready to use.** When created, the system automatically sets up that tenant's baseline PBX defaults (more on this below).

### The default tenant

This is **not** a customer. It is a single, built-in container for the shared, admin-owned resources that belong to the phone system itself rather than to any one company.

- **Exactly one exists.** Its name is always `Default` and its slug is always `default`.
- **It has no owner.** There is no primary contact, because no customer owns it.
- **It cannot be turned off or deleted,** and it automatically returns to its correct settings if edited into a strange state.
- **It holds the server's default SIP realm** (and other platform-wide resources), so the phone engine can resolve directory and voicemail lookups.

> **Using TallPBX without multi-tenancy:** if you do not need to separate multiple companies, you can simply run everything in the default tenant (or create a single customer tenant for yourself). Administrators manage the PBX there directly, and everyday callers are just extensions.

---

## Who Can Access What

Access depends on who you are and what you belong to.

- **Customer tenants** are opened only to the users explicitly granted membership in that tenant. System administrators can manage any tenant's resources, and can enter a customer's own view by impersonating one of its member users (see below).
- **The default tenant** is managed by system administrators. It holds platform-wide resources (like the default SIP realm) that belong to the system itself, so the people who run the system are the ones who manage it.
- **Cross-tenant access is blocked.** If a user tries to reach a record belonging to a different tenant, the request is refused outright ("Cross-tenant access denied"). The system fails closed — if it cannot confirm the record belongs to the active tenant, it denies access rather than allowing it.

---

## Admins and Impersonation

**Admins impersonate users, not tenants.** So when an admin impersonates someone, they see exactly what that user sees, including that user's tenant membership. This is how an administrator steps into the portal as a customer experiences it, which is the fastest way to troubleshoot a tenant-specific problem.

A few safeguards to be aware of:

- **It is permission-gated.** Only administrators with the impersonate permission can do it.
- **It is fully audited.** Every impersonation is recorded with who started it, who they impersonated, and when.
- **No chaining.** An admin cannot impersonate while already impersonating.
- **The admin's real identity is preserved** behind the scenes, so ending the impersonation always returns to the right administrator.

*(Impersonation is shown with screenshots in the [User Interface Tour](ui-tour.md). This page stays focused on the concept.)*

---

## What "Shared" Really Means

The default tenant is sometimes described as holding "shared" resources. It is worth being precise, because **"shared" here means shared by the phone system and managed by administrators — not shared between customers.** Tenant A can never read or use Tenant B's (or the default tenant's) resources.

Here is how resources are actually scoped:

| Resource | How it is scoped | Can other tenants use it? |
| :--- | :--- | :--- |
| Baseline defaults (music on hold, dialplans, feature codes, SIP profiles, media directories) | Each new tenant gets its **own copies** when it is created | No — every tenant has its own separate set |
| SIP gateways | Locked to one tenant | No |
| SIP extensions | Locked to one tenant | No |
| SIP users / accounts | Locked to one tenant | No |
| System groups, global settings, email connector | Not owned by any tenant (system-wide) | Yes — these apply to everyone by design |

Two details are worth calling out:

- **Defaults are copied, not shared.** When a customer tenant is created, it receives its own independent copies of the baseline PBX defaults. So the *capability* (hold music, dialplans, and so on) is available everywhere, but each tenant's *data* is separate and can be customized without affecting anyone else.
- **SIP gateways, extensions, and SIP users each belong to exactly one tenant.** They are never shared or auto-copied. Two tenants may happen to use the same extension number, but those are two separate records in two separate spaces — not a shared one.

The only place multiple tenants can genuinely overlap is a **shared SIP domain** (several tenants on one domain). Even there, each phone login is resolved to exactly one tenant, and if the system cannot tell them apart it refuses to guess rather than risk mixing tenants.

---

## Related Documentation

- [Telephony Fundamentals: Users, Extensions, SIP Accounts & Devices](pbx-concepts.md) — what each phone-side piece is and how they connect.
- [Tenant Isolation & Phone Provisioning](../README.md#tenant-isolation--phone-provisioning) — how to configure phones for a single company or for multiple tenants (domains vs. username prefixes).
- [PBX "Hello World" Quickstart](pbx-hello-world.md) — create an extension and place your first test call.
- [User Interface Tour](ui-tour.md) — visual walkthrough of the tenants and impersonation screens.
