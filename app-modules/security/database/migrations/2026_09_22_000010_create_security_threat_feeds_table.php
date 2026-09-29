<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the threat feed configuration table.
     *
     * One row per provider driver in this release: `provider` is unique by
     * design. The feed CIDRs themselves are never stored here as rows — they
     * are streamed into the nftables kernel interval sets; this table keeps
     * only the sync settings and the metadata of the most recent attempt.
     * The provider contract is deliberately instance-oriented, so relaxing
     * the uniqueness to a (provider, slug) pair in a future release needs no
     * driver changes.
     */
    public function up(): void
    {
        Schema::create('security_threat_feeds', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->unique();
            $table->string('name');
            $table->boolean('enabled')->default(false);
            $table->enum('country_mode', ['all', 'blacklist', 'whitelist'])->default('all');
            $table->text('countries')->nullable();
            $table->enum('sync_interval', ['hourly', '4_hours', '12_hours', 'daily'])->default('daily');
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('entries_count')->default(0);
            $table->string('etag')->nullable();
            $table->string('last_modified_header')->nullable();
            // How many feed lines failed CIDR validation on the last sync,
            // surfaced in the UI so a provider format change is visible
            // instead of silent.
            $table->unsignedInteger('last_rejected_lines')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Drop the threat feed configuration table.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_threat_feeds');
    }
};
