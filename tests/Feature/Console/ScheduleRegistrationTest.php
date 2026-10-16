<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;

// Pins the scheduler registrations the operational docs and changelog rely
// on: Geo-IP country zones must refresh automatically alongside threat
// feeds, never only on manual CLI runs.

it('schedules the geo-ip zone sync weekly', function (): void {
    $geoEvents = array_values(array_filter(
        app(Schedule::class)->events(),
        fn ($event): bool => str_contains((string) ($event->command ?? ''), 'security:sync-geoip')
    ));

    expect($geoEvents)->not->toBeEmpty();

    // weekly() compiles to Sunday 00:00; the command itself skips disabled
    // or country-less geo rules, so scheduling is safe for every install.
    expect($geoEvents[0]->expression)->toBe('0 0 * * 0');
});
