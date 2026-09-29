<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Security\Models\SecurityThreatFeed;

/**
 * Feature tests for the security_threat_feeds table and its model.
 *
 * The table stores per-driver feed configuration and sync metadata only —
 * the feed CIDRs themselves live in the nftables kernel interval sets, never
 * in individual database rows.
 */
it('creates the security_threat_feeds table with every documented column', function (): void {
    expect(Schema::hasTable('security_threat_feeds'))->toBeTrue();

    foreach ([
        'id', 'provider', 'name', 'enabled', 'country_mode', 'countries',
        'sync_interval', 'last_sync_at', 'last_status', 'last_error',
        'entries_count', 'etag', 'last_modified_header', 'last_rejected_lines',
        'created_at', 'updated_at',
    ] as $column) {
        expect(Schema::hasColumn('security_threat_feeds', $column))->toBeTrue();
    }
});

it('enforces one feed configuration per provider driver', function (): void {
    SecurityThreatFeed::factory()->create(['provider' => 'voipbl']);

    // The unique constraint documents the release contract: one row per
    // driver; relaxing it later to (provider, slug) needs no driver change.
    expect(fn () => SecurityThreatFeed::factory()->create(['provider' => 'voipbl']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('casts enabled, countries, timestamps, and counters to native types', function (): void {
    $feed = SecurityThreatFeed::factory()->create([
        'enabled' => true,
        'countries' => ['US', 'CA'],
        'last_sync_at' => now(),
        'entries_count' => 12345,
        'last_rejected_lines' => 7,
        'last_status' => 'success',
    ]);

    $fresh = $feed->fresh();

    expect($fresh->enabled)->toBeTrue()
        ->and($fresh->countries)->toBe(['US', 'CA'])
        ->and($fresh->last_sync_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->entries_count)->toBe(12345)
        ->and($fresh->last_rejected_lines)->toBe(7)
        ->and($fresh->last_status)->toBe('success');
});

it('maps each sync interval to its seconds value', function (): void {
    expect(SecurityThreatFeed::factory()->make(['sync_interval' => 'hourly'])->syncIntervalSeconds())->toBe(3600)
        ->and(SecurityThreatFeed::factory()->make(['sync_interval' => '4_hours'])->syncIntervalSeconds())->toBe(14400)
        ->and(SecurityThreatFeed::factory()->make(['sync_interval' => '12_hours'])->syncIntervalSeconds())->toBe(43200)
        ->and(SecurityThreatFeed::factory()->make(['sync_interval' => 'daily'])->syncIntervalSeconds())->toBe(86400);
});

it('flags a feed as stale only after two missed sync intervals', function (): void {
    $feed = SecurityThreatFeed::factory()->create([
        'sync_interval' => 'hourly',
        'last_sync_at' => now()->subMinutes(121),
    ]);

    // Just past two intervals (120 minutes): the feed is silently rotting.
    expect($feed->isStale())->toBeTrue();

    $feed->update(['last_sync_at' => now()->subMinutes(59)]);
    expect($feed->fresh()->isStale())->toBeFalse();

    // A feed that has never synced yet is idle, not rotting.
    $feed->update(['last_sync_at' => null]);
    expect($feed->fresh()->isStale())->toBeFalse();
});

it('defaults a factory feed to disabled, all-countries, and empty metadata', function (): void {
    $feed = SecurityThreatFeed::factory()->create();

    expect($feed->enabled)->toBeFalse()
        ->and($feed->country_mode)->toBe('all')
        ->and($feed->countries)->toBe([])
        ->and($feed->entries_count)->toBe(0)
        ->and($feed->last_rejected_lines)->toBe(0)
        ->and($feed->last_sync_at)->toBeNull()
        ->and($feed->last_status)->toBeNull();
});
