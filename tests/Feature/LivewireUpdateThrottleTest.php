<?php

declare(strict_types=1);

use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/**
 * Rate limiting for the shared Livewire update endpoint.
 *
 * The update endpoint carries every panel Livewire interaction — including
 * sign-in and password-reset submissions — so it must refuse request floods
 * before they reach any component code. The limiter allows 60 requests per
 * minute per actor (or client address for guests).
 */
it('rate limits the Livewire update endpoint', function () {
    $endpoint = EndpointResolver::updatePath();

    // The payload is deliberately minimal: the rate limiter runs before any
    // component work, so even requests that would fail processing count.
    $payload = ['components' => [['snapshot' => 'invalid']]];

    $statuses = [];
    for ($i = 0; $i < 61; $i++) {
        $statuses[] = $this->postJson($endpoint, $payload, ['X-Livewire' => 'true'])->getStatusCode();
    }

    // The first 60 requests pass the limiter (their outcomes vary with the
    // deliberately invalid payload), and the 61st is refused.
    expect(array_slice($statuses, 0, 60))->not->toContain(429)
        ->and($statuses[60])->toBe(429);
});
