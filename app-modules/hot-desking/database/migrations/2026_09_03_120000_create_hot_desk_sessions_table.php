<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `hot_desk_sessions` table for managing hot desking
     * device and extension mobility sessions. Each session links a user's
     * primary extension with a physical desk phone extension and optional device.
     */
    public function up(): void
    {
        Schema::create('hot_desk_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('extension_id')->constrained('extensions')->cascadeOnDelete();
            $table->foreignUuid('device_extension_id')->constrained('extensions')->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('device_mac')->nullable();
            $table->string('ip_address')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('login_at')->index();
            $table->timestamp('logout_at')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'extension_id']);
            $table->index(['tenant_id', 'device_extension_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hot_desk_sessions');
    }
};
