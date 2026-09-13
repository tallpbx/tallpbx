<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\DialplanContext;
use App\Services\PermissionService;
use App\Services\TenantDefaultsService;
use App\Services\TenantServiceInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Extensions\Models\Extension;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\SipAccounts\Models\SipAccount;
use Modules\Voicemails\Models\Voicemail;

/**
 * Seeds the application with default data for local development.
 *
 * When FSPBX_DEMO_MODE is true, creates:
 *   - 2 customer tenants (TallPBX, Acme Corp) with SIP extensions,
 *     voicemail, inbound routes, and tenant defaults
 *   - 4 demo users across the two tenants, one of whom belongs to
 *     both tenants (multi-tenant scenario)
 *   - Admin permission groups; the first administrator is created separately
 *
 * When FSPBX_DEMO_MODE is false, only creates the Default tenant and admin
 * groups — no demo extensions, users, or administrator account.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Create the seeder instance.
     */
    public function __construct(
        private readonly TenantServiceInterface $tenants,
        private readonly TenantDefaultsService $tenantDefaults,
        private readonly DialplanContext $dialplanContext,
    ) {}

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Artisan::call('module:sync', ['--only-local' => true]);
        app(PermissionService::class)->syncToDatabase();

        DB::transaction(function (): void {
            $this->call(DefaultSettingsSeeder::class);
            $this->call(LocalMediaFileStoreSeeder::class);
            $this->tenants->defaultTenant();

            if ($this->isDemoMode()) {
                $this->seedDemoData();
            }

            $this->call(AdminSeeder::class);
        });
    }

    /**
     * Seed the full demo dataset: 2 tenants, 4 users, extensions.
     */
    private function seedDemoData(): void
    {
        // ── Users ─────────────────────────────────────────────────────────
        // TallPBX users
        $tallAdmin = $this->createUser('user@tallpbx.org', 'Demo User');
        $tallRegular = $this->createUser('operator@tallpbx.org', 'Operator');

        // Acme Corp users
        $acmeAdmin = $this->createUser('admin@acme.test', 'Acme Admin');

        // Multi-tenant user (belongs to BOTH TallPBX and Acme Corp)
        $shared = $this->createUser('shared@example.test', 'Shared User');

        // ── Tenants ───────────────────────────────────────────────────────
        $tallpbx = $this->createTenant('TallPBX', 'tallpbx', (string) $tallAdmin->id);
        $acme = $this->createTenant('Acme Corp', 'acme', (string) $acmeAdmin->id);

        // ── Tenant memberships ────────────────────────────────────────────
        // TallPBX: 1 admin + 2 regular (one of whom is multi-tenant)
        $tallpbx->users()->syncWithoutDetaching([
            $tallAdmin->id => ['role' => 'admin'],
            $tallRegular->id => ['role' => 'user'],
            $shared->id => ['role' => 'user'],
        ]);

        // Acme Corp: 1 admin + 1 regular (the multi-tenant user)
        $acme->users()->syncWithoutDetaching([
            $acmeAdmin->id => ['role' => 'admin'],
            $shared->id => ['role' => 'user'],
        ]);

        // ── Common user group ─────────────────────────────────────────────
        $userGroup = Group::firstOrCreate(
            ['name' => 'Users', 'tenant_id' => null],
            ['description' => 'Default group for tenant users with basic access.'],
        );

        foreach ([$tallAdmin, $tallRegular, $acmeAdmin, $shared] as $u) {
            $u->groups()->syncWithoutDetaching([$userGroup->id]);
        }

        // ── PBX defaults + extensions ─────────────────────────────────────
        $this->seedPbxDefaults($tallpbx, ['1000', '1001']);
        $this->seedPbxDefaults($acme, ['2000', '2001'], 'acme.'.$this->defaultSipRealm());

        // ── Tenant administrator groups ───────────────────────────────────
        $this->seedTenantAdministratorGroup($tallpbx, $tallAdmin);
        $this->seedTenantAdministratorGroup($acme, $acmeAdmin);
    }

    /**
     * Create or retrieve a demo user with a random password (idempotent).
     */
    private function createUser(string $email, string $name): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(bin2hex(random_bytes(8))),
            ],
        );
    }

    /**
     * Create or retrieve a demo customer tenant.
     */
    private function createTenant(string $name, string $slug, string $primaryUserId): Tenant
    {
        /** @var Tenant|null $tenant */
        $tenant = Tenant::where('slug', $slug)->first();

        if ($tenant === null) {
            return $this->tenants->create([
                'name' => $name,
                'slug' => $slug,
                'primary_user_id' => $primaryUserId,
                'enabled' => true,
            ]);
        }

        $tenant->forceFill([
            'name' => $name,
            'purpose' => Tenant::PURPOSE_CUSTOMER,
            'primary_user_id' => $primaryUserId,
            'enabled' => true,
        ])->save();

        $this->tenantDefaults->provision($tenant);

        return $tenant->fresh();
    }

    /**
     * Seed PBX callable defaults (domain, extensions, SIP accounts,
     * voicemail, inbound route) for a single tenant.
     *
     * @param  array<int, string>  $extensions
     */
    private function seedPbxDefaults(Tenant $tenant, array $extensions, ?string $realm = null): void
    {
        $realm ??= $this->defaultSipRealm();
        $password = $this->defaultSipPassword();

        /** @var TenantDomain $domain */
        // Key on both domain and tenant so a re-run updates this tenant's
        // own row instead of reassigning another tenant's matching domain
        // (e.g. the Default tenant's server realm), which would collide
        // with the tenant_domains unique index on repeated seeding.
        $domain = TenantDomain::updateOrCreate(
            ['domain' => $realm, 'tenant_id' => $tenant->id],
            [
                'purpose' => 'sip_realm',
                'enabled' => true,
            ],
        );

        foreach ($extensions as $number) {
            $displayName = "Extension {$number}";

            /** @var Extension $extension */
            $extension = Extension::withoutGlobalScope('tenant')->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'extension_number' => $number,
                ],
                [
                    'display_name' => $displayName,
                    'voicemail_enabled' => true,
                    'enabled' => true,
                ],
            );

            SipAccount::withoutGlobalScope('tenant')->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'auth_username' => $number,
                ],
                [
                    'extension_id' => $extension->id,
                    'tenant_domain_id' => $domain->id,
                    'identity_mode' => 'domain_username',
                    'auth_password' => $password,
                    'global_auth_key' => "domain:{$domain->id}:{$number}",
                    'user_context' => $this->dialplanContext->internal((string) $tenant->id),
                    'enabled' => true,
                ],
            );

            Voicemail::withoutGlobalScope('tenant')->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'voicemail_id' => $number,
                ],
                [
                    'mailbox' => $number,
                    'name' => "{$displayName} Voicemail",
                    'password' => $number,
                    'require_password' => true,
                    'forward_to_email' => false,
                    'delete_after_email' => false,
                    'enabled' => true,
                ],
            );
        }

        InboundRoute::withoutGlobalScope('tenant')->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'destination_number' => '15551230000',
            ],
            [
                'name' => "Default inbound DID for {$tenant->name}",
                'action' => 'bridge',
                'action_data' => "user/1000@{$realm}",
                'priority' => 10,
                'enabled' => true,
            ],
        );
    }

    /**
     * Grant the first tenant user tenant-scoped administrator permissions.
     */
    private function seedTenantAdministratorGroup(Tenant $tenant, User $user): void
    {
        $group = Group::firstOrCreate(
            ['name' => 'Tenant Administrators', 'tenant_id' => $tenant->id],
            ['description' => 'Full tenant PBX management without system-level admin permissions.'],
        );

        $permissionIds = Permission::query()
            ->where('name', 'not like', 'admin.%')
            ->pluck('id');

        $group->permissions()->sync($permissionIds);
        $user->groups()->syncWithoutDetaching([$group->id]);
    }

    /**
     * Resolve the SIP realm phones should register against.
     */
    private function defaultSipRealm(): string
    {
        $configuredRealm = trim((string) config('freeswitch.default_sip_realm', ''));

        if ($configuredRealm !== '') {
            return $configuredRealm;
        }

        $server = trim((string) config('freeswitch.server', '127.0.0.1'));
        $host = parse_url($server, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return $host;
        }

        $server = preg_replace('#^https?://#', '', $server) ?? $server;
        $server = explode('/', $server)[0] ?? $server;
        $server = explode(':', $server)[0] ?? $server;

        return $server !== '' ? $server : '127.0.0.1';
    }

    /**
     * Return the generated or fallback SIP password for seeded extensions.
     */
    private function defaultSipPassword(): string
    {
        $password = trim((string) config('freeswitch.default_sip_password', ''));

        return $password !== '' ? $password : 'TallPBX1234!';
    }

    /**
     * Determine whether demo data should be seeded.
     *
     * Controlled by the FSPBX_DEMO_MODE environment variable set by the
     * installer and read through configuration so cached deployments use the
     * value selected when configuration was generated.
     */
    private function isDemoMode(): bool
    {
        return (bool) config('freeswitch.demo_mode', false);
    }
}
