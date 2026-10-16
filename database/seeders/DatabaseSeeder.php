<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Group;
use App\Models\Module;
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
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;
use Modules\SipAccounts\Models\SipAccount;
use Modules\Voicemails\Models\Voicemail;

/**
 * Seeds the application with default data for local development.
 *
 * When PBX_DEMO_MODE is true, creates:
 *   - 2 customer tenants (TallPBX, Acme Corp) with SIP extensions,
 *     voicemail, inbound routes, and tenant defaults
 *   - 4 demo users across the two tenants, one of whom belongs to
 *     both tenants (multi-tenant scenario)
 *   - Admin permission groups; the first administrator is created separately
 *
 * When PBX_DEMO_MODE is false, only creates the Default tenant and admin
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
        if ($this->isDemoMode()) {
            $this->ensureDemoCustomModuleInstalled();
        }

        Artisan::call('module:sync', ['--only-local' => true]);
        app(PermissionService::class)->syncToDatabase();

        DB::transaction(function (): void {
            $this->call(DefaultSettingsSeeder::class);
            $this->call(LocalMediaFileStoreSeeder::class);
            $this->call(SecurityServiceSeeder::class);
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

        // ── Demo custom module ───────────────────────────────────────────
        $this->seedDemoCustomModule();

        // ── Demo firewall rules ───────────────────────────────────────────
        $this->seedDemoFirewallRules();
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
                // Demo accounts get an unguessable random password; access happens through an explicit reset.
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

        // Fallback for hostless inputs: strip the scheme, path, and port manually.
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
     * Controlled by the PBX_DEMO_MODE environment variable set by the
     * installer and read through configuration so cached deployments use the
     * value selected when configuration was generated.
     */
    private function isDemoMode(): bool
    {
        return (bool) config('freeswitch.demo_mode', false);
    }

    /**
     * Ensure the demo custom module files are scaffolded on disk.
     */
    private function ensureDemoCustomModuleInstalled(): void
    {
        $customModulePath = base_path('custom-modules/demo-webhook');

        if (! is_dir($customModulePath)) {
            Artisan::call('make:module', [
                'name' => 'demo-webhook',
                '--display-name' => 'Demo Webhook Alerts',
                '--description' => 'Demo third-party webhook integration for dispatching call event notifications.',
                '--category' => 'custom',
            ]);
        }
    }

    /**
     * Seed or ensure the demo custom module is enabled in the registry.
     */
    private function seedDemoCustomModule(): void
    {
        Module::updateOrCreate(
            ['name' => 'demo-webhook'],
            [
                'display_name' => 'Demo Webhook Alerts',
                'source' => 'custom',
                'category' => 'custom',
                'enabled' => true,
                'version' => '1.0.0',
            ],
        );
    }

    /**
     * Seed safe demo custom firewall rules.
     *
     * Only references services that are already allowed in the standard PBX
     * port catalog (ICMP Ping, Web Admin Portal) with accept actions, so it
     * does not block actual traffic or compromise security.
     */
    private function seedDemoFirewallRules(): void
    {
        $icmpServiceId = SecurityService::where('name', 'ICMP Ping Diagnostics')->value('id');
        $webServiceId = SecurityService::where('name', 'Web Admin Portal')->value('id');
        $tftpServiceId = SecurityService::where('name', 'TFTP Provisioning')->value('id');

        $demoRules = [
            [
                'sequence' => 10,
                'description' => 'Allow ICMP Ping Diagnostics',
                'source_ip' => 'any',
                'service_id' => $icmpServiceId,
                'custom_port' => null,
                'custom_protocol' => null,
                'action' => 'accept',
                'enabled' => true,
            ],
            [
                'sequence' => 20,
                'description' => 'Allow Web Admin Portal (HTTP/HTTPS)',
                'source_ip' => 'any',
                'service_id' => $webServiceId,
                'custom_port' => null,
                'custom_protocol' => null,
                'action' => 'accept',
                'enabled' => true,
            ],
            [
                'sequence' => 30,
                'description' => 'Allow TFTP Provisioning (Inactive Example)',
                'source_ip' => 'any',
                'service_id' => $tftpServiceId,
                'custom_port' => null,
                'custom_protocol' => null,
                'action' => 'accept',
                'enabled' => false,
            ],
        ];

        foreach ($demoRules as $rule) {
            SecurityRule::updateOrCreate(
                ['description' => $rule['description']],
                $rule,
            );
        }
    }
}
