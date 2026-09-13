<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Admin\Livewire\NotificationsList;

beforeEach(function (): void {
    $admin = Admin::factory()->create();
    $group = Group::factory()->system()->create();
    $group->permissions()->attach(Permission::factory()->create([
        'name' => 'admin.notifications.view',
        'module' => 'admin',
    ]));
    $admin->groups()->attach($group);
    $this->admin = $admin;
});

it('shows only the current admins own notifications', function (): void {
    $other = Admin::factory()->create();
    DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TestNotification',
        'notifiable_type' => Admin::class,
        'notifiable_id' => $this->admin->id,
        'data' => '[]',
    ]);
    DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TestNotification',
        'notifiable_type' => Admin::class,
        'notifiable_id' => $other->id,
        'data' => '[]',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(NotificationsList::class)
        ->assertSet('unreadCount', 1);
});

it('scopes tenant-user notifications to their own user identity', function (): void {
    $tenant = Tenant::factory()->create();
    $user = tenantUser($tenant);

    DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => '[]',
    ]);
    DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TestNotification',
        'notifiable_type' => Admin::class,
        'notifiable_id' => $this->admin->id,
        'data' => '[]',
    ]);

    Livewire::actingAs($user, 'web')
        ->test(NotificationsList::class)
        ->assertSet('unreadCount', 1);

    app(TenantManager::class)->setTenantId(null);
});

it('deletes an owned notification with the delete permission', function (): void {
    $group = Group::factory()->system()->create();
    $group->permissions()->attach(Permission::factory()->create([
        'name' => 'admin.notifications.delete',
        'module' => 'admin',
    ]));
    $this->admin->groups()->attach($group);

    $notification = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TestNotification',
        'notifiable_type' => Admin::class,
        'notifiable_id' => $this->admin->id,
        'data' => '[]',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(NotificationsList::class)
        ->call('deleteNotification', $notification->id)
        ->assertSet('actionMessage', 'Notification deleted.');

    $this->assertModelMissing($notification);
});

it('denies deletion without the delete permission', function (): void {
    $notification = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TestNotification',
        'notifiable_type' => Admin::class,
        'notifiable_id' => $this->admin->id,
        'data' => '[]',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(NotificationsList::class)
        ->call('deleteNotification', $notification->id)
        ->assertSet('actionError', 'You do not have permission to delete notifications.');

    $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
});

it('cannot delete another actors notification', function (): void {
    $other = Admin::factory()->create();
    $group = Group::factory()->system()->create();
    $group->permissions()->attach(Permission::factory()->create([
        'name' => 'admin.notifications.delete',
        'module' => 'admin',
    ]));
    $this->admin->groups()->attach($group);

    $foreign = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TestNotification',
        'notifiable_type' => Admin::class,
        'notifiable_id' => $other->id,
        'data' => '[]',
    ]);

    $this->expectException(ModelNotFoundException::class);

    Livewire::actingAs($this->admin, 'admin')
        ->test(NotificationsList::class)
        ->call('deleteNotification', $foreign->id);

    $this->assertDatabaseHas('notifications', ['id' => $foreign->id]);
});

it('cannot mark another actors notification as read', function (): void {
    $other = Admin::factory()->create();
    $foreign = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TestNotification',
        'notifiable_type' => Admin::class,
        'notifiable_id' => $other->id,
        'data' => '[]',
    ]);

    $this->expectException(ModelNotFoundException::class);

    Livewire::actingAs($this->admin, 'admin')
        ->test(NotificationsList::class)
        ->call('markAsRead', $foreign->id);

    $this->assertDatabaseHas('notifications', ['id' => $foreign->id, 'read_at' => null]);
});

it('marks only the current actors notifications as read', function (): void {
    $other = Admin::factory()->create();
    DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TestNotification',
        'notifiable_type' => Admin::class,
        'notifiable_id' => $this->admin->id,
        'data' => '[]',
    ]);
    $foreign = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TestNotification',
        'notifiable_type' => Admin::class,
        'notifiable_id' => $other->id,
        'data' => '[]',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(NotificationsList::class)
        ->call('markAllAsRead')
        ->assertSet('unreadCount', 0);

    $this->assertDatabaseHas('notifications', ['id' => $foreign->id, 'read_at' => null]);
});
