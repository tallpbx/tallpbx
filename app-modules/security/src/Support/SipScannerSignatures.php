<?php

declare(strict_types=1);

namespace Modules\Security\Support;

use Modules\Security\Models\SecuritySetting;

/**
 * The curated SIP scanner signature registry with two confidence tiers.
 *
 * A false positive here costs a real phone system its service for the whole
 * ban duration, so the base list is deliberately split by how unmistakable
 * each signature is: high-confidence signatures auto-ban on match, while
 * low-confidence signatures are only recorded (and escalate when several
 * distinct ones appear in a short window).
 *
 * The curated base is read-only and versioned in code (`defaults()`), so an
 * application update can ship new defaults without ever touching
 * administrator data. Administrator-added signatures live in the
 * `sip_scanner_signatures` setting, are treated as literal substrings (never
 * regular expressions), and are additive-only: they append to the base and
 * are high-confidence by definition — adding one is an explicit human
 * decision.
 */
final class SipScannerSignatures
{
    /**
     * Version of the curated base list. Bump when the base changes so the
     * panel and the dialplan cache can tell revisions apart.
     */
    public const VERSION = 1;

    /**
     * Setting key holding the administrator's custom signature list (JSON array of strings).
     */
    public const CUSTOM_SETTINGS_KEY = 'sip_scanner_signatures';

    /**
     * Setting key holding the ban duration in seconds (0 = permanent).
     */
    public const BAN_SECONDS_KEY = 'sip_scanner_ban_seconds';

    /**
     * Setting key holding the low-confidence escalation window in seconds.
     */
    public const WINDOW_SECONDS_KEY = 'sip_scanner_window_seconds';

    /**
     * Setting key holding the automatic-ban enforcement toggle (ships off).
     */
    public const ENFORCEMENT_KEY = 'sip_scanner_enforcement_enabled';

    /**
     * Recommended ban duration (24 hours) when nothing is configured.
     */
    public const DEFAULT_BAN_SECONDS = 86400;

    /**
     * Shortest configurable ban duration (1 hour).
     */
    public const MIN_BAN_SECONDS = 3600;

    /**
     * Longest finite configurable ban duration (7 days).
     */
    public const MAX_BAN_SECONDS = 604800;

    /**
     * Recommended low-confidence escalation window (5 minutes).
     */
    public const DEFAULT_WINDOW_SECONDS = 300;

    /**
     * Maximum length of a custom signature entry.
     */
    public const MAX_CUSTOM_LENGTH = 64;

    /**
     * The versioned curated signature list, split into confidence tiers.
     *
     * Each entry carries the SIP header field it is matched against
     * ('From' or 'User-Agent') and the literal substring pattern.
     *
     * @return array{version: int, high: array<int, array{field: string, pattern: string}>, low: array<int, array{field: string, pattern: string}>}
     */
    public static function defaults(): array
    {
        return [
            'version' => self::VERSION,
            'high' => [
                // Named penetration-testing tool.
                ['field' => 'From', 'pattern' => 'sipvicious'],
                // Named scanners and CLI fuzzers. 'sipcli' deliberately stays
                // unqualified so it also covers sipcli/v1.8.
                ['field' => 'User-Agent', 'pattern' => 'friendly-scanner'],
                ['field' => 'User-Agent', 'pattern' => 'VaxSIPUserAgent'],
                ['field' => 'User-Agent', 'pattern' => 'sipcli'],
                // Named commercial IVR/bot toolkit.
                ['field' => 'User-Agent', 'pattern' => 'Ozeki'],
            ],
            'low' => [
                // A generic phrase legitimate softphones and gateway stacks
                // emit: recorded and shown, never banned on this signal alone.
                ['field' => 'User-Agent', 'pattern' => 'SIP Call'],
            ],
        ];
    }

    /**
     * All curated base patterns, lowercased for case-insensitive comparisons.
     *
     * @return array<int, string>
     */
    public static function basePatternsLowercased(): array
    {
        $defaults = self::defaults();

        return array_map(
            static fn (array $entry): string => mb_strtolower($entry['pattern']),
            array_merge($defaults['high'], $defaults['low']),
        );
    }

    /**
     * Validate and normalize one custom signature entry.
     *
     * Returns the trimmed entry, or null when it is not a string, is empty,
     * exceeds the length limit, or contains anything outside printable ASCII
     * (newlines, control bytes, or Unicode would corrupt the generated
     * dialplan condition).
     */
    public static function validateCustomEntry(mixed $entry): ?string
    {
        if (! is_string($entry)) {
            return null;
        }

        $trimmed = trim($entry);

        if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_CUSTOM_LENGTH) {
            return null;
        }

