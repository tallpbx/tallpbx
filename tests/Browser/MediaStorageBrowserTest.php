<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Models\MediaAsset;
use Modules\FileStores\Services\MediaArchiveDestinationServiceInterface;

// Create an administrator and two uniquely named destinations for each test so
// the browser can verify both local-only and remote archive choices safely.
beforeEach(function (): void {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->group = Group::factory()->system()->create();

    $permissions = collect(['file-stores.view', 'file-stores.update', 'admin.notifications.view'])
        ->map(fn (string $name): Permission => Permission::query()->firstOrCreate(
            ['name' => $name],
            ['module' => 'file-stores', 'description' => 'Dusk permission for '.$name],
        ));
    $this->group->permissions()->sync($permissions->pluck('id'));
    $this->admin->groups()->attach($this->group);
    $this->destinationPrefix = 'Dusk media '.Str::uuid();
    $this->mediaFixtureRoots = [];
    $this->mediaFixtureAssets = [];
    $this->mediaFixtureStores = [];
    $this->mediaFixtureUsers = [];
    $this->mediaFixtureGroups = [];
    $this->mediaFixtureTenants = [];

    $this->localDestination = FileStore::query()->create([
        'name' => $this->destinationPrefix.' local',
        'provider' => 'local',
        'settings' => ['root' => storage_path('framework/dusk-media-archive')],
    ]);
    $this->remoteDestination = FileStore::query()->create([
        'name' => $this->destinationPrefix.' remote',
        'provider' => 'sftp',
        'settings' => [
            'host' => 'archive.example.test',
            'username' => 'dusk',
            'root' => '/media',
        ],
    ]);
});

// Restore the normal local archive selection and delete the records this test
// created so later browser tests do not inherit its destination configuration.
afterEach(function (): void {
    DatabaseNotification::query()
        ->where('notifiable_type', Admin::class)
        ->where('notifiable_id', $this->admin->id)
        ->delete();

    foreach ($this->mediaFixtureAssets as $asset) {
        $asset->delete();
    }

    foreach ($this->mediaFixtureUsers as $user) {
        $user->groups()->detach();
        $user->tenants()->detach();
        $user->delete();
    }

    foreach ($this->mediaFixtureGroups as $group) {
        $group->delete();
    }

    foreach ($this->mediaFixtureTenants as $tenant) {
        $tenant->delete();
    }

    foreach ($this->mediaFixtureStores as $store) {
        $store->delete();
    }

    foreach ($this->mediaFixtureRoots as $root) {
        File::deleteDirectory($root);
    }

    app(MediaArchiveDestinationServiceInterface::class)->set(
        FileStore::query()->firstOrCreate(
            ['name' => 'Local storage - media'],
            [
                'provider' => 'local',
                'settings' => ['root' => config('media-storage.store_root')],
            ],
        ),
    );

    $this->localDestination->delete();
    $this->remoteDestination->delete();
    $this->admin->groups()->detach($this->group);
    $this->group->delete();
    $this->admin->delete();
});

// Verify that an administrator can keep the reserved local media destination
// or select a remote archive destination through the panel.
it('lets a system admin select the reserved local or a remote media archive destination', function (): void {
    // Exercise the same controls an administrator uses in the File Stores page.
    $this->browse(function (Browser $browser): void {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/file-stores')
            ->waitFor('#media-archive-file-store', 5)
            ->assertSee('Media archive destination')
            ->assertSee('Local storage - media')
            ->assertSee($this->destinationPrefix.' remote')
            ->select('#media-archive-file-store', $this->remoteDestination->id)
            ->press('Save archive destination')
            ->waitFor("#archive-destination-status[data-archive-destination-id=\"{$this->remoteDestination->id}\"]", 5);
    });

    expect(app(MediaArchiveDestinationServiceInterface::class)->current()->is($this->remoteDestination))->toBeTrue();
});

it('streams local recording media to an authorized owning tenant user', function (): void {
    [$user, $asset] = createDuskMediaFixture($this, MediaCategory::Recording, 'local-announcement.wav', 'local announcement bytes');

    $this->browse(function (Browser $browser) use ($user, $asset): void {
        $browser->loginAs($user, 'web')->visit('/panel/dashboard');

        expect(readDuskMediaResponse($browser, "/panel/media-assets/{$asset->id}/stream"))
            ->toBe([200, 'audio/wav', 24, 'inline; filename=local-announcement.wav']);
    });
});

it('streams and downloads an archived call recording from a second local store', function (): void {
    [$user, $asset] = createDuskMediaFixture($this, MediaCategory::CallRecording, 'archived-call.wav', 'archived call recording bytes', true);

    $this->browse(function (Browser $browser) use ($user, $asset): void {
        $browser->loginAs($user, 'web')->visit('/panel/dashboard');

        expect(readDuskMediaResponse($browser, "/panel/media-assets/{$asset->id}/stream"))
            ->toBe([200, 'audio/wav', 29, 'inline; filename=archived-call.wav'])
            ->and(readDuskMediaResponse($browser, "/panel/media-assets/{$asset->id}/download"))
            ->toBe([200, 'audio/wav', 29, 'attachment; filename=archived-call.wav']);
    });
});

