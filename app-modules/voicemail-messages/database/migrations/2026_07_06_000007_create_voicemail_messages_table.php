<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voicemail_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('voicemail_id')->constrained('voicemails')->cascadeOnDelete();
            $table->string('caller_id')->nullable();
            $table->string('caller_id_name')->nullable();
            $table->unsignedInteger('duration')->default(0);
            $table->string('file_path');
            $table->uuid('freeswitch_message_uuid')->nullable()->unique();
            $table->string('freeswitch_domain')->nullable();
            $table->string('freeswitch_folder')->nullable();
            $table->boolean('listened')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voicemail_messages');
    }
};
