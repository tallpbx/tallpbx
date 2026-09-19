<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('security_services', function (Blueprint $table): void {
            // Widen the 'protocol' column from ENUM('tcp','udp','both') to a free
            // VARCHAR(20) so that custom rules can also use 'icmp'.
            // The schema builder is used instead of a raw MariaDB "MODIFY COLUMN"
            // statement so the migration also runs on SQLite, which is the driver
            // the automated test suite uses. On MariaDB the resulting column is
            // identical (VARCHAR(20) NOT NULL DEFAULT 'both').
            $table->string('protocol', 20)->default('both')->change();

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

        // Restore the original ENUM('tcp','udp','both') definition of the column.
        // Using the schema builder keeps this step working on both MariaDB and SQLite.
        Schema::table('security_services', function (Blueprint $table): void {
            $table->enum('protocol', ['tcp', 'udp', 'both'])->default('both')->change();
        });
    }
};
