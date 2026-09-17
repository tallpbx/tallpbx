<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to create the security_audit_logs table.
 *
 * Provides an enterprise audit trail of administrative actions on the host security subsystem
 * (rule creations/edits/deletions, whitelist/blacklist modifications, manual bans/unbans, settings changes).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete()->comment('Admin who performed the action (NULL for system events)');
            $table->string('action', 100)->comment('Action type e.g. ban_created, unban_executed, rule_saved, settings_updated');
            $table->string('ip_address', 100)->nullable()->comment('Target IP address involved in the action if applicable');
            $table->string('description', 255)->nullable()->comment('Human-readable description of what changed');
            $table->json('details')->nullable()->comment('Structured before/after or contextual metadata');
            $table->timestamps();

            $table->index(['action', 'created_at'], 'idx_security_audit_action_created');
            $table->index(['admin_id', 'created_at'], 'idx_security_audit_admin_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_audit_logs');
    }
};
