<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the module taxonomy columns to the modules table.
 *
 * `source` records where a module comes from (core app-modules/, untracked
 * custom-modules/, or vendor packages) so lifecycle actions can refuse to
 * delete permanent monorepo modules. `category` stores the canonical UI
 * navigation group (plan §1.6) that the /panel/modules workbench groups by.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->string('source')->default('core')->after('name');
            $table->string('category')->nullable()->after('version');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->dropColumn(['source', 'category']);
        });
    }
};
