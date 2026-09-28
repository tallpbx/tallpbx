<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record the git revision a module was uninstalled from so module:restore
     * can bring its files back even after the deletion itself is committed.
     */
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->string('source_ref')->nullable()->after('composer_package');
        });
    }

    /**
     * Reverse the migration by dropping the recorded revision column.
     */
    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->dropColumn('source_ref');
        });
    }
};
