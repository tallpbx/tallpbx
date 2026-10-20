<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the database migration.
     */
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            // Note: the former `source_ref` column is deliberately absent from this
            // baseline. Databases upgraded from versions that created it keep a
            // harmless inert nullable column, and no cleanup migration will ever
            // drop it: MigrationSafetyGuard blocks dropColumn in up() on the
            // protected database, and the inert-column ruling established the
            // column is unfilled and never existed on live installs.
            // Composer package the module was installed from, kept so module:restore
            // can reinstall vendor modules after an uninstall.
            $table->string('composer_package')->nullable();
            $table->string('display_name');
            $table->string('version');
            $table->boolean('enabled')->default(true);
            $table->string('status')->default('enabled')->index();
            $table->boolean('protected')->default(false);
            $table->boolean('required')->default(false);
            $table->integer('priority')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the database migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
