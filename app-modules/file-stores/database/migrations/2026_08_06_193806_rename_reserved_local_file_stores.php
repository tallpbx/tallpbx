<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rename the two built-in local stores without changing their IDs or data.
     */
    public function up(): void
    {
        DB::table('file_stores')->where('name', 'Local media')->update(['name' => 'Local storage - media']);
        DB::table('file_stores')->where('name', 'Local host')->update(['name' => 'Local storage - backups']);
    }

    /**
     * Restore the previous labels if this migration is rolled back.
     */
    public function down(): void
    {
        DB::table('file_stores')->where('name', 'Local storage - media')->update(['name' => 'Local media']);
        DB::table('file_stores')->where('name', 'Local storage - backups')->update(['name' => 'Local host']);
    }
};
