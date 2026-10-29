<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * High-volume tables benefiting from composite (tenant_id, created_at) index
     * for tenant-scoped recent-first list ordering.
     *
     * @var array<int, string>
     */
    private const TABLES = [
        'call_recordings',
        'media_assets',
        'voicemail_messages',
        'xml_cdr',
        'xml_cdrs',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumns($tableName, ['tenant_id', 'created_at'])) {
                if (! Schema::hasIndex($tableName, ['tenant_id', 'created_at'])) {
                    Schema::table($tableName, function (Blueprint $table): void {
                        $table->index(['tenant_id', 'created_at']);
                    });
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumns($tableName, ['tenant_id', 'created_at'])) {
                if (Schema::hasIndex($tableName, ['tenant_id', 'created_at'])) {
                    Schema::table($tableName, function (Blueprint $table): void {
                        $table->dropIndex(['tenant_id', 'created_at']);
                    });
                }
            }
        }
    }
};
