<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to support ICMP protocol and configurable rate limits in security_services.
 *
 * Allows system and custom services to specify 'icmp' as their protocol,
 * and adds optional rate_limit (packets/second) and burst fields.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE security_services MODIFY COLUMN protocol VARCHAR(20) NOT NULL DEFAULT 'both'");

        Schema::table('security_services', function (Blueprint $table): void {
            if (! Schema::hasColumn('security_services', 'rate_limit')) {
                $table->unsignedInteger('rate_limit')->nullable()->after('source_ip')->comment('Rate limit in packets/sec (null for unlimited)');
            }
            if (! Schema::hasColumn('security_services', 'burst')) {
                $table->unsignedInteger('burst')->nullable()->after('rate_limit')->comment('Burst packet count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('security_services', function (Blueprint $table): void {
            if (Schema::hasColumn('security_services', 'burst')) {
                $table->dropColumn('burst');
            }
            if (Schema::hasColumn('security_services', 'rate_limit')) {
                $table->dropColumn('rate_limit');
            }
        });

        DB::statement("ALTER TABLE security_services MODIFY COLUMN protocol ENUM('tcp', 'udp', 'both') NOT NULL DEFAULT 'both'");
    }
};
