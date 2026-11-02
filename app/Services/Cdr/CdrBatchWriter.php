<?php

declare(strict_types=1);

namespace App\Services\Cdr;

use Illuminate\Support\Facades\DB;
use Modules\CallDetailRecords\Models\Cdr;

/**
 * Commits parsed call records to MariaDB in one transaction.
 *
 * Shared by the stream digest and the spool drainer so both paths keep the
 * exact same upsert semantics: one row per (call_uuid, leg), so a replayed
 * record updates instead of duplicating.
 */
class CdrBatchWriter
{
    /**
     * Columns refreshed when a row already exists for (call_uuid, leg).
     *
     * @var array<int, string>
     */
    private const UPDATE_COLUMNS = [
        'tenant_id', 'b_leg_uuid', 'caller_id', 'caller_id_name', 'destination',
        'duration', 'billsec', 'hangup_cause', 'direction', 'record_path',
        'start_stamp', 'answer_stamp', 'end_stamp', 'json_data', 'updated_at',
    ];

    /**
     * Upsert every parsed row in one transaction.
     *
     * A failure rolls the whole batch back so callers can keep every source
     * record (stream entry or spool file) and retry later.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function write(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::transaction(function () use ($rows): void {
            Cdr::upsert($rows, ['call_uuid', 'leg'], self::UPDATE_COLUMNS);
        });
    }
}
