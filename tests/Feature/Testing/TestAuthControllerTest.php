<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use App\Models\Admin;
use App\Models\User;

afterEach(function (): void {
    app()->detectEnvironment(fn (): string => 'testing');
});

it('authenticates admin via test bridge when environment is testing', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);

    $response = $this->get("/_testing/login/admin/{$admin->id}");

    $response->assertRedirect('/panel');
    $this->assertAuthenticatedAs($admin, 'admin');
});

it('authenticates tenant user via test bridge when environment is testing', function (): void {
    $user = User::factory()->create(['enabled' => true]);

    $response = $this->get("/_testing/login/web/{$user->id}");

    $response->assertRedirect('/panel');
    $this->assertAuthenticatedAs($user, 'web');
});

it('returns 404 if environment is not testing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $response = $this->get('/_testing/login/admin/1');

    $response->assertNotFound();
});
