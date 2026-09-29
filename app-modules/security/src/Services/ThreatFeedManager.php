<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Modules\Security\Contracts\ThreatFeedProviderInterface;
use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Support\ThreatFeedSyncResult;

/**
 * Dispatches threat feed sync requests to the registered provider drivers.
 *
 * Every provider is keyed by its stable identifier, so adding APIBAN or
 * Spamhaus later is one `register()` call and one driver class — the sync
 * command, the panel, and the kernel compiler never change.
 */
class ThreatFeedManager
{
    /**
     * Registered provider drivers keyed by their identifier.
     *
     * @var array<string, ThreatFeedProviderInterface>
     */
    private array $providers = [];

    /**
     * Create the manager with the first-party VoIPBL driver pre-registered.
     */
    public function __construct(VoipblFeedProvider $voipbl)
    {
        $this->register($voipbl);
    }

    /**
     * Register (or replace) a provider driver under its identifier.
     */
    public function register(ThreatFeedProviderInterface $provider): void
    {
        $this->providers[$provider->identifier()] = $provider;
    }

    /**
     * Resolve the driver registered for a feed's provider column.
     */
    public function providerFor(string $identifier): ?ThreatFeedProviderInterface
    {
        return $this->providers[$identifier] ?? null;
    }

    /**
     * Every registered driver.
     *
     * @return array<int, ThreatFeedProviderInterface>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * Sync one feed through its registered driver.
     *
     * An unknown provider is reported as a failed result with the identifier
     * named, so a stale configuration row never crashes a scheduled run.
     */
    public function sync(SecurityThreatFeed $feed, bool $force = false): ThreatFeedSyncResult
    {
        $provider = $this->providerFor($feed->provider);

        if ($provider === null) {
            return ThreatFeedSyncResult::failed(
                "No threat feed provider is registered for '{$feed->provider}'."
            );
        }

        return $provider->sync($feed, $force);
    }
}
