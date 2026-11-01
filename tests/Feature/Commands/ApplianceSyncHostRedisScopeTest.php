<?php

declare(strict_types=1);

/*
 * Guards the blast radius of `appliance:sync-host`.
 *
 * The command runs after a snapshot restore or an IP change and clears cached
 * telephony data. It used to call Redis::flushall(), which empties every
 * logical database: cache (DB 1), sessions (DB 2) and the CDR stream (DB 3).
 * Only the cache holds data that embeds the old address, and only the cache is
 * safe to discard, so the flush must stay scoped to that store.
 *
 * This is a source assertion rather than a behavioural one: exercising the
 * command end to end would require a live Redis server, a database, and
 * Artisan recursion through optimize:clear. The regression it prevents is
 * literal - someone restoring the broad flush.
 */

it('flushes only the cache store, never every redis database', function (): void {
    $source = (string) file_get_contents(base_path('app/Console/Commands/ApplianceSyncHostCommand.php'));

    // Assert on the call, not the word: the command's own comments explain why
    // a broad flush is wrong and therefore mention it by name.
    expect($source)->not->toContain('Redis::flushall')
        ->and($source)->toContain("Cache::store('redis')->flush()");
});
