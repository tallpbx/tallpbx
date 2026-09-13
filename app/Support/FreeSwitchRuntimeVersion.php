<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\FreeSwitchServiceInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Reads and caches the FreeSWITCH runtime version for UI display.
 */
class FreeSwitchRuntimeVersion
{
    private const CACHE_KEY = 'freeswitch.runtime.version';

    /**
     * Create a runtime version reader.
     */
    public function __construct(
        private readonly FreeSwitchServiceInterface $freeSwitch,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Return a footer-ready label such as "FreeSWITCH 1.11.2".
     */
    public function label(): string
    {
        $version = $this->current();

        return $version === null ? 'FreeSWITCH' : "FreeSWITCH {$version}";
    }

    /**
     * Return the cached FreeSWITCH version, or null when ESL is unavailable.
     */
    public function current(): ?string
    {
        return $this->cache->remember(self::CACHE_KEY, now()->addMinutes(5), function (): ?string {
            try {
                if (! $this->freeSwitch->isConnected()) {
                    return null;
                }

                return $this->parse($this->freeSwitch->api('version'));
            } catch (Throwable) {
                return null;
            }
        });
    }

    /**
     * Extract the semantic FreeSWITCH version from common API outputs.
     */
    public function parse(string $response): ?string
    {
        if (preg_match('/FreeSWITCH(?:\s+\(Version|\s+Version)\s+(\d+(?:\.\d+)+)/i', $response, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
