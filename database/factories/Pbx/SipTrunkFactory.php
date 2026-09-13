<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SipTrunks\Models\SipTrunk;

/**
 * Generate fake SipTrunk records for testing.
 *
 * Creates SIP trunk connections with random hostnames, ports, and authentication credentials.
 */
class SipTrunkFactory extends Factory
{
    protected $model = SipTrunk::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word().' Trunk',
            'host' => 'sip.'.fake()->domainName(),
            'port' => 5060,
            'username' => fake()->userName(),
            'password' => fake()->password(),
            'codecs' => 'PCMU,PCMA',
            'enabled' => true,
        ];
    }
}
