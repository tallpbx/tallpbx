<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Models\MediaAsset;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    /**
     * Define a valid media asset with a local destination by default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'file_store_id' => function (): string {
                return FileStore::query()->create([
                    'name' => 'Media asset factory '.Str::uuid(),
                    'provider' => 'local',
                    'settings' => ['root' => storage_path('framework/testing/media-assets')],
                ])->id;
            },
            'owner_type' => 'call-recording',
            'owner_id' => fake()->uuid(),
            'category' => MediaCategory::CallRecording,
            'status' => MediaAssetStatus::Pending,
            'object_key' => 'media/'.fake()->uuid().'.wav',
            'staging_path' => null,
            'original_filename' => 'recording.wav',
            'mime_type' => 'audio/wav',
            'byte_size' => fake()->numberBetween(1_024, 1_048_576),
            'sha256' => hash('sha256', fake()->uuid()),
            'sync_attempts' => 0,
            'last_attempt_at' => null,
            'synced_at' => null,
            'alerted_at' => null,
            'last_error' => null,
        ];
    }
}
