<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dialplans define call routing logic — a collection of conditions
     * and actions evaluated in order. Each dialplan has one or more
     * details (conditions) that determine how calls are routed.
     */
    public function up(): void
    {
        Schema::create('dialplans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('context')->default('default');
            $table->integer('order')->default(100);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'context', 'enabled', 'order'], 'dialplans_xml_context_idx');
        });

        Schema::create('dialplan_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('dialplan_id');
            $table->string('tag')->default('condition');
            $table->string('field')->nullable();
            $table->string('expression')->nullable();
            $table->string('action')->nullable();
            $table->text('data')->nullable();
            $table->integer('order')->default(10);
            $table->timestamps();

            $table->foreign('dialplan_id')
                ->references('id')->on('dialplans')
                ->cascadeOnDelete();

            $table->index(['dialplan_id', 'order'], 'dialplan_details_dialplan_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dialplan_details');
        Schema::dropIfExists('dialplans');
    }
};
