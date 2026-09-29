<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the security_bans.vector column so SIP scanner bans can be stored.
 *
 * The original ENUM('web_auth','sip_auth','ssh','manual') cannot represent
 * the new 'sip_scanner' vector introduced with the SIP bot filtering
 * feature. The column becomes a VARCHAR(20) — the same approach the ICMP
 * migration used for security_services.protocol — so both MariaDB and the
 * SQLite test driver accept it and future vectors need no schema change.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('security_bans', function (Blueprint $table): void {
            $table->string('vector', 20)->comment('Attack vector or manual ban')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore the original ENUM definition. Rows carrying 'sip_scanner'
        // must be removed first or the narrowed column would reject them.
        DB::table('security_bans')
            ->where('vector', 'sip_scanner')
            ->delete();

        Schema::table('security_bans', function (Blueprint $table): void {
            $table->enum('vector', ['web_auth', 'sip_auth', 'ssh', 'manual'])
                ->comment('Attack vector or manual ban')
                ->change();
        });
    }
};
