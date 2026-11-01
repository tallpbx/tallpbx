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
     */
    public static function tenantLookup(string $domain, string $user): string
    {
        return self::NAMESPACE.':tenant:user:'.$domain.':'.$user;
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
}
