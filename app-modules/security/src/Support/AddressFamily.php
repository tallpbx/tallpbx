<?php

declare(strict_types=1);

namespace Modules\Security\Support;

/**
 * Shared address-family helpers for the security firewall pipeline.
 *
 * Classifies firewall address input (IPv4 and IPv6, with optional CIDR
 * notation) so the UI forms, the ban service, and the ruleset compiler all
 * agree on what a valid entry is and which kernel set it belongs to.
 */
final class AddressFamily
{
    /**
     * Classify a firewall address entry, optionally carrying a CIDR prefix.
     *
     * @param  string  $value  Address or CIDR range typed by an administrator
     * @return string 'ipv4', 'ipv6', or 'invalid'
     */
    public static function classify(string $value): string
    {
        $value = trim($value);

        if ($value === '' || substr_count($value, '/') > 1) {
            return 'invalid';
        }

        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::hasValidPrefix($prefix, 32) ? 'ipv4' : 'invalid';
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::hasValidPrefix($prefix, 128) ? 'ipv6' : 'invalid';
        }

        return 'invalid';
    }

    /**
     * Determine if a value is a plain IPv4 or IPv6 address (no CIDR prefix).
     */
    public static function isValidAddress(string $value): bool
    {
        return filter_var(trim($value), FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Determine if a value is a valid address or CIDR range of either family.
     */
    public static function isValidAddressOrCidr(string $value): bool
    {
        return self::classify($value) !== 'invalid';
    }

    /**
     * Validate an optional CIDR prefix against the family's maximum prefix length.
     *
     * @param  string|null  $prefix  CIDR prefix digits, or NULL when absent
     * @param  int  $maxPrefix  Family maximum (32 for IPv4, 128 for IPv6)
     */
    private static function hasValidPrefix(?string $prefix, int $maxPrefix): bool
    {
        if ($prefix === null) {
            return true;
        }

        return ctype_digit($prefix) && (int) $prefix <= $maxPrefix;
    }
}
