<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration to standardize port range formatting to hyphen syntax (16384-32768).
 *
 * Converts legacy colon port range syntax (e.g. 16384:32768) in security_services
 * and security_rules to native nftables hyphen range syntax (16384-32768) for
 * consistency across the UI, database, and generated kernel rulesets.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Standardize port ranges in PBX Port Catalog services
        DB::table('security_services')
            ->where('port_range', 'like', '%:%')
            ->get()
            ->each(function (object $service): void {
                DB::table('security_services')
                    ->where('id', $service->id)
                    ->update([
                        'port_range' => str_replace(':', '-', $service->port_range),
                        'updated_at' => now(),
                    ]);
            });

        // Standardize custom port ranges in sequential firewall rules
        DB::table('security_rules')
            ->where('custom_port', 'like', '%:%')
            ->get()
            ->each(function (object $rule): void {
                DB::table('security_rules')
                    ->where('id', $rule->id)
                    ->update([
                        'custom_port' => str_replace(':', '-', (string) $rule->custom_port),
                        'updated_at' => now(),
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert canonical RTP range back to legacy colon syntax if rolled back
        DB::table('security_services')
            ->where('name', 'RTP Voice/Video Media')
            ->where('port_range', '16384-32768')
            ->update([
                'port_range' => '16384:32768',
                'updated_at' => now(),
            ]);
    }
};
