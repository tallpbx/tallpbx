<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the local-first media asset ledger.
     */
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('file_store_id')->constrained('file_stores')->restrictOnDelete();
            $table->string('owner_type');
            $table->uuid('owner_id');
            $table->string('category');
            $table->string('status')->default('pending');
            $table->string('object_key');
            $table->text('staging_path')->nullable();
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->unsignedSmallInteger('sync_attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
            $table->index(['tenant_id', 'category', 'status']);
            $table->index(['status', 'last_attempt_at']);
        });
    }

    /**
     * Remove the media asset ledger.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
