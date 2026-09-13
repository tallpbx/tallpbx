<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('stores PBX media as file paths instead of database payloads', function () {
    foreach (['call_recordings', 'recordings', 'voicemail_messages'] as $table) {
        expect(Schema::hasColumn($table, 'file_path'))->toBeTrue();

        foreach (['audio_data', 'media_data', 'file_data', 'content', 'base64'] as $payloadColumn) {
            expect(Schema::hasColumn($table, $payloadColumn))->toBeFalse();
        }
    }
});
