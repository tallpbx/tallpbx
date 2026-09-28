<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record the Composer package a module was installed from so
     * module:restore can reinstall vendor modules after an uninstall.
     */
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->string('composer_package')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migration by dropping the recorded package column.
     */
    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->dropColumn('composer_package');
        });
    }
};
