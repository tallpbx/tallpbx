<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the backups table for storing backup configurations and run history.
     */
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->json('scope')->comment('Array of backup scopes: database, app_files, media, configuration');
            $table->integer('retention_count')->default(7)->comment('Number of backups to keep before pruning');
            $table->boolean('compression')->default(true)->comment('Whether to gzip the backup archive');
            $table->foreignUuid('file_store_id')->nullable()->constrained('file_stores')->nullOnDelete();
            $table->string('destination_disk')->default('local')->comment('Filesystem disk for backup storage');
            $table->string('manifest_path')->nullable();
            $table->string('archive_checksum', 64)->nullable();
            $table->unsignedBigInteger('archive_bytes')->nullable();
            $table->string('schedule_cron')->nullable()->comment('Cron expression for scheduled backups');
            $table->string('status')->default('pending')->comment('pending, running, completed, failed');
            $table->unsignedBigInteger('size_bytes')->nullable()->comment('Size of the last successful backup in bytes');
            $table->string('last_file_path')->nullable()->comment('Path to the last backup file on the destination disk');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable()->comment('Error message from the last failed backup');
            $table->boolean('notify_on_success')->default(true)->comment('Send notification on successful backup');
            $table->boolean('notify_on_failure')->default(true)->comment('Send notification on failed backup');
            $table->timestamps();
        });
    }

    /**
     * Drop the backups table.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
