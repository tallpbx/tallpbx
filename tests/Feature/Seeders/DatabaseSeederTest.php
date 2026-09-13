<?php

use App\Models\Admin;
use App\Models\Group;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\DialplanContext;
use App\Services\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Cache;
use Modules\Extensions\Models\Extension;
use Modules\Gateways\Models\Gateway;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\SipAccounts\Models\SipAccount;
use Modules\SipTrunks\Models\SipTrunk;
use Modules\Voicemails\Models\Voicemail;

beforeEach(function (): void {
    config(['freeswitch.demo_mode' => true]);
});

it('seeds production defaults without demo data', function (): void {
    config(['freeswitch.demo_mode' => false]);

    $this->seed(DatabaseSeeder::class);

    expect(Admin::query()->exists())->toBeFalse()
        ->and(Tenant::where('slug', 'default')->exists())->toBeTrue()
        ->and(Tenant::count())->toBe(1)
        ->and(Tenant::where('purpose', Tenant::PURPOSE_CUSTOMER)->count())->toBe(0)
        ->and(User::count())->toBe(0)
        ->and(Extension::withoutGlobalScope('tenant')->count())->toBe(0)
        ->and(SipAccount::withoutGlobalScope('tenant')->count())->toBe(0)
        ->and(InboundRoute::withoutGlobalScope('tenant')->count())->toBe(0)
        ->and(Voicemail::withoutGlobalScope('tenant')->count())->toBe(0)
        ->and(SipTrunk::withoutGlobalScope('tenant')->count())->toBe(0)
        ->and(Gateway::withoutGlobalScope('tenant')->count())->toBe(0);
});

it('creates default user and tenant', function () {
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'user@tallpbx.org')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Demo User');
    expect($user->password)->not->toBeNull();

    $tenant = Tenant::where('slug', 'tallpbx')->first();
    expect($tenant)->not->toBeNull();
    expect($tenant->name)->toBe('TallPBX');
    expect($tenant->purpose)->toBe(Tenant::PURPOSE_CUSTOMER);
    expect($tenant->primary_user_id)->toBe($user->id);
});

it('creates the default shared-resource tenant', function () {
    $this->seed(DatabaseSeeder::class);

    $tenant = Tenant::where('slug', 'default')->first();

    expect($tenant)->not->toBeNull();
    expect($tenant->name)->toBe('Default');
    expect($tenant->purpose)->toBe(Tenant::PURPOSE_DEFAULT);
    expect($tenant->primary_user_id)->toBeNull();
});

it('attaches user to tenant as admin', function () {
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'user@tallpbx.org')->first();
    $tenant = Tenant::where('slug', 'tallpbx')->first();

    expect($tenant->users()->where('user_id', $user->id)->exists())->toBeTrue();
    expect($tenant->users()->find($user->id)->pivot->role)->toBe('admin');
});

it('grants the default tenant admin tenant-scoped PBX permissions', function () {
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'user@tallpbx.org')->first();
    $tenant = Tenant::where('slug', 'tallpbx')->first();
    app(TenantContext::class)->switch($tenant);

    $group = Group::where('tenant_id', $tenant->id)
        ->where('name', 'Tenant Administrators')
        ->first();

    expect($group)->not->toBeNull()
        ->and($group->permissions()->where('name', 'extensions.view')->exists())->toBeTrue()
        ->and($group->permissions()->where('name', 'admin.users.view')->exists())->toBeFalse()
        ->and($user->groups()->whereKey($group->id)->exists())->toBeTrue()
        ->and($user->hasPermission('extensions.view'))->toBeTrue()
        ->and($user->hasPermission('admin.users.view'))->toBeFalse()
        ->and($group->permissions()->count())->toBe(Permission::where('name', 'not like', 'admin.%')->count());
});

it('seeds pending initial administrator setup without a default account', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Admin::query()->exists())->toBeFalse()
        ->and(Setting::system()->where('key', 'initial_admin.setup')->value('value'))->toContain('pending');
});

