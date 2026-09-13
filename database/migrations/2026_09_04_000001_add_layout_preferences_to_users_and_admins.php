<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('layout_mode', 20)->default('sidebar')->after('theme');
            $table->boolean('sidebar_collapsed')->default(false)->after('layout_mode');
        });

        Schema::table('admins', function (Blueprint $table): void {
            $table->string('layout_mode', 20)->default('sidebar')->after('theme');
            $table->boolean('sidebar_collapsed')->default(false)->after('layout_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->dropColumn(['layout_mode', 'sidebar_collapsed']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['layout_mode', 'sidebar_collapsed']);
        });
    }
};