it('hides foreign and pending media while distinguishing missing permission', function (): void {
    [$owner, $asset] = createDuskMediaFixture($this, MediaCategory::Recording, 'private-recording.wav', 'private recording bytes');
    $foreignTenant = Tenant::factory()->create();
    $foreignUser = User::factory()->create();
    $foreignUser->tenants()->attach($foreignTenant, ['role' => 'admin', 'primary' => true]);
    grantDuskMediaPermission($foreignUser, $foreignTenant, 'recordings.view', $this);
    $unprivilegedUser = User::factory()->create();
    $unprivilegedUser->tenants()->attach($owner->tenants()->firstOrFail(), ['role' => 'member', 'primary' => true]);
    $pendingAsset = MediaAsset::withoutGlobalScopes()->create([
        ...$asset->toArray(),
        'id' => (string) Str::uuid(),
        'owner_id' => (string) Str::uuid(),
        'status' => MediaAssetStatus::Pending,
    ]);
    $this->mediaFixtureUsers[] = $foreignUser;
    $this->mediaFixtureUsers[] = $unprivilegedUser;
    $this->mediaFixtureTenants[] = $foreignTenant;
    $this->mediaFixtureAssets[] = $pendingAsset;

    $this->browse(function (Browser $foreignBrowser, Browser $ownerBrowser, Browser $unprivilegedBrowser) use ($foreignUser, $unprivilegedUser, $owner, $asset, $pendingAsset): void {
        $foreignBrowser->loginAs($foreignUser, 'web')->visit('/panel/dashboard');
        expect(readDuskMediaResponse($foreignBrowser, "/panel/media-assets/{$asset->id}/stream")[0])->toBe(404)
            ->and(readDuskMediaResponse($foreignBrowser, "/panel/media-assets/{$asset->id}/download")[0])->toBe(404);

        $ownerBrowser->loginAs($owner, 'web')->visit('/panel/dashboard');
        expect(readDuskMediaResponse($ownerBrowser, "/panel/media-assets/{$pendingAsset->id}/stream")[0])->toBe(404);

        $unprivilegedBrowser->loginAs($unprivilegedUser, 'web')->visit('/panel/dashboard');
        expect(readDuskMediaResponse($unprivilegedBrowser, "/panel/media-assets/{$asset->id}/stream")[0])->toBe(403);
    });
});

it('shows safe archive failure details without exposing storage internals', function (): void {
    DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'dusk-media-archive-failure',
        'notifiable_type' => $this->admin::class,
        'notifiable_id' => $this->admin->id,
        'data' => [
            'title' => 'Media archive transfer failed',
            'message' => 'A media archive could not be transferred. Its local spool has been retained for recovery.',
            'media_asset_id' => (string) Str::uuid(),
            'category' => 'call-recording',
            'original_filename' => 'customer-call.wav',
            'staging_path' => '/private/dusk-spool-marker.wav',
            'object_key' => 'private-object-key.wav',
            'last_error' => 'provider-secret-error',
        ],
    ]);

    $this->browse(function (Browser $browser): void {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/notifications')
            ->waitForText('Media archive transfer failed', 5)
            ->assertSee('call-recording')
            ->assertSee('customer-call.wav')
            ->assertDontSee('dusk-spool-marker')
            ->assertDontSee('private-object-key')
            ->assertDontSee('provider-secret-error');
    });
});

/**
 * Create a tenant user, local file store, and available asset for one browser media scenario.
 *
 * @return array{0: User, 1: MediaAsset}
 */
function createDuskMediaFixture(object $test, MediaCategory $category, string $filename, string $contents, bool $archive = false): array
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'admin', 'primary' => true]);
    grantDuskMediaPermission($user, $tenant, $category === MediaCategory::CallRecording ? 'call-recordings.view' : 'recordings.view', $test);
    $root = storage_path('framework/dusk-media-'.Str::uuid());
    $objectKey = ($archive ? 'archive/' : 'runtime/').$filename;
    File::ensureDirectoryExists(dirname($root.'/'.$objectKey));
    File::put($root.'/'.$objectKey, $contents);
    $store = FileStore::query()->create([
        'name' => 'Dusk media store '.Str::uuid(),
        'provider' => 'local',
        'settings' => ['root' => $root],
    ]);
    $asset = MediaAsset::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'file_store_id' => $store->id,
        'owner_type' => $category === MediaCategory::CallRecording ? 'call-recording' : 'recording',
        'owner_id' => (string) Str::uuid(),
        'category' => $category,
        'status' => MediaAssetStatus::Available,
        'object_key' => $objectKey,
        'original_filename' => $filename,
        'mime_type' => 'audio/wav',
        'byte_size' => strlen($contents),
        'sha256' => hash('sha256', $contents),
    ]);

    $test->mediaFixtureRoots[] = $root;
    $test->mediaFixtureAssets[] = $asset;
    $test->mediaFixtureStores[] = $store;
    $test->mediaFixtureUsers[] = $user;
    $test->mediaFixtureTenants[] = $tenant;

    return [$user, $asset];
}

/**
 * Attach one tenant-scoped media permission to a browser-test user.
 */
function grantDuskMediaPermission(User $user, Tenant $tenant, string $permissionName, object $test): void
{
    $permission = Permission::query()->firstOrCreate(
        ['name' => $permissionName],
        ['module' => Str::before($permissionName, '.'), 'description' => 'Dusk permission for '.$permissionName],
    );
    $group = Group::factory()->forTenant($tenant->id)->create();
    $group->permissions()->sync([$permission->id]);
    $user->groups()->attach($group);
    $test->mediaFixtureGroups[] = $group;
}

/**
 * Read a same-origin media response because Dusk does not expose streamed response metadata.
 *
 * @return array{0: int, 1: string|null, 2: int, 3: string|null}
 */
function readDuskMediaResponse(Browser $browser, string $path): array
{
    $responses = $browser->script(<<<JS
        const request = new XMLHttpRequest();
        request.open('GET', '{$path}', false);
        request.send();

        return [
            request.status,
            request.getResponseHeader('Content-Type'),
            request.responseText.length,
            request.getResponseHeader('Content-Disposition'),
        ];
        JS);

    return $responses[0];
}
