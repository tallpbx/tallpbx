<?php

declare(strict_types=1);

namespace App\Services\Cdr;

use Illuminate\Support\Facades\Redis;

/**
 * Appends raw call detail records to the isolated Redis stream that buffers
 * them between FreeSWITCH's HTTP hand-off and the worker's MariaDB commit.
 *
 * The stream lives on the dedicated "cdr" connection (Redis database 3) with
 * no key prefix, so the stream key is always literally "pbx:cdr:stream"
 * regardless of the application's cache prefix.
 */
class CdrRedisIngest
{
    /** Stream holding raw payloads between the HTTP hand-off and the worker. */
    public const STREAM_KEY = 'pbx:cdr:stream';

    /**
     * Append one raw payload and return its stream entry id.
     *
     * @param  string  $rawJson  The untouched document mod_json_cdr posted
     * @return string Stream entry id, e.g. "1698847200000-0"
     */
    public function ingest(string $rawJson): string
    {
        /** @var string $id */
        $id = Redis::connection('cdr')->xadd(self::STREAM_KEY, '*', ['data' => $rawJson]);

        return $id;
    }

    /**
     * Count entries still waiting for the worker.
     *
     * Used by health checks to notice an unbounded stream before a reboot
     * would put that backlog at risk.
     */
    public function getPendingCount(): int
    {
        /** @var int $count */
        $count = Redis::connection('cdr')->xlen(self::STREAM_KEY);

        return (int) $count;
    }
}
