<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Concrete implementation of TenantServiceInterface.
 *
 * Manages customer tenant CRUD, the shared Default tenant, and user
 * memberships. All multi-step operations are wrapped in transactions.
 */
class TenantService implements TenantServiceInterface
{
    /**
     * Create the service instance.
     */
    public function __construct(
        private readonly TenantDefaultsService $tenantDefaults,
    ) {}

    /**
     * Create a tenant and provision the tenant-owned PBX defaults.
     */
    public function create(array $data): Tenant
    {
        return DB::transaction(function () use ($data): Tenant {
            $tenant = Tenant::create($data);

            $this->tenantDefaults->provision($tenant);

            return $tenant;
        });
    }

    /**
     * Create, normalize, and provision the shared Default tenant.
     */
    public function defaultTenant(): Tenant
    {
        return DB::transaction(function (): Tenant {
            $tenant = Tenant::firstOrCreate(
                ['slug' => 'default'],
                [
                    'name' => 'Default',
                    'purpose' => Tenant::PURPOSE_DEFAULT,
                    'enabled' => true,
                ],
            );

            if (
                $tenant->name !== 'Default'
                || $tenant->purpose !== Tenant::PURPOSE_DEFAULT
                || $tenant->primary_user_id !== null
                || ! $tenant->enabled
            ) {
                $tenant->forceFill([
                    'name' => 'Default',
                    'purpose' => Tenant::PURPOSE_DEFAULT,
                    'enabled' => true,
                    'primary_user_id' => null,
                ])->save();
            }

            $this->tenantDefaults->provision($tenant);
            $this->provisionDefaultRealmDomain($tenant);

            return $tenant->fresh();
        });
    }

    /**
     * Register the configured default SIP realm as the shared Default tenant's
     * domain so FreeSWITCH directory and voicemail lookups can resolve it.
     *
     * Custom customer tenants keep their own domains and never inherit the
     * server realm, so this runs only for the shared Default tenant.
     */
    private function provisionDefaultRealmDomain(Tenant $tenant): void
    {
        $realm = (string) config('freeswitch.default_sip_realm', '');

        if ($realm === '') {
            return;
        }

        TenantDomain::firstOrCreate(
            ['tenant_id' => $tenant->id, 'domain' => $realm],
            ['purpose' => 'sip_realm', 'enabled' => true],
        );
    }

    /**
     * {@inheritdoc}
     */
    public function update(Tenant $tenant, array $data): Tenant
    {
        $tenant->update($data);

        return $tenant->fresh();
    }

    /**
     * {@inheritdoc}
     */
    public function delete(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant) {
            $tenant->users()->detach();
            $tenant->delete();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function addUser(Tenant $tenant, User $user, string $role = 'member'): void
    {
        $tenant->users()->syncWithoutDetaching([
            $user->id => ['role' => $role],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function removeUser(Tenant $tenant, User $user): void
    {
        $tenant->users()->detach($user->id);
    }

    /**
     * {@inheritdoc}
     */
    public function all(): Collection
    {
        return Tenant::query()
            ->with('primaryUser')
            ->withCount('users')
            ->orderBy('name')
            ->get();
    }

    /**
     * {@inheritdoc}
     */
    public function findBySlug(string $slug): ?Tenant
    {
        return Tenant::where('slug', $slug)->first();
    }

    /**
     * {@inheritdoc}
     */
    public function setEnabled(Tenant $tenant, bool $enabled): Tenant
    {
        $tenant->update(['enabled' => $enabled]);

        return $tenant->fresh();
    }
}
