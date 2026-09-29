<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Modules\Security\Models\SecuritySetting;
use Modules\Security\Support\SipScannerSignatures;

/**
 * Unit tests for the SIP scanner signature registry.
 *
 * Covers the versioned curated defaults (two confidence tiers), custom
 * signature validation and sanitization, literal-substring escaping for the
 * dialplan renderer, and the scanner settings helpers.
 */
it('exposes versioned curated defaults split into confidence tiers', function (): void {
    $defaults = SipScannerSignatures::defaults();

    expect($defaults)->toHaveKey('version')
        ->and($defaults['version'])->toBe(SipScannerSignatures::VERSION)
        ->and($defaults)->toHaveKey('high')
        ->and($defaults)->toHaveKey('low');

    // High-confidence signatures auto-ban on match.
    expect(array_column($defaults['high'], 'pattern'))->toBe([
        'sipvicious',
        'friendly-scanner',
        'VaxSIPUserAgent',
        'sipcli',
        'Ozeki',
    ]);

    // 'sipcli' must stay a single unqualified entry so it also covers
    // sipcli/v1.8 — no separate versioned entry may be shipped.
    $sipcliEntries = array_filter($defaults['high'], fn (array $entry): bool => str_contains($entry['pattern'], 'sipcli'));
    expect($sipcliEntries)->toHaveCount(1);

    // Low-confidence signatures are recorded but never ban on their own.
    expect(array_column($defaults['low'], 'pattern'))->toBe(['SIP Call'])
        ->and($defaults['low'][0]['field'])->toBe('User-Agent');
});

it('validates custom signature entries as trimmed printable ASCII within 64 characters', function (): void {
    // Valid: trimmed, case preserved, spaces allowed inside.
    expect(SipScannerSignatures::validateCustomEntry('  My Scanner v2  '))->toBe('My Scanner v2')
        ->and(SipScannerSignatures::validateCustomEntry('Zoiper'))->toBe('Zoiper')
        ->and(SipScannerSignatures::validateCustomEntry(str_repeat('a', 64)))->toBe(str_repeat('a', 64));

    // Invalid: empty or whitespace-only.
    expect(SipScannerSignatures::validateCustomEntry(''))->toBeNull()
        ->and(SipScannerSignatures::validateCustomEntry('   '))->toBeNull();

    // Invalid: longer than 64 characters.
    expect(SipScannerSignatures::validateCustomEntry(str_repeat('a', 65)))->toBeNull();

    // Invalid: anything outside printable ASCII (newlines, control bytes, Unicode).
    expect(SipScannerSignatures::validateCustomEntry("bad\nagent"))->toBeNull()
        ->and(SipScannerSignatures::validateCustomEntry("bad\x7fagent"))->toBeNull()
        ->and(SipScannerSignatures::validateCustomEntry('📞'))->toBeNull();

    // Invalid: non-strings are refused outright.
    expect(SipScannerSignatures::validateCustomEntry(42))->toBeNull()
        ->and(SipScannerSignatures::validateCustomEntry(null))->toBeNull()
        ->and(SipScannerSignatures::validateCustomEntry(['array']))->toBeNull();
});

it('sanitizes custom lists with case-insensitive dedupe against the curated base', function (): void {
    // Duplicates within the list collapse to the first spelling.
    expect(SipScannerSignatures::sanitizeCustomList(['Zoiper', 'zoiper', 'ZOIPER ']))->toBe(['Zoiper']);

    // Entries duplicating the curated base (either tier) or invalid entries are dropped.
    expect(SipScannerSignatures::sanitizeCustomList(['sipvicious', 'FRIENDLY-SCANNER', 'sip call', 'Fresh']))->toBe(['Fresh']);

    // An internal control character always survives trimming and is refused;
    // leading/trailing whitespace is trimmed away as documented above.
    expect(SipScannerSignatures::sanitizeCustomList(['ok', '', "bad\nagent", str_repeat('x', 65), 42]))->toBe(['ok']);

    // Order of the surviving entries is preserved.
    expect(SipScannerSignatures::sanitizeCustomList(['Bravo', 'alpha', 'Charlie']))->toBe(['Bravo', 'alpha', 'Charlie']);
});

