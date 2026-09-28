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
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Models\MediaAsset;
use Modules\FileStores\Services\MediaArchiveDestinationServiceInterface;

// Create an administrator and two uniquely named destinations for each test so
// the browser can verify both local-only and remote archive choices safely.
beforeEach(function (): void {
    // Every running server has the reserved "Local storage - media"
    // destination once the File Stores page has been opened; create it up
    // front so the archive destination selector offers it deterministically
    // on its first load against the refreshed test database.
    app(MediaArchiveDestinationServiceInterface::class)->set(
        FileStore::query()->firstOrCreate(
            ['name' => 'Local storage - media'],
            [
                'provider' => 'local',
                'settings' => ['root' => config('media-storage.store_root')],
            ],
        ),
    );

    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->group = Group::factory()->system()->create();

    $permissions = collect(['file-stores.view', 'file-stores.update', 'admin.notifications.view'])
        ->map(fn (string $name): Permission => Permission::query()->firstOrCreate(
            ['name' => $name],
            ['module' => 'file-stores', 'description' => 'Browser test permission for '.$name],
        ));
    $this->group->permissions()->sync($permissions->pluck('id'));
    $this->admin->groups()->attach($this->group);
    $this->destinationPrefix = 'Browser media '.Str::uuid();
    $this->mediaFixtureRoots = [];
    $this->mediaFixtureAssets = [];
    $this->mediaFixtureStores = [];
    $this->mediaFixtureUsers = [];
    $this->mediaFixtureGroups = [];
    $this->mediaFixtureTenants = [];

    $this->localDestination = FileStore::query()->create([
        'name' => $this->destinationPrefix.' local',
        'provider' => 'local',
        'settings' => ['root' => storage_path('framework/browser-media-archive')],
    ]);
    $this->remoteDestination = FileStore::query()->create([
        'name' => $this->destinationPrefix.' remote',
        'provider' => 'sftp',
        'settings' => [
            'host' => 'archive.example.test',
            'username' => 'browser',
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
    // Authenticate through the fast session bridge, then exercise the same
    // controls an administrator uses in the File Stores page.
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/file-stores');
    $page->assertSee('Media archive destination')
        ->assertSee($this->destinationPrefix.' remote')
        // The reserved destination is offered inside a <select>, and Playwright
        // does not report <option> elements as visible, so verify the offered
        // choice through the DOM instead of assertSee().
        ->assertScript(<<<'JS'
            (() => {
                const select = document.querySelector('#media-archive-file-store');
                if (!select) return false;
                return [...select.options].some((option) => option.textContent.includes('Local storage - media'));
            })()
            JS)
        ->assertPresent('#media-archive-file-store')
        ->select('#media-archive-file-store', $this->remoteDestination->id)
        ->press('Save archive destination')
        // The status line only appears with the newly saved destination id once
        // Livewire re-renders, replacing Dusk's selector-based waitFor().
        ->assertPresent("#archive-destination-status[data-archive-destination-id=\"{$this->remoteDestination->id}\"]");

    expect(app(MediaArchiveDestinationServiceInterface::class)->current()->is($this->remoteDestination))->toBeTrue();
});

it('streams local recording media to an authorized owning tenant user', function (): void {
    [$user, $asset] = createMediaFixture($this, MediaCategory::Recording, 'local-announcement.wav', 'local announcement bytes');

    $this->loginAs($user, 'web');

    $page = visit('/panel/dashboard');

    expect(readMediaResponse($page, "/panel/media-assets/{$asset->id}/stream"))
        ->toBe([200, 'audio/wav', 24, 'inline; filename=local-announcement.wav']);
});

it('streams and downloads an archived call recording from a second local store', function (): void {
    [$user, $asset] = createMediaFixture($this, MediaCategory::CallRecording, 'archived-call.wav', 'archived call recording bytes', true);

    $this->loginAs($user, 'web');

    $page = visit('/panel/dashboard');

    expect(readMediaResponse($page, "/panel/media-assets/{$asset->id}/stream"))
        ->toBe([200, 'audio/wav', 29, 'inline; filename=archived-call.wav'])
        ->and(readMediaResponse($page, "/panel/media-assets/{$asset->id}/download"))
        ->toBe([200, 'audio/wav', 29, 'attachment; filename=archived-call.wav']);
});

it('hides foreign and pending media while distinguishing missing permission', function (): void {
    [$owner, $asset] = createMediaFixture($this, MediaCategory::Recording, 'private-recording.wav', 'private recording bytes');
    $foreignTenant = Tenant::factory()->create();
    $foreignUser = User::factory()->create();
    $foreignUser->tenants()->attach($foreignTenant, ['role' => 'admin', 'primary' => true]);
    grantMediaPermission($foreignUser, $foreignTenant, 'recordings.view', $this);
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

    // The browser session is shared by the in-process test server, so each
    // identity below is authenticated and verified one at a time.

    // A tenant user from another tenant cannot see the media at all.
    $this->loginAs($foreignUser, 'web');
    $foreignPage = visit('/panel/dashboard');
    expect(readMediaResponse($foreignPage, "/panel/media-assets/{$asset->id}/stream")[0])->toBe(404)
        ->and(readMediaResponse($foreignPage, "/panel/media-assets/{$asset->id}/download")[0])->toBe(404);

    // The owning tenant user cannot stream an asset that is still pending.
    $this->loginAs($owner, 'web');
    $ownerPage = visit('/panel/dashboard');
    expect(readMediaResponse($ownerPage, "/panel/media-assets/{$pendingAsset->id}/stream")[0])->toBe(404);

    // A tenant user without the media permission is told the request is forbidden.
    $this->loginAs($unprivilegedUser, 'web');
    $unprivilegedPage = visit('/panel/dashboard');
    expect(readMediaResponse($unprivilegedPage, "/panel/media-assets/{$asset->id}/stream")[0])->toBe(403);
});

it('shows safe archive failure details without exposing storage internals', function (): void {
    DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'browser-media-archive-failure',
        'notifiable_type' => $this->admin::class,
        'notifiable_id' => $this->admin->id,
        'data' => [
            'title' => 'Media archive transfer failed',
            'message' => 'A media archive could not be transferred. Its local spool has been retained for recovery.',
            'media_asset_id' => (string) Str::uuid(),
            'category' => 'call-recording',
            'original_filename' => 'customer-call.wav',
            'staging_path' => '/private/browser-spool-marker.wav',
            'object_key' => 'private-object-key.wav',
            'last_error' => 'provider-secret-error',
        ],
    ]);

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/notifications');
    $page->assertSee('Media archive transfer failed')
        ->assertSee('call-recording')
        ->assertSee('customer-call.wav')
        ->assertDontSee('browser-spool-marker')
        ->assertDontSee('private-object-key')
        ->assertDontSee('provider-secret-error');
});

/**
 * Create a tenant user, local file store, and available asset for one browser media scenario.
 *
 * @return array{0: User, 1: MediaAsset}
 */
function createMediaFixture(object $test, MediaCategory $category, string $filename, string $contents, bool $archive = false): array
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'admin', 'primary' => true]);
    grantMediaPermission($user, $tenant, $category === MediaCategory::CallRecording ? 'call-recordings.view' : 'recordings.view', $test);
    $root = storage_path('framework/browser-media-'.Str::uuid());
    $objectKey = ($archive ? 'archive/' : 'runtime/').$filename;
    File::ensureDirectoryExists(dirname($root.'/'.$objectKey));
    File::put($root.'/'.$objectKey, $contents);
    $store = FileStore::query()->create([
        'name' => 'Browser media store '.Str::uuid(),
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
function grantMediaPermission(User $user, Tenant $tenant, string $permissionName, object $test): void
{
    $permission = Permission::query()->firstOrCreate(
        ['name' => $permissionName],
        ['module' => Str::before($permissionName, '.'), 'description' => 'Browser test permission for '.$permissionName],
    );
    $group = Group::factory()->forTenant($tenant->id)->create();
    $group->permissions()->sync([$permission->id]);
    $user->groups()->attach($group);
    $test->mediaFixtureGroups[] = $group;
}

/**
 * Read a same-origin media response because the streamed response metadata
 * (status, headers, body length) is not exposed by page assertions.
 *
 * The script runs as an immediately-invoked expression because Playwright
 * evaluates script() content as an expression, not a multi-line statement body.
 *
 * @return array{0: int, 1: string|null, 2: int, 3: string|null}
 */
function readMediaResponse(object $page, string $path): array
{
    return $page->script(<<<JS
        (() => {
            const request = new XMLHttpRequest();
            request.open('GET', '{$path}', false);
            request.send();

            return [
                request.status,
                request.getResponseHeader('Content-Type'),
                request.responseText.length,
                request.getResponseHeader('Content-Disposition'),
            ];
        })()
        JS);
}
