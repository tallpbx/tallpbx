<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align the call detail records listing index with the panel's actual query.
 *
 * The panel sorts a tenant's records by the moment the call started, not by
 * the moment the worker happened to store the row. The original index was
 * built on the storage timestamp, so the database had to sort every panel
 * page in memory. This migration replaces it with the column the query orders
 * by, so the rows are read in order.
 *
 * The two steps are deliberately separate statements. The tenant foreign key
 * borrows an index that starts with the tenant column, and the old index
 * happens to be the one it borrowed. Dropping it while nothing else covers
 * that column is refused by the database, so the replacement index is created
 * first and only then is the old one removed.
 *
 * The migration is safe on both paths: a fresh install already carries the
 * corrected index from the create migration, while a host that ran the
 * earlier version of that migration still carries the old index.
 */
return new class extends Migration
{
    /**
     * Replace the storage-timestamp index with the call-start index.
     */
    public function up(): void
    {
        if (Schema::hasIndex('cdrs', 'cdrs_tenant_id_start_stamp_index')) {
            return;
        }

        Schema::table('cdrs', function (Blueprint $table): void {
            $table->index(['tenant_id', 'start_stamp'], 'cdrs_tenant_id_start_stamp_index');
        });

        if (! Schema::hasIndex('cdrs', 'cdrs_tenant_id_created_at_index')) {
            return;
        }

        Schema::table('cdrs', function (Blueprint $table): void {
            $table->dropIndex('cdrs_tenant_id_created_at_index');
        });
    }

    /**
     * Restore the storage-timestamp index.
     */
    public function down(): void
    {
        if (! Schema::hasIndex('cdrs', 'cdrs_tenant_id_start_stamp_index')) {
            return;
        }

        Schema::table('cdrs', function (Blueprint $table): void {
            $table->index(['tenant_id', 'created_at'], 'cdrs_tenant_id_created_at_index');
        });

        Schema::table('cdrs', function (Blueprint $table): void {
            $table->dropIndex('cdrs_tenant_id_start_stamp_index');
        });
    }
};
