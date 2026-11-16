<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the requester snapshot projected from the restore journal.
     *
     * `requested_by_admin_id` is deliberately not a foreign key: after a
     * restore the `admins` table may have reused that id, so the original
     * numeric id and this snapshot stay intact as an opaque audit record.
     */
    public function up(): void
    {
        Schema::table('restore_operations', function (Blueprint $table): void {
            $table->string('requested_by_name')->nullable()->after('requested_by_admin_id');
            $table->string('requested_by_email')->nullable()->after('requested_by_name');
        });
    }

    /**
     * Remove the requester snapshot columns.
     */
    public function down(): void
    {
        Schema::table('restore_operations', function (Blueprint $table): void {
            $table->dropColumn(['requested_by_name', 'requested_by_email']);
        });
    }
};
