<?php

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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('purpose')->default('customer')->index();
            $table->foreignId('primary_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the database migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
