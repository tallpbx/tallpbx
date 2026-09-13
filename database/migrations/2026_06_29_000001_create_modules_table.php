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
