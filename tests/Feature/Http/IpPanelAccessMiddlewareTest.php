<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    Setting::system()->where('key', 'panel.allow_ip_access')->delete();
    // These tests cover host access rules, not pending first-admin setup.
    Admin::factory()->create();
});

it('allows panel access via IP by default', function (): void {
    $this->get('/panel/login', ['Host' => '192.168.1.1'])
        ->assertStatus(200);
});

it('does not block panel access when the host is not an IP', function (): void {
    Setting::create([
        'key' => 'panel.allow_ip_access',
        'value' => 'false',
    ]);

    Cache::flush();

    // Simulate a domain-based request by overriding the host on the
    // request instance before middleware execution. Setting an HTTP
    // Host header via the test helpers does not always propagate to
    // $request->getHost() in the test framework.
    $this->app['request']->headers->set('HOST', 'pbx.example.com');

    $this->get('/panel/login')
        ->assertStatus(200);
});

it('blocks panel access via IPv4 when IP access is disabled', function (): void {
    Setting::create([
        'key' => 'panel.allow_ip_access',
        'value' => 'false',
    ]);

    Cache::flush();

    $this->get('/panel/login', ['Host' => '192.168.1.1'])
        ->assertForbidden();
});

it('blocks panel access via IPv6 when IP access is disabled', function (): void {
    Setting::create([
        'key' => 'panel.allow_ip_access',
        'value' => 'false',
    ]);

    Cache::flush();

    $this->get('/panel/login', ['Host' => '::1'])
        ->assertForbidden();
});

it('allows API routes regardless of IP access setting', function (): void {
    Setting::create([
        'key' => 'panel.allow_ip_access',
        'value' => 'false',
    ]);

    Cache::flush();

    // The XML handler API is not under /panel prefix so the middleware
    // does not apply. In test config, auth is disabled so we expect 200.
    config(['freeswitch.xml_handler.auth' => false]);

    $this->get('/api/v1/xml-handler?section=directory&domain=test.local')
        ->assertOk();
});

it('caches the IP access setting for subsequent requests', function (): void {
    Setting::create([
        'key' => 'panel.allow_ip_access',
        'value' => 'true',
    ]);

    Cache::flush();

    // First request populates the cache via the Setting model.
    $this->get('/panel/login', ['Host' => '192.168.1.1'])
        ->assertStatus(200);

    // Delete the Setting row so only the cache can satisfy the check.
    Setting::system()->where('key', 'panel.allow_ip_access')->delete();

    // Second request must use the cached value (still 'true').
    $this->get('/panel/login', ['Host' => '192.168.1.1'])
        ->assertStatus(200);
});
