<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

it('delivers a database notification to an admin through the morph map', function (): void {
    $admin = Admin::factory()->create();

    $admin->notify(new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toArray(object $notifiable): array
        {
            return ['ok' => true];
        }
    });

    expect(DB::table('notifications')
        ->where('notifiable_type', 'admin')
        ->where('notifiable_id', $admin->id)
        ->exists())->toBeTrue();
});

it('delivers a database notification to a tenant user through the morph map', function (): void {
    $tenant = Tenant::factory()->create();
    $user = tenantUser($tenant);

    $user->notify(new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toArray(object $notifiable): array
        {
            return ['ok' => true];
        }
    });

    expect(DB::table('notifications')
        ->where('notifiable_type', 'user')
        ->where('notifiable_id', $user->id)
        ->exists())->toBeTrue();
});
