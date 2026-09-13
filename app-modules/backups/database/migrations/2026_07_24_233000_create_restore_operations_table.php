<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the durable audit and lifecycle record for restore requests.
     */
    public function up(): void
    {
        Schema::create('restore_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('requested_by_admin_id');
            $table->string('archive_path');
            $table->string('archive_checksum', 64);
            $table->uuid('source_backup_id')->nullable();
            $table->json('scopes');
            $table->string('status')->default('queued');
            $table->text('failure_reason')->nullable();
            $table->string('pre_restore_snapshot_path')->nullable();
            $table->string('helper_log_path')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Remove restore-operation records when the module migration is rolled back.
     */
    public function down(): void
    {
        Schema::dropIfExists('restore_operations');
    }
};
