<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Admin\Livewire\NotificationsList;

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->actingAs($this->admin, 'admin');
});

/**
 * Helper: create a database notification for the admin.
 */
function createNotification(Admin $admin, array $data = [], bool $read = false): DatabaseNotification
{
    return DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\SystemAlert',
        'notifiable_type' => Admin::class,
        'notifiable_id' => $admin->id,
        'data' => array_merge([
            'title' => 'Test Notification',
            'message' => 'This is a test notification.',
        ], $data),
        'read_at' => $read ? now() : null,
    ]);
}

it('renders the notifications list', function () {
    Livewire::test(NotificationsList::class)
        ->assertOk()
        ->assertSee('Notifications');
});

it('lists notifications for the current admin', function () {
    createNotification($this->admin, ['title' => 'Welcome!'], read: false);

    Livewire::test(NotificationsList::class)
        ->assertSee('Welcome!');
});

it('marks a notification as read', function () {
    $notification = createNotification($this->admin, ['title' => 'Alert!'], read: false);

    Livewire::test(NotificationsList::class)
        ->call('markAsRead', $notification->id);

    $this->assertNotNull($notification->fresh()->read_at);
});

it('marks all notifications as read', function () {
    $n1 = createNotification($this->admin, ['title' => 'First'], read: false);
    $n2 = createNotification($this->admin, ['title' => 'Second'], read: false);

    Livewire::test(NotificationsList::class)
        ->call('markAllAsRead');

    $this->assertNotNull($n1->fresh()->read_at);
    $this->assertNotNull($n2->fresh()->read_at);
});

it('deletes a notification', function () {
    // Deleting requires the notifications.delete permission for admins.
    $group = Group::factory()->system()->create();
    $group->permissions()->attach(Permission::factory()->create([
        'name' => 'admin.notifications.delete',
        'module' => 'admin',
    ]));
    $this->admin->groups()->attach($group);

    $notification = createNotification($this->admin, ['title' => 'Delete me'], read: false);

    Livewire::test(NotificationsList::class)
        ->call('deleteNotification', $notification->id);

    $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
});
