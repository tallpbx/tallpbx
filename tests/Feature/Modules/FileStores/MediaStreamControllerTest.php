<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Models\MediaAsset;
use Modules\FileStores\Services\MediaStorageServiceInterface;

beforeEach(function (): void {
    $this->root = storage_path('framework/testing/media-streams');
    File::ensureDirectoryExists($this->root.'/files');
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();
    $this->store = FileStore::query()->create([
        'name' => 'Private stream storage',
        'provider' => 'local',
        'settings' => ['root' => $this->root],
    ]);
    $this->grantCallRecordingsView = function (User|Admin $actor, ?Tenant $tenant = null): void {
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'call-recordings.view'],
            ['module' => 'call-recordings', 'description' => 'View call recordings'],
        );
        $group = $tenant === null
            ? Group::factory()->system()->create()
            : Group::factory()->forTenant($tenant->id)->create();
        $group->permissions()->attach($permission);
        $actor->groups()->attach($group);
    };
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('streams an available local asset to an authorized member of its tenant', function (): void {
    $user = User::factory()->create();
    $user->tenants()->attach($this->tenant, ['role' => 'admin', 'primary' => true]);
    ($this->grantCallRecordingsView)($user, $this->tenant);
    File::put($this->root.'/files/call.wav', 'local media');
    $asset = createMediaStreamAsset($this, ['object_key' => 'files/call.wav']);

    $response = $this->actingAs($user)
        ->withSession(['selected_tenant_id' => (string) $this->tenant->id])
        ->get(route('panel.media-assets.stream', $asset));

    $response->assertOk()
        ->assertHeader('Content-Type', 'audio/wav')
        ->assertHeader('Content-Disposition', 'inline; filename=call.wav');
    expect($response->streamedContent())->toBe('local media');
});

it('streams an archived asset through the storage service and closes its stream', function (): void {
    $admin = Admin::factory()->create();
    ($this->grantCallRecordingsView)($admin);
    $asset = createMediaStreamAsset($this, ['file_store_id' => FileStore::query()->create([
        'name' => 'Remote stream storage',
        'provider' => 's3',
        'settings' => ['bucket' => 'media-archive', 'region' => 'us-east-1'],
    ])->id]);
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, 'remote media');
    rewind($stream);
    $storage = Mockery::mock(MediaStorageServiceInterface::class);
    $storage->shouldReceive('openReadStream')->once()->with($asset->id)->andReturn($stream);
    app()->instance(MediaStorageServiceInterface::class, $storage);

    $response = $this->actingAs($admin, 'admin')
        ->get(route('panel.media-assets.download', $asset));

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=call.wav');
    expect($response->streamedContent())->toBe('remote media')
        ->and(is_resource($stream))->toBeFalse();
});

it('returns not found when a tenant user requests another tenant’s asset', function (): void {
    $user = User::factory()->create();
    $user->tenants()->attach($this->otherTenant, ['role' => 'admin', 'primary' => true]);
    ($this->grantCallRecordingsView)($user, $this->otherTenant);
    $asset = createMediaStreamAsset($this);

    $this->actingAs($user)
        ->withSession(['selected_tenant_id' => (string) $this->otherTenant->id])
        ->get(route('panel.media-assets.stream', $asset))
        ->assertNotFound();
});

it('requires the category view permission for administrators', function (): void {
    $asset = createMediaStreamAsset($this);

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->get(route('panel.media-assets.stream', $asset))
        ->assertForbidden();
});

it('returns not found for unavailable assets', function (): void {
    $admin = Admin::factory()->create();
    ($this->grantCallRecordingsView)($admin);
    $asset = createMediaStreamAsset($this, ['status' => MediaAssetStatus::Pending]);

    $this->actingAs($admin, 'admin')
        ->get(route('panel.media-assets.stream', $asset))
        ->assertNotFound();
});

it('uses a safe filename in download headers', function (): void {
    $admin = Admin::factory()->create();
    ($this->grantCallRecordingsView)($admin);
    File::put($this->root.'/files/call.wav', 'local media');
    $asset = createMediaStreamAsset($this, ['object_key' => 'files/call.wav', 'original_filename' => "call\r\nX-Injected: yes.wav"]);

    $response = $this->actingAs($admin, 'admin')
        ->get(route('panel.media-assets.download', $asset));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))
        ->toBe('attachment; filename=callX-Injectedyes.wav')
        ->not->toContain("\r")
        ->not->toContain("\n");
});

/**
 * Create an available media asset owned by the primary test tenant.
 *
 * @param  array<string, mixed>  $attributes
 */
function createMediaStreamAsset(object $test, array $attributes = []): MediaAsset
{
    return MediaAsset::query()->create([
        'tenant_id' => $test->tenant->id,
        'file_store_id' => $test->store->id,
        'owner_type' => 'call-recording',
        'owner_id' => fake()->uuid(),
        'category' => MediaCategory::CallRecording,
        'status' => MediaAssetStatus::Available,
        'object_key' => 'files/call.wav',
        'original_filename' => 'call.wav',
        'mime_type' => 'audio/wav',
        'byte_size' => 11,
        'sha256' => hash('sha256', 'local media'),
        ...$attributes,
    ]);
}
