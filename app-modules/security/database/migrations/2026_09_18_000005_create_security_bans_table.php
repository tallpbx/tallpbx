<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to create the security_bans table.
 *
 * Single source of truth for all banned IP addresses (dynamic auto-bans and manual bans).
 * Tracks attack vector, attempt count, ban timestamp, expiration, and unban metadata.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_bans', function (Blueprint $table): void {
            $table->id();
            $table->string('ip_address', 100);
            $table->enum('vector', ['web_auth', 'sip_auth', 'ssh', 'manual'])->comment('Attack vector or manual ban');
            $table->string('reason', 255)->comment('Plain-language reason for the ban');
            $table->unsignedInteger('attempt_count')->default(1)->comment('Number of failed attempts triggering ban');
            $table->timestamp('banned_at')->useCurrent()->comment('When the ban took effect');
            $table->timestamp('expires_at')->nullable()->comment('When the ban expires (NULL for permanent)');
            $table->boolean('is_active')->default(true)->comment('Whether the ban is currently active');
            $table->timestamp('unbanned_at')->nullable()->comment('When the ban was lifted');
            $table->foreignId('unbanned_by_admin_id')->nullable()->constrained('admins')->nullOnDelete()->comment('Administrator who manually lifted the ban');
            $table->timestamps();

            $table->index(['ip_address', 'is_active'], 'idx_security_bans_ip');
            $table->index(['is_active', 'expires_at'], 'idx_security_bans_active_expires');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_bans');
    }
};
