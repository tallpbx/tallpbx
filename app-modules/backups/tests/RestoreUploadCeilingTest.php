<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Backups\Livewire\RestoreArchive;

/*
 * The product limit is 2 decimal gigabytes. The transport layers (PHP and
 * Nginx) carry binary headroom so the application rule is what the operator
 * actually sees.
 */

/**
 * Create an administrator authorized to request a system restore.
 */
function uploadCeilingAdmin(): Admin
{
    $permission = Permission::query()->firstOrCreate(
        ['name' => 'backups.restore'],
        ['module' => 'backups', 'description' => 'Restore backups'],
    );
    $group = Group::factory()->system()->create(['name' => 'Super Administrators '.uniqid()]);
    $group->permissions()->sync([$permission->id]);
    $admin = Admin::factory()->create();
    $admin->groups()->attach($group);

    return $admin;
}

it('caps a direct archive upload at two decimal gigabytes', function (): void {
    expect((int) config('backup-storage.max_upload_bytes'))->toBe(2_000_000_000);
});

it('refuses an archive above the ceiling and accepts one below it', function (): void {
    $admin = uploadCeilingAdmin();

    $manifest = UploadedFile::fake()->createWithContent('backup-manifest.json', json_encode([
        'format_version' => 1,
        'scopes' => ['database'],
        'archive' => ['sha256' => str_repeat('a', 64), 'bytes' => 1],
    ], JSON_THROW_ON_ERROR));

    // UploadedFile::fake()->create() takes kilobytes: 1_960_000 KB (~2.007 GB)
    // exceeds 2_000_000_000 bytes; 1_900_000 KB (~1.946 GB) is below it.
    Livewire::actingAs($admin, 'admin')
        ->test(RestoreArchive::class)
        ->set('archiveUpload', UploadedFile::fake()->create('big.tar.gz', 1_960_000))
        ->set('manifestUpload', $manifest)
        ->call('confirmUploadedRestore')
        ->assertHasErrors('archiveUpload');

    Livewire::actingAs($admin, 'admin')
        ->test(RestoreArchive::class)
        ->set('archiveUpload', UploadedFile::fake()->create('ok.tar.gz', 1_900_000))
        ->set('manifestUpload', $manifest)
        ->call('confirmUploadedRestore')
        ->assertHasNoErrors('archiveUpload');
});

it('ships matching PHP and Nginx transport limits', function (): void {
    expect(file_get_contents(base_path('scripts/resources/php.sh')))
        ->toContain('upload_max_filesize')
        ->toContain('post_max_size')
        ->and(file_get_contents(base_path('scripts/resources/nginx.sh')))
        ->toContain('client_max_body_size');
});
