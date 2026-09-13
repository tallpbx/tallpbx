<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SipAccounts\Models\SipAccount;

/**
 * @extends Factory<SipAccount>
 */
class SipAccountFactory extends Factory
{
    protected $model = SipAccount::class;

    public function definition(): array
    {
        $username = fake()->unique()->userName();

        return [
            'tenant_id' => Tenant::factory(),
            'identity_mode' => 'global_username',
            'auth_username' => $username,
            'auth_password' => fake()->password(),
            'global_auth_key' => "global:{$username}",
            'enabled' => true,
        ];
    }

    /**
     * Perform the domainUsername operation.
     */
    public function domainUsername(string $username, string $domainId): static
    {
        return $this->state(fn (array $attributes) => [
            'identity_mode' => 'domain_username',
            'auth_username' => $username,
            'tenant_domain_id' => $domainId,
            'global_auth_key' => "domain:{$domainId}:{$username}",
        ]);
    }

    /**
     * Scope the factory state to a specific tenant.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }
}
