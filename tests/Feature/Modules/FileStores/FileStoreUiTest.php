<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Services\PermissionService;
use Livewire\Livewire;
use Modules\Backups\Models\Backup;
use Modules\FileStores\Livewire\FileStoresEdit;
use Modules\FileStores\Livewire\FileStoresList;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Models\MediaAsset;
use Modules\FileStores\Services\FileStoreServiceInterface;
use Modules\FileStores\Services\MediaArchiveDestinationServiceInterface;
use Modules\FileStores\Services\MediaStorageServiceInterface;

beforeEach(function (): void {
    $this->admin = Admin::factory()->create(['enabled' => true]);

    $permissions = [
        'file-stores.view',
        'file-stores.create',
        'file-stores.update',
        'file-stores.delete',
        'file-stores.test-connection',
    ];

    $permissionService = app(PermissionService::class);
    $permissionService->register('file-stores', $permissions);
    $permissionService->syncToDatabase();

    $group = Group::factory()->system()->create();
    $group->permissions()->attach(Permission::whereIn('name', $permissions)->pluck('id'));
    $this->admin->groups()->attach($group);
});

it('lists system file store profiles for an authorized admin', function (): void {
    FileStore::create([
        'name' => 'Remote archives',
        'provider' => 's3',
        'settings' => ['bucket' => 'tallpbx-test', 'region' => 'us-west-2', 'access_key' => 'key', 'secret' => 'secret'],
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FileStoresList::class)
        ->assertOk()
        ->assertSee('File Stores')
        ->assertSee('Remote archives')
        ->assertSee('S3');
});

it('keeps the add file store action compact and its label on one line', function (): void {
    $view = file_get_contents(base_path('app-modules/file-stores/resources/views/file-stores-list.blade.php'));

    expect($view)
        ->toContain('class="btn btn-primary btn-sm whitespace-nowrap"')
        ->toContain('<x-heroicon-o-plus class="w-4 h-4" />')
        ->toContain('Add file store');
});

it('explains durable media, temporary spool files, and backup storage paths', function (): void {
    $view = file_get_contents(base_path('app-modules/file-stores/resources/views/file-stores-list.blade.php'));

    expect($view)
        ->toContain("config('media-storage.store_root')")
        ->toContain("config('backup-storage.root')")
        ->toContain('temporary staging area')
        ->toContain('Completed media TallPBX keeps');
});

it('creates a local file store profile from the edit form', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(FileStoresEdit::class)
        ->set('name', 'Local archives')
        ->set('provider', 'local')
        ->set('settings.root', storage_path('app/backups'))
        ->call('save')
        ->assertRedirect(route('panel.file-stores.index'));

    expect(FileStore::query()->where('name', 'Local archives')->value('provider'))->toBe('local');
});

it('shows the deletion-error presentation previews when a backup references a file store', function (): void {
    $fileStore = FileStore::query()->create([
        'name' => 'Referenced SFTP profile',
        'provider' => 'sftp',
        'settings' => ['host' => 'sftp.example.test', 'username' => 'backup', 'password' => 'secret'],
    ]);
    Backup::factory()->create(['name' => 'Referenced backup', 'file_store_id' => $fileStore->id]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FileStoresList::class)
        ->call('deleteFileStore', $fileStore->id)
        ->assertSet('deleteError', 'Cannot delete “Referenced SFTP profile” because it is used by backup “Referenced backup”. Delete or move that backup first.');
});

it('uses the shared confirmation modal for file store deletion', function (): void {
    $fileStore = FileStore::query()->create([
        'name' => 'Delete confirmation store',
        'provider' => 'sftp',
        'settings' => ['host' => 'sftp.example.test', 'username' => 'backup', 'password' => 'secret'],
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FileStoresList::class)
        ->call('confirmFileStoreDeletion', $fileStore->id)
        ->assertSee('Delete File Store?')
        ->assertSeeHtml('aria-modal="true"');
});

it('lets a system admin select a readable media archive destination', function (): void {
    $destination = FileStore::query()->create([
        'name' => 'Media archive',
        'provider' => 's3',
        'settings' => ['bucket' => 'tallpbx-media', 'region' => 'us-west-2', 'access_key' => 'key', 'secret' => 'secret'],
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FileStoresList::class)
        ->assertSee('Media archive destination')
        ->set('archiveFileStoreId', $destination->id)
        ->call('updateArchiveDestination')
        ->assertSet('archiveFileStoreId', $destination->id);

    expect(app(MediaArchiveDestinationServiceInterface::class)->current()->is($destination))->toBeTrue();
});

it('shows a safe page error when a connection test fails', function (): void {
    $fileStore = FileStore::query()->create([
        'name' => 'Unavailable SFTP',
        'provider' => 'sftp',
        'settings' => ['host' => 'sftp.example.test', 'username' => 'backup', 'password' => 'secret'],
    ]);

    $service = Mockery::mock(FileStoreServiceInterface::class);
    $service->shouldReceive('testConnection')->once()->andThrow(new RuntimeException('Connection refused: secret host detail'));
    app()->instance(FileStoreServiceInterface::class, $service);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FileStoresList::class)
        ->call('testConnection', $fileStore->id)
        ->assertSet('operationalMessageType', 'error')
        ->assertSet('operationalMessage', 'Connection could not be verified. Check the server address, credentials, and network access.');
});

it('shows a failed remote media archive and queues its retry from the file stores page', function (): void {
    $asset = MediaAsset::factory()->create([
        'status' => 'failed',
        'sync_attempts' => 8,
        'last_error' => 'Connection refused: internal host detail',
    ]);
    $mediaStorage = Mockery::mock(MediaStorageServiceInterface::class);
    $mediaStorage->shouldReceive('retryArchive')->once()->with($asset->id);
    app()->instance(MediaStorageServiceInterface::class, $mediaStorage);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FileStoresList::class)
        ->assertSee('Media archive transfers')
        ->assertSee($asset->original_filename)
        ->call('retryMediaArchive', $asset->id)
        ->assertSet('operationalMessageType', 'info')
        ->assertSet('operationalMessage', 'Media archive retry has been queued.');
});
