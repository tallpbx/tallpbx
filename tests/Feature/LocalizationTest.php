<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('it can switch language to spanish', function (): void {
    $this->get(route('lang.switch', ['locale' => 'es']))
        ->assertRedirect();

    $this->assertEquals('es', session('locale'));
});

test('it can switch language to french', function (): void {
    $this->get(route('lang.switch', ['locale' => 'fr']))
        ->assertRedirect();

    $this->assertEquals('fr', session('locale'));
});

test('it does not switch to unsupported locales', function (): void {
    $this->get(route('lang.switch', ['locale' => 'de']))
        ->assertRedirect();

    $this->assertNotEquals('de', session('locale'));
});

test('it redirects to english on first visit', function (): void {
    $this->get('/')
        ->assertRedirect('/en');
});

test('it loads home page with locale prefix', function (): void {
    $this->get('/es')
        ->assertOk();

    $this->assertEquals('es', app()->getLocale());
});

test('it applies the locale from the session on root redirect', function (): void {
    $this->withSession(['locale' => 'es'])
        ->get('/')
        ->assertRedirect('/es');
});

test('it renders translated content on the home page', function (): void {
    $response = $this->get('/es');

    $response->assertSee('Plataforma de Telefonía Moderna')
        ->assertSee('TALL significa Tailwind CSS, Alpine.js, Laravel y Livewire');
});

test('it persists language preference for authenticated user', function (): void {
    $user = User::factory()->create(['language' => 'en']);

    $this->actingAs($user)
        ->get(route('lang.switch', ['locale' => 'es']));

    $user->refresh();

    $this->assertEquals('es', $user->language);
});

test('it persists language preference for authenticated admin', function (): void {
    $admin = Admin::factory()->create(['language' => 'en']);

    $this->actingAs($admin, 'admin')
        ->get(route('lang.switch', ['locale' => 'fr']));

    $admin->refresh();

    $this->assertEquals('fr', $admin->language);
});

test('it renders translated content in the admin panel', function (): void {
    $admin = grantAdminPermissions();

    $this->withSession(['locale' => 'es'])
        ->actingAs($admin, 'admin')
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertSee('Usuarios')
        ->assertSee('Inquilinos');
});