        // Printable ASCII only: space (0x20) through tilde (0x7E).
        if (preg_match('/^[\x20-\x7E]+$/', $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Sanitize a whole custom signature list.
     *
     * Drops invalid entries, collapses case-insensitive duplicates within the
     * list, and refuses entries that duplicate the curated base (either
     * tier). Order of the surviving entries is preserved.
     *
     * @param  array<int|string, mixed>  $entries
     * @return array<int, string>
     */
    public static function sanitizeCustomList(array $entries): array
    {
        $seen = self::basePatternsLowercased();
        $sanitized = [];

        foreach ($entries as $entry) {
            $valid = self::validateCustomEntry($entry);

            if ($valid === null) {
                continue;
            }

            $lower = mb_strtolower($valid);

            if (in_array($lower, $seen, true)) {
                continue;
            }

            $seen[] = $lower;
            $sanitized[] = $valid;
        }

        return $sanitized;
    }

    /**
     * Read the administrator's custom signature list from the settings.
     *
     * A missing or corrupted stored value degrades to "no custom
     * signatures" instead of breaking detection entirely.
     *
     * @return array<int, string>
     */
    public static function custom(): array
    {
        $decoded = json_decode((string) SecuritySetting::get(self::CUSTOM_SETTINGS_KEY, '[]'), true);

        if (! is_array($decoded)) {
            return [];
        }

        return self::sanitizeCustomList($decoded);
    }

    /**
     * Persist the sanitized custom signature list and return what was stored.
     *
     * @param  array<int|string, mixed>  $entries
     * @return array<int, string>
     */
    public static function saveCustom(array $entries): array
    {
        $sanitized = self::sanitizeCustomList($entries);

        SecuritySetting::set(self::CUSTOM_SETTINGS_KEY, json_encode($sanitized));

        return $sanitized;
    }

    /**
     * Build a preg-quoted alternation of the given patterns.
     *
     * Every entry is escaped with preg_quote, so an administrator typing `.`
     * or `|` changes nothing about the matching semantics: custom entries are
     * literal substrings by contract. The dialplan renderer splices the
     * result into its SIP header conditions.
     *
     * @param  array<int, string>  $patterns
     */
    public static function escapedRegex(array $patterns): string
    {
        return implode('|', array_map(
            static fn (string $pattern): string => preg_quote($pattern, '/'),
            $patterns,
        ));
    }

    /**
     * The escaped alternation of the custom signature list ('' when empty).
     */
    public static function customRegex(): string
    {
        return self::escapedRegex(self::custom());
    }

    /**
     * The effective ban duration in seconds (0 means permanent).
     *
     * Accepts only the documented shapes — 0 for permanent, or a finite
     * duration between one hour and seven days; anything else falls back to
     * the recommended 24 hours rather than producing a wild ban time.
     */
    public static function banSeconds(): int
    {
        $seconds = SecuritySetting::getInt(self::BAN_SECONDS_KEY, self::DEFAULT_BAN_SECONDS);

        if ($seconds === 0) {
            return 0;
        }

        if ($seconds < self::MIN_BAN_SECONDS || $seconds > self::MAX_BAN_SECONDS) {
            return self::DEFAULT_BAN_SECONDS;
        }

        return $seconds;
    }

    /**
     * The low-confidence escalation window in seconds.
     *
     * Two or more distinct low-confidence signatures from the same address
     * inside this window escalate to a ban. Nonsensical values fall back to
     * the recommended five minutes.
     */
    public static function windowSeconds(): int
    {
        $seconds = SecuritySetting::getInt(self::WINDOW_SECONDS_KEY, self::DEFAULT_WINDOW_SECONDS);

        return $seconds > 0 ? $seconds : self::DEFAULT_WINDOW_SECONDS;
    }

    /**
     * The automatic-ban enforcement toggle.
     *
     * Ships off: detection and recording are always on, only the automatic
     * kernel ban is opt-in (the record-only rollout). This is per-feature and
     * distinct from the global firewall observe mode.
     */
    public static function enforcementEnabled(): bool
    {
        return SecuritySetting::getBoolean(self::ENFORCEMENT_KEY, false);
    }
}