it('syncs modules and grants the super administrators group registered permissions', function () {
    $this->seed(DatabaseSeeder::class);

    $superAdminGroup = Group::query()->where('name', 'Super Administrators')->firstOrFail();

    expect(Module::count())->toBeGreaterThanOrEqual(15)
        ->and($superAdminGroup->permissions()->where('name', 'admin.dashboard.view')->exists())->toBeTrue()
        ->and($superAdminGroup->permissions()->where('name', 'extensions.view')->exists())->toBeTrue();
});

it('creates seeder data in a single transaction', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'user@tallpbx.org')->exists())->toBeTrue();
    expect(Admin::query()->exists())->toBeFalse();
    expect(Tenant::where('slug', 'tallpbx')->exists())->toBeTrue();
});

it('is safe to run repeatedly', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $tenant = Tenant::where('slug', 'tallpbx')->firstOrFail();

    expect(User::where('email', 'user@tallpbx.org')->count())->toBe(1)
        ->and(Tenant::where('slug', 'tallpbx')->count())->toBe(1)
        ->and($tenant->users()->where('user_id', User::where('email', 'user@tallpbx.org')->value('id'))->count())->toBe(1)
        ->and(Extension::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->whereIn('extension_number', ['1000', '1001'])->count())->toBe(2)
        ->and(SipAccount::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->whereIn('auth_username', ['1000', '1001'])->count())->toBe(2);
});

it('seeds callable PBX defaults for the customer tenant', function () {
    config([
        'freeswitch.server' => 'http://192.0.2.10',
        'freeswitch.default_sip_realm' => '192.0.2.10',
        'freeswitch.default_sip_password' => 'GeneratedSecret123!',
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.dialplan_cache_ttl' => 0,
    ]);

    $this->seed(DatabaseSeeder::class);

    $tenant = Tenant::where('slug', 'tallpbx')->firstOrFail();
    $domain = TenantDomain::where('tenant_id', $tenant->id)->where('domain', '192.0.2.10')->first();
    $dialplanContext = app(DialplanContext::class);

    expect($domain)->not->toBeNull()
        ->and(Extension::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('extension_number', '1000')->exists())->toBeTrue()
        ->and(Extension::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('extension_number', '1001')->exists())->toBeTrue()
        ->and(SipAccount::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('auth_username', '1000')->where('tenant_domain_id', $domain->id)->exists())->toBeTrue()
        ->and(InboundRoute::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('destination_number', '15551230000')->exists())->toBeTrue();

    $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'user',
        'domain' => '192.0.2.10',
        'key_value' => '1000',
        'sip_auth_username' => '1000',
    ]))
        ->assertOk()
        ->assertSee('<user id="1000">', false)
        ->assertSee('GeneratedSecret123!', false);

    $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $dialplanContext->internal((string) $tenant->id),
        'Caller-Destination-Number' => '1001',
        'Caller-Caller-ID-Number' => '1000',
    ]))
        ->assertOk()
        ->assertSee('<extension name="local_extension">', false)
        ->assertDontSee('application="limit" data="hiredis default pbx:${domain_name}:local_extension:active 100000"', false)
        ->assertDontSee('application="hiredis_raw" data="default set pbx:mod_hiredis:last_call:${uuid} ${caller_id_number}-&gt;${destination_number}"', false)
        ->assertSee('data="${sofia_contact($1@${domain_name})}"', false);

    config([
        'freeswitch.xml_handler.hiredis_limit_enabled' => true,
        'freeswitch.xml_handler.hiredis_marker_enabled' => true,
    ]);
    Cache::flush();

    $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $dialplanContext->internal((string) $tenant->id),
        'Caller-Destination-Number' => '1001',
        'Caller-Caller-ID-Number' => '1000',
    ]))
        ->assertOk()
        ->assertSee('application="limit" data="hiredis default pbx:${domain_name}:local_extension:active 100000"', false)
        ->assertSee('application="hiredis_raw" data="default set pbx:mod_hiredis:last_call:${uuid} ${caller_id_number}-&gt;${destination_number}"', false);
});
