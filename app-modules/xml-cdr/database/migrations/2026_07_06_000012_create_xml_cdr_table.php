<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xml_cdr', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('call_uuid')->nullable()->index();
            $table->string('caller_id')->nullable();
            $table->string('caller_id_name')->nullable();
            $table->string('destination')->nullable();
            $table->unsignedInteger('duration')->default(0);
            $table->unsignedInteger('billsec')->default(0);
            $table->string('hangup_cause')->nullable();
            $table->string('direction')->nullable();
            $table->timestamp('start_stamp')->nullable();
            $table->timestamp('answer_stamp')->nullable();
            $table->timestamp('end_stamp')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xml_cdr');
    }
};
