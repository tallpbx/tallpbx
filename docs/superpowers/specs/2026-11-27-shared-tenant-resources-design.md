# Shared Tenant Resources

**Date** 2026-11-27

**Status** Draft for review

## Purpose

A platform administrator can configure a carrier trunk or upload an announcement once, then make it available to one tenant, selected tenants, or all tenants. Each tenant continues to choose how to use the resource in its own routes and menus. The first two resource types are SIP trunks and reusable prompt recordings.

TallPBX already uses the **Default** tenant as an administrative home. Gateway forms preselect it for administrator-created resources, while the outbound route picker and runtime require the gateway and route to have the same tenant. The existing gateway UUID is the FreeSWITCH registration identity. These are useful starting points for central administration and stable references. [Default tenant service](../../../app/Services/TenantService.php#L43) · [Gateway picker](../../../app-modules/outbound-routes/src/Livewire/OutboundRoutesEdit.php#L175) · [Gateway rendering](../../../app-modules/gateways/src/Services/GatewayService.php#L134)

FusionPBX offers global and domain-specific gateways and music on hold. Its administrator gateway permission also allows a route in one domain to reference a gateway from another. The standard IVR recording picker draws uploaded recordings from the current domain. These examples support sharing resources and configuring their consumers as distinct operations. [FusionPBX gateways](https://docs.fusionpbx.com/en/latest/accounts/gateways.html) · [Outbound route gateway selection](https://github.com/fusionpbx/fusionpbx/blob/master/app/dialplan_outbound/dialplan_outbound_add.php#L924-L935) · [Recording picker](https://github.com/fusionpbx/fusionpbx/blob/master/resources/classes/sounds.php#L97-L110) · [Music on hold](https://docs.fusionpbx.com/en/latest/applications/music_on_hold.html)

## Ownership and availability

Keep one owner per resource. New resources start as **Owner only**, including resources created under Default. Admin-created resources intended for reuse can live in Default. A platform administrator can also share an existing tenant-owned resource without moving it or changing its UUID. Existing private resources keep their owner and access at upgrade time.

| Availability | Tenant use |
| --- | --- |
| **Owner only** | The owning tenant can use the resource. |
| **Selected tenants** | The owner and the enabled tenants explicitly selected by a platform administrator can use it. At least one additional tenant is required. |
| **All tenants** | All enabled tenants can use it, including tenants created later. |

The owner and consumer must both be enabled, and the resource must be available. Disabling the resource or a tenant suspends access. Re-enabling it restores access under the current availability and grants. An explicit revocation takes a separate grant change to restore. Determine the consuming tenant from the authorized panel context or the tenant-resolved call path. Tenant membership and the existing permission for the consumer action still apply. An enabled tenant that can use a resource receives the minimum information needed to select and use it.

A platform administrator with the module's sharing permission controls publication, grants, and revocation. Platform administrators with the appropriate module permission manage shared resources. The tenant's existing management permissions apply to its owner-only resources. Platform administrators assign shared trunks to tenant outbound routes in the first release. Authorized tenant IVR editors can assign a shared prompt in their own menus. Shared resources expose only the details needed for the permitted use.

### Options considered

| Approach | Trade-off |
| --- | --- |
| Default as an implicit provider for every tenant | Simple lookup, with sharing determined by placement. Selective grants and a clear record of publication would require additional policy. |
| Separate copy per recipient | Each tenant controls its copy. Copies may drift, and a copied trunk can create its own carrier registration. Replacing a greeting requires updating every copy. |
| One owner with explicit availability | Stable references and one managed instance. Configuration, permissions, and runtime all need the same access decision. This is the proposed model. |

## Administrator workflow

The gateway and recording editors show the owner and an **Available to** control. **Selected tenants** opens a tenant picker. **All tenants** states that future tenants will also gain access. Changes show who will gain or lose access, and removals show affected routes or menus before they take effect.

A tenant choosing a resource sees its eligible local and shared choices, with owner names where needed for disambiguation. The choice uses the resource UUID, so renaming a resource preserves existing references. A saved reference that has become unavailable remains visible to an authorized editor with a status and a replacement action.

For example, an administrator can keep `Carrier trunk` and `Holiday greeting` in Default. Sales and Support can each point an outbound route at the trunk. Only Support can select the greeting when it is shared solely with Support. Another tenant with the same SIP realm stays outside both grants until explicitly added.

## Application design

Give each supported resource type an explicit availability value and a tenant grant relationship with a unique resource-and-recipient pair. Use foreign keys to retain ownership and clean up recipient grants. Keep the owner, grants, and references on their existing module boundaries. Reuse a common ownership-and-access rule across modules, with resource-specific checks for operations such as playing a file or selecting a carrier. Keep the existing tenant scope for ordinary management queries. Sharing is resolved only at the operations that support it.

Check access on the picker, on saving a reference, and when the reference is used. Saving a foreign UUID or reusing a stale browser page must have the same outcome as choosing an unavailable resource in the UI. Runtime authorization checks the consuming tenant again, including when several tenants share a SIP domain or a FreeSWITCH profile. Use stable UUID references in each consumer.

Save availability and grants atomically, using consistent locking with operations that attach new consumers or remove access. Record the actor, resource, previous and new availability, and affected tenants in the administrative audit trail. Keep passwords, private file paths, and audio out of audit records available to consumers.

## SIP trunks

One gateway UUID has one configured FreeSWITCH Sofia profile and one gateway lifecycle. Where carrier registration is required, keep one live registration per switch instance for the shared gateway. Access changes affect the tenants' route eligibility. The owning profile, credentials, and registration lifecycle are managed as gateway configuration. Verify that calls originating on each supported profile reach that gateway without re-emitting it under the consumer's profile. [TallPBX Sofia gateway selection](../../../app-modules/sip-profiles/src/Services/SipProfileService.php#L114)

A platform administrator with outbound route permissions explicitly assigns the gateway in each consumer tenant's outbound route. The first release keeps the tenant route list read-only and the assignment operation platform-admin only. The call uses the consuming tenant's dialing rules, outbound caller ID, call permissions, and call detail records. The carrier account and its capacity are shared by the participating tenants. Check how the provider handles caller ID and concurrency before relying on a shared trunk for production traffic. Tenant views can show the gateway label and operational availability. Credentials and carrier configuration remain on the management side. [Current tenant route list](../../../app-modules/outbound-routes/src/Livewire/OutboundRoutesList.php#L18)

Grant-based trunk use goes through typed outbound route assignments. Before releasing it, audit tenant-editable paths that can originate an outbound call, including raw dialplan actions, IVR destinations, and forwarding. Route external numbers from those paths through the tenant's authorized outbound rules. Reject tenant-editable direct references to a shared gateway if they bypass the managed route check. Review existing platform-authored custom references as part of upgrade and dependency review.

A route with a disabled, deleted, or revoked gateway must stop producing a bridge to that gateway. Its saved reference and unavailable state should remain visible until an administrator repairs the route. Keep ordinary routing priority for other independently authorized routes. Preserve the difference between an explicitly assigned gateway that became unavailable and an intentionally gateway-less route. Review the existing legacy string and direct SIP fallbacks so a failed typed assignment cannot take either path by accident. The current gateway foreign key clears on delete, so deletion must preserve that distinction through dependency handling or explicit route state. [Outbound resolver](../../../app-modules/outbound-routes/src/Services/OutboundRouteService.php#L155) · [Outbound route schema](../../../app-modules/outbound-routes/database/migrations/2026_07_01_000001_create_outbound_routes_table.php#L22)

The first trunk release shares **outbound use**. Inbound phone-number ownership, ingress context, and emergency routing are separately managed. A trunk grant leaves those decisions with their existing policies. A future inbound-sharing design would need a trusted carrier and called-number mapping to a single tenant, along with separate emergency caller ID and location verification.

## Reusable recordings

Share uploaded entries in **Recordings**. Each consumer references the recording UUID. The audio stays under the original owner's managed file storage, and the IVR resolves an available audio file at call time. Private uploads owned by individual menus, call recordings, voicemail messages, and other private media keep their own lifecycle and access rules. Built-in FreeSWITCH prompts remain system-provided sounds.

A tenant IVR editor can see the labels of eligible recordings in the menu picker and select them using its menu permissions and the recording grant. The recordings library and browser preview additionally require the existing recording view permission, checked in the tenant's active context. An authorized preview streams the audio bytes. Keep the attachment download endpoint under owner and administrator permissions, alongside editing, replacement, and deletion. Authorize preview and attachment requests separately and scope the sharing change to reusable recording assets. [Current media authorization](../../../app-modules/file-stores/src/Services/MediaAssetAuthorizationService.php#L35) · [Media endpoints](../../../app-modules/file-stores/src/Http/Controllers/MediaStreamController.php#L21)

Replacing audio changes the recording for every authorized menu using that UUID. Show that impact before saving and publish the new file only once it is playable. Failed replacement leaves the current audio available. The [IVR parity design](2026-11-27-ivr-parity-design.md#prompt-library-and-storage) delivers recording references and prompt fallback first. Shared recording resolution then extends which recording UUIDs the menu is allowed to use.

## Revocation and failure handling

For a reduction in availability, show affected routes, prompt slots, and playback options. A sharing administrator can immediately revoke one selected tenant after confirming the impact. For an all-tenant resource, changing to a selected list revokes the omitted tenants. Unaffected routes and menus stay eligible. Keep affected references visible for repair by a platform route administrator or an authorized IVR editor. Provide an administrator-only disable action to stop new uses by all tenants. Require dependency resolution before deleting a shared resource or its owning tenant so a database cascade cannot silently remove resources in use.

A saved reference can become unavailable independently of the planned workflow. An IVR omits an unavailable optional prompt or playback option. If its entry greeting is unavailable, the menu follows its configured exit destination or hangs up cleanly. An outbound route with an unavailable assigned trunk cannot use that trunk. If availability cannot be verified, handle the reference as unavailable.

TallPBX caches generated dialplan XML. Sharing changes, owner or resource disablement, and recording replacement must be reflected in the affected consumer tenants' cache keys. Use tenant routing revisions for affected recipients and the global revision or an equivalent authoritative check for changes affecting all tenants. Confirm the runtime sees the change when the administrator receives success. If invalidation can lag or fail, use an authoritative check or bypass stale cached content on the affected paths. Test the full handler and the fast PHP-FPM XML handler, including the optional [Laravel Octane](https://laravel.com/docs/octane) worker. These handlers answer FreeSWITCH's XML lookups for accounts and call routing. [Routing revisions](../../../app/Services/Xml/DialplanXmlBuilder.php#L449) · [XML Handler operations](../../operations.md#octane-xml-worker-optional-performance-mode)

The change applies to new routing and media decisions. A call already bridged, a prompt already playing, or an action already accepted by the switch may finish. Live verification must distinguish those cases from a fresh request served by a stale cache. A grant or revocation needs no gateway re-registration when the gateway configuration itself stays active.

## Delivery and evidence

1. **Confirm the trunk path in a disposable lab.** Use a Default-owned trunk pointed at a synthetic carrier. Verify that two granted tenants can place calls with their own caller identity and call attribution, a third tenant cannot use the trunk, and one tenant can be revoked with the routing caches warm. Confirm a single live registration for the gateway and the expected switch behavior across tenant profiles. Record the FreeSWITCH build, call verdicts, and sanitized evidence before writing the trunk implementation plan.
2. **Deliver shared prompt recordings once IVR recording references are available.** Test reuse by two authorized menus, denial in a different tenant on the same SIP realm, preview permissions, replacement failure, dependency reporting, and revocation visible on the next call. Verify the actual audio and the fallback behavior on live calls.
3. **Deliver outbound trunk sharing as its own change.** Use the lab result to plan the runtime profile binding and cache boundary. Test that grant changes affect the picker, saved-reference validation, and fresh calls consistently, including when a route contains a historical free-form gateway value. Verify that a route can survive a trunk being disabled without using an unintended path.

The trunk lab can run alongside the recordings work. Test **All tenants** with a tenant created after publication. Test **Selected tenants** with an unlisted tenant and with a tenant whose access is removed. Include existing owner-only records in upgrade coverage and confirm disabled tenants cannot gain live access. Test that an unprivileged actor cannot change a grant, attach a shared trunk, bypass the typed route check, or fetch a revoked recording. Test that a reference submitted concurrently with revocation cannot be saved as available. Each release needs observable consumer behavior as well as authorization and database tests.

## Later candidates

Music on hold is the closest follow-on for shared media. Provisioning templates already have a tenant-specific and global lookup. Review those resource types individually against this ownership and access model. Publish shared dialplans, destinations, or inbound number assignments only after defining their calling-context, routing, and conflict semantics. [TallPBX provisioning template lookup](../../../app-modules/provision/src/Services/ProvisionService.php#L85)
