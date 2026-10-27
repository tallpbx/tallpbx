<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);

    $superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    if ($superAdminGroup !== null) {
        $this->admin->groups()->attach($superAdminGroup->id);
    }

    $this->tenant = Tenant::factory()->create();
    $this->tenantUser = User::factory()->create();
    $this->tenantUser->tenants()->attach($this->tenant->id, ['primary' => true, 'role' => 'admin']);
});

it('renders the session expired modal dialog on admin panel pages', function (): void {
    $response = $this->actingAs($this->admin, 'admin')
        ->get('/panel/git-update');

    $response->assertOk()
        ->assertSee('id="session-expired-modal-title"', false)
        ->assertSee('role="dialog"', false)
        ->assertSee(__('admin.session_expired_title'))
        ->assertSee(__('admin.session_expired_message'))
        ->assertSee(__('admin.session_expired_action'));
});

it('renders the session expired modal dialog on tenant portal pages', function (): void {
    $response = $this->actingAs($this->tenantUser, 'web')
        ->get('/panel/dashboard');

    $response->assertOk()
        ->assertSee('id="session-expired-modal-title"', false)
        ->assertSee('role="dialog"', false)
        ->assertSee(__('admin.session_expired_title'))
        ->assertSee(__('admin.session_expired_message'))
        ->assertSee(__('admin.session_expired_action'));
});

it('contains zero occurrences of native browser wire:confirm across all blade templates', function (): void {
    $bladeFiles = [];
    foreach ([resource_path('views'), base_path('app-modules')] as $directory) {
        if (! File::isDirectory($directory)) {
            continue;
        }
        foreach (File::allFiles($directory) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $bladeFiles[] = $file->getRealPath();
            }
        }
    }

    expect($bladeFiles)->not->toBeEmpty();

    $violatingFiles = [];
    foreach ($bladeFiles as $file) {
        $contents = file_get_contents($file);
        if ($contents !== false && str_contains($contents, 'wire:confirm')) {
            $violatingFiles[] = str_replace(base_path().'/', '', $file);
        }
    }

    expect($violatingFiles)->toBeEmpty(
        'The following templates still use native browser wire:confirm instead of <x-confirmation-modal>: '.implode(', ', $violatingFiles)
    );
});
