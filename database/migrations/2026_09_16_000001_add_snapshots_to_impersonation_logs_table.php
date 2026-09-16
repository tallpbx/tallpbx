<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add human-readable snapshot columns to the impersonation_logs table.
     *
     * Storing admin_name and user_email directly on the audit record preserves
     * the audit trail even if an administrator or tenant user account is later
     * altered or removed.
     */
    public function up(): void
    {
        Schema::table('impersonation_logs', function (Blueprint $table) {
            $table->string('admin_name')->nullable()->after('admin_id');
            $table->string('user_email')->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('impersonation_logs', function (Blueprint $table) {
            $table->dropColumn(['admin_name', 'user_email']);
        });
    }
};
