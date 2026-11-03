<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Require the call reference number on every call detail record.
 *
 * The (call_uuid, leg) pair is what stops a replayed record from being
 * stored twice. A blank call reference number breaks that rule quietly,
 * because a database treats two blanks as two different values: the unique
 * key never fires, so the same record could be stored again and again, and
 * the row could never be replayed safely.
 *
 * The parser has always refused a payload without a call reference number
 * and quarantined it instead, so the pipeline cannot produce such a row.
 * The column is made required anyway so the guarantee belongs to the
 * database rather than to one code path, and so a writer added later cannot
 * quietly defeat it.
 */
return new class extends Migration
{
    /**
     * Refuse a blank call reference number.
     */
    public function up(): void
    {
        $blanks = DB::table('cdrs')->whereNull('call_uuid')->count();

        if ($blanks > 0) {
            // Such a row can never be deduplicated, so it is a hazard rather
            // than a record. The migration refuses to continue instead of
            // removing call history on its own; an operator decides what to
            // do with it.
            throw new RuntimeException(sprintf(
                'Cannot require cdrs.call_uuid: %d record(s) carry no call reference number. '
                .'A record without one can never be deduplicated, so it must be cleared by an operator before this migration runs.',
                $blanks,
            ));
        }

        Schema::table('cdrs', function (Blueprint $table): void {
            // The type and length stay as they are; only the blank allowance
            // goes. The existing single-column and composite indexes stay in
            // place, so the unique key on (call_uuid, leg) starts firing for
            // every row.
            $table->string('call_uuid')->change();
        });
    }

    /**
     * Allow a blank call reference number again.
     */
    public function down(): void
    {
        Schema::table('cdrs', function (Blueprint $table): void {
            $table->string('call_uuid')->nullable()->change();
        });
    }
};