it('round-trips the custom signatures through the settings key', function (): void {
    expect(SipScannerSignatures::custom())->toBe([]);

    $stored = SipScannerSignatures::saveCustom(['Zoiper', 'zoiper', 'sipvicious', 'Grandstream*']);

    // The sanitized list is what gets persisted — duplicates and base
    // collisions can never reach the database.
    expect($stored)->toBe(['Zoiper', 'Grandstream*'])
        ->and(SipScannerSignatures::custom())->toBe(['Zoiper', 'Grandstream*'])
        ->and(SecuritySetting::get('sip_scanner_signatures'))->toBe(json_encode(['Zoiper', 'Grandstream*']));

    // A corrupted stored value degrades to "no custom signatures".
    SecuritySetting::set('sip_scanner_signatures', '{not json');
    expect(SipScannerSignatures::custom())->toBe([]);
});

it('escapes custom entries as literal substrings so regex metacharacters stay inert', function (): void {
    expect(SipScannerSignatures::escapedRegex(['a.b|c', 'x']))->toBe('a\.b\|c|x')
        ->and(SipScannerSignatures::escapedRegex([]))->toBe('');

    // The escaped alternation matches the literal text and nothing looser:
    // the dot is not a wildcard and the pipe is not an alternation.
    $regex = SipScannerSignatures::escapedRegex(['Ozeki.', 'sip|cli']);

    expect(preg_match('/'.$regex.'/i', 'ozeki.X'))->toBe(1)
        ->and(preg_match('/'.$regex.'/i', 'ozekiX'))->toBe(0)
        ->and(preg_match('/'.$regex.'/i', 'sip|cli'))->toBe(1)
        ->and(preg_match('/'.$regex.'/i', 'sipcli'))->toBe(0);
});

it('resolves the ban duration with permanent zero and bounded windows', function (): void {
    // Missing setting: the recommended default of 24 hours.
    expect(SipScannerSignatures::banSeconds())->toBe(86400);

    // Zero is the documented permanent option.
    SecuritySetting::set('sip_scanner_ban_seconds', 0);
    expect(SipScannerSignatures::banSeconds())->toBe(0);

    // The documented bounds pass through untouched.
    SecuritySetting::set('sip_scanner_ban_seconds', 3600);
    expect(SipScannerSignatures::banSeconds())->toBe(3600);

    SecuritySetting::set('sip_scanner_ban_seconds', 604800);
    expect(SipScannerSignatures::banSeconds())->toBe(604800);

    // Anything else falls back to the default instead of a wild ban time.
    foreach ([100, -5, 99999999] as $nonsense) {
        SecuritySetting::set('sip_scanner_ban_seconds', $nonsense);
        expect(SipScannerSignatures::banSeconds())->toBe(86400);
    }
});

it('resolves the low-confidence escalation window and the enforcement toggle', function (): void {
    // Missing settings: five-minute window, enforcement off (record-only rollout).
    expect(SipScannerSignatures::windowSeconds())->toBe(300)
        ->and(SipScannerSignatures::enforcementEnabled())->toBeFalse();

    SecuritySetting::set('sip_scanner_window_seconds', 600);
    expect(SipScannerSignatures::windowSeconds())->toBe(600);

    foreach ([0, -1] as $nonsense) {
        SecuritySetting::set('sip_scanner_window_seconds', $nonsense);
        expect(SipScannerSignatures::windowSeconds())->toBe(300);
    }

    SecuritySetting::set('sip_scanner_enforcement_enabled', true);
    expect(SipScannerSignatures::enforcementEnabled())->toBeTrue();
});
