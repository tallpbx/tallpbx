<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devices represent physical or soft phones provisioned for a tenant.
     * Each device records vendor, model, MAC address (normalized), and the
     * provisioning template to use. MAC addresses are globally unique.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('vendor');
            $table->string('model')->nullable();
            $table->string('mac_address')->unique();
            $table->string('template')->nullable();
            $table->foreignUuid('sip_account_id')->nullable()->constrained('sip_accounts')->nullOnDelete();
            $table->json('settings')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
