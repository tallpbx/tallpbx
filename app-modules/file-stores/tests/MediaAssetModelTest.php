<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Modules\CallRecordings\Models\CallRecording;
use Modules\ConferenceCenters\Models\ConferenceCenter;
use Modules\Fax\Models\FaxInbox;
use Modules\Fax\Models\FaxOutgoing;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Models\MediaAsset;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\MusicOnHold\Models\MusicOnHold;
use Modules\Recordings\Models\Recording;
use Modules\VoicemailMessages\Models\VoicemailMessage;
use Modules\Voicemails\Models\Voicemail;

beforeEach(function (): void {
    app(TenantManager::class)->clear();

    $this->fileStore = FileStore::query()->create([
        'name' => 'Media asset test store',
        'provider' => 'local',
        'settings' => ['root' => storage_path('framework/testing/media-assets')],
    ]);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
});

it('stores a UUID media asset with enum casts and a polymorphic owner', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $tenant->id);
    $recording = CallRecording::factory()->create(['tenant_id' => $tenant->id]);

    $asset = MediaAsset::factory()
        ->for($recording, 'owner')
        ->create([
            'tenant_id' => $tenant->id,
            'file_store_id' => $this->fileStore->id,
            'category' => MediaCategory::CallRecording,
            'status' => MediaAssetStatus::Pending,
        ]);

    expect(Str::isUuid($asset->id))->toBeTrue()
        ->and($asset->category)->toBe(MediaCategory::CallRecording)
        ->and($asset->status)->toBe(MediaAssetStatus::Pending)
        ->and($asset->fileStore->is($this->fileStore))->toBeTrue()
        ->and($asset->owner->is($recording))->toBeTrue();
});

it('allows only one current media asset for an owner', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $tenant->id);
    $recording = CallRecording::factory()->create(['tenant_id' => $tenant->id]);

    MediaAsset::factory()
        ->for($recording, 'owner')
        ->create([
            'tenant_id' => $tenant->id,
            'file_store_id' => $this->fileStore->id,
        ]);

    expect(fn (): MediaAsset => MediaAsset::factory()
        ->for($recording, 'owner')
        ->create([
            'tenant_id' => $tenant->id,
            'file_store_id' => $this->fileStore->id,
        ]))->toThrow(QueryException::class);
});

it('prevents deleting a file store referenced by a media asset', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $tenant->id);
    $recording = CallRecording::factory()->create(['tenant_id' => $tenant->id]);

    MediaAsset::factory()
        ->for($recording, 'owner')
        ->create([
            'tenant_id' => $tenant->id,
            'file_store_id' => $this->fileStore->id,
        ]);

    expect(fn (): ?bool => $this->fileStore->delete())
        ->toThrow(QueryException::class);
});

it('uses stable aliases for every media owner type', function (): void {
    expect((new VoicemailMessage)->getMorphClass())->toBe('voicemail-message')
        ->and((new CallRecording)->getMorphClass())->toBe('call-recording')
        ->and((new FaxInbox)->getMorphClass())->toBe('fax-inbound')
        ->and((new FaxOutgoing)->getMorphClass())->toBe('fax-outbound')
        ->and((new Recording)->getMorphClass())->toBe('recording')
        ->and((new MusicOnHold)->getMorphClass())->toBe('music-on-hold')
        ->and((new Voicemail)->getMorphClass())->toBe('voicemail')
        ->and((new IvrMenu)->getMorphClass())->toBe('ivr-menu')
        ->and((new ConferenceCenter)->getMorphClass())->toBe('conference-center');
});
