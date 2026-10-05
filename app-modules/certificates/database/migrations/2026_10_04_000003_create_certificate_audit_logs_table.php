<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to create the certificate_audit_logs table.
 *
 * Provides a comprehensive audit trail of all certificate lifecycle events:
 * issuances, imports, renewals, service deployments, deletions, and operational failures.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('certificate_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('certificate_id')->nullable()->constrained('certificates')->nullOnDelete()->comment('Target certificate if applicable');
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete()->comment('Admin who triggered the action, or NULL for system scheduler');
            $table->string('action', 50)->comment('Action type e.g. issued, renewed, imported, generated, deployed_web, deployed_telephony, deleted, failed');
            $table->string('status', 20)->comment('success, error, warning');
            $table->text('message')->comment('Plain-language summary of what occurred');
            $table->json('details')->nullable()->comment('Contextual payload or error details');
            $table->timestamps();

            $table->index(['certificate_id', 'created_at'], 'idx_cert_audit_certificate_created');
            $table->index(['action', 'created_at'], 'idx_cert_audit_action_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificate_audit_logs');
    }
};
