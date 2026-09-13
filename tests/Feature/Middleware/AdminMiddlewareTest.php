<?php

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('redirects guests to admin login', function () {
    get(route('panel.dashboard'))
        ->assertRedirect(route('panel.login'));
});

it('allows authenticated admin through', function () {
    $admin = grantAdminPermissions();

    actingAs($admin, 'admin')
        ->get(route('panel.dashboard'))
        ->assertOk();
});
