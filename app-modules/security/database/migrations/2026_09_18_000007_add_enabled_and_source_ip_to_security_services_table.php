<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to add enabled and source_ip columns to security_services.
 *
 * Enables administrators to toggle individual core PBX services (such as closing
 * WebRTC if not utilized) and restrict core services to specific IP addresses or subnets
 * (such as restricting SSH or Web Admin access to an administrative VPN network).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('security_services', function (Blueprint $table): void {
            $table->boolean('enabled')->default(true)->after('is_system')->comment('Whether this service is active in the firewall ruleset');
            $table->string('source_ip', 100)->default('any')->after('enabled')->comment('Source IP or CIDR restriction (any or CIDR)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('security_services', function (Blueprint $table): void {
            $table->dropColumn(['enabled', 'source_ip']);
        });
    }
};
