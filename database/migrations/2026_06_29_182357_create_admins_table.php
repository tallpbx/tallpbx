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
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('language', 5)->default('en');
            $table->string('password');
            $table->string('theme', 20)->default('system');
            $table->boolean('enabled')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the database migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
