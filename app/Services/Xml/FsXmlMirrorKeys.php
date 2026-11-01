<?php

declare(strict_types=1);

namespace App\Services\Xml;

/**
 * Builds the raw Redis key strings used by the optional Lua XML mirror.
 *
 * These strings are an immutable contract shared byte-for-byte with
 * scripts/freeswitch/tallpbx_xml_handler.lua. The namespace is a constant
 * rather than an environment setting on purpose: a PHP/Lua drift would turn
 * every lookup into a silent cache miss, which is hard to notice in the logs.
 *
 * Every payload key embeds the tenant's current RoutingCacheVersion, so a
 * revision bump makes the next Lua lookup miss and fall through to PHP.
 *
 * The identity map key embeds the global routing revision instead. The map
 * answers "which tenant owns this username", so it cannot embed a tenant
 * revision; the global revision is the only epoch that changes when account
 * or domain ownership moves from one tenant to another.
 */
class FsXmlMirrorKeys
{
    /**
     * Fixed Redis key namespace for every mirrored XML document.
     */
    public const NAMESPACE = 'pbx:fsxml';

    /**
     * The per-tenant routing revision key read by the Lua handler.
     */
    public static function version(int $tenantId): string
    {
        return self::NAMESPACE.':ver:'.$tenantId;
    }

    /**
     * The system-wide routing revision key (dialplan-wide invalidations).
     */
    public static function globalVersion(): string
    {
        return self::NAMESPACE.':ver:global';
    }

    /**
     * The identity map that turns a request's domain and user into a tenant ID.
     *
     * The epoch is the system-wide routing revision at the moment PHP resolved
     * the identity. An ownership change bumps it, which moves the key and makes
     * every answer written before the change unreachable instead of merely
     * expiring after the TTL.
     */
    public static function tenantLookup(string $domain, string $user, int $epoch): string
    {
        return self::NAMESPACE.':tenant:user:'.$domain.':'.$user.':e'.$epoch;
    }

    /**
     * The mirrored directory document key for one tenant, lookup shape, and revision.
     *
     * The tag name is part of the key because FreeSWITCH sends two shapes:
     * `domain` for its native switch_xml_locate_user() lookup and `user` for
     * explicit user lookups. Both are mirrored under separate keys.
     */
    public static function directory(int $tenantId, string $tagName, string $domain, string $user, int $version): string
    {
        return self::NAMESPACE.':dir:'.$tenantId.':'.$tagName.':'.$domain.':'.$user.':v'.$version;
    }

    /**
     * Label holding one rendered dialplan document.
     *
     * Dialplan runs on every call leg, so it carries both revisions: the tenant
     * revision, which moves when an administrator saves anything that feeds the
     * routing plan, and the global epoch, which moves when a SIP account or
     * tenant domain changes and can alter how a destination resolves. A stale
     * dialplan does not merely show wrong data, it routes a live call the wrong
     * way, so Lua must treat any missing or unparsable revision as a miss.
     */
    public static function dialplan(int $tenantId, string $context, string $destination, int $version, int $epoch): string
    {
        return self::NAMESPACE.':dp:'.$tenantId.':'.$context.':'.$destination.':v'.$version.':e'.$epoch;
    }
}
