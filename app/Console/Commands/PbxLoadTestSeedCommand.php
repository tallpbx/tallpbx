<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\DialplanContext;
use App\Services\TenantDefaultsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\CallBlocks\Models\CallBlock;
use Modules\CallCenters\Models\Queue;
use Modules\CallForwards\Models\CallForward;
use Modules\Conferences\Models\Conference;
use Modules\Emergency\Models\Emergency;
use Modules\Extensions\Models\Extension;
use Modules\FeatureCodes\Models\FeatureCode;
use Modules\FollowMe\Models\FollowMe;
use Modules\Gateways\Models\Gateway;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\OutboundRoutes\Models\OutboundRoute;
use Modules\RingGroups\Models\RingGroup;
use Modules\RingGroups\Models\RingGroupExtension;
use Modules\SipAccounts\Models\SipAccount;
use Modules\TimeConditions\Models\TimeCondition;
use Modules\Voicemails\Models\Voicemail;

/**
 * Creates repeatable tenant, extension, route, gateway, and SIPp test data.
 *
 * The generated records exercise internal, inbound, and outbound dialplan
 * XML paths without touching real tenant data. Use --reset to recreate only
 * the named synthetic tenant.
 */
#[Signature('pbx:load-test:seed
    {--tenant=load-test-beta : Synthetic tenant slug to create or update}
    {--domain=load.test.local : SIP realm/domain for the synthetic tenant}
    {--extensions=20 : Number of extensions and SIP accounts to create}
    {--start=2000 : First extension number in the generated range}
    {--password=LoadTest1234 : Password assigned to every generated SIP account}
    {--sipp-host=127.0.0.1 : SIPp UAS host used by the simulated outbound gateway}
    {--sipp-port=5088 : SIPp UAS port used by the simulated outbound gateway}
    {--output=storage/app/load-tests/sipp-users.csv : CSV path written for SIPp injection}
    {--include-media-fixtures : Seed queue and IVR destinations used by optional SIPp/RTP media-flow validation}
    {--include-extended-fixtures : Seed ring groups, voicemail, call-forwards, time-conditions, follow-me, conferences, emergency, and call-blocks for extended SIPp scenario coverage}
    {--reset : Delete the synthetic tenant before recreating it}')]
#[Description('Create repeatable PBX data and SIPp CSV credentials for beta call-load testing')]
class PbxLoadTestSeedCommand extends Command
{
    /**
     * Create repeatable tenant, extension, route, and SIPp credential data.
     */
    public function handle(TenantDefaultsService $tenantDefaults, DialplanContext $dialplanContext): int
    {
        $slug = $this->stringOption('tenant');
        $domain = $this->stringOption('domain');
        $extensionCount = $this->integerOption('extensions', minimum: 2, maximum: 10000);
        $start = $this->integerOption('start', minimum: 100, maximum: 999999);
        $password = $this->stringOption('password');
        $sippHost = $this->stringOption('sipp-host');
        $sippPort = $this->integerOption('sipp-port', minimum: 1, maximum: 65535);
        $output = $this->stringOption('output');
        $includeMediaFixtures = (bool) $this->option('include-media-fixtures');
        $includeExtendedFixtures = (bool) $this->option('include-extended-fixtures');

        if ($this->option('reset')) {
            Tenant::where('slug', $slug)->delete();
        }

        $summary = DB::transaction(function () use ($slug, $domain, $extensionCount, $start, $password, $sippHost, $sippPort, $includeMediaFixtures, $includeExtendedFixtures, $tenantDefaults, $dialplanContext): array {
            $tenant = Tenant::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => 'PBX Load Test',
                    'purpose' => Tenant::PURPOSE_CUSTOMER,
                    'enabled' => true,
                ],
            );

            /** @var TenantDomain $tenantDomain */
            $tenantDomain = TenantDomain::updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'domain' => $domain,
                    'purpose' => 'sip_realm',
                ],
                ['enabled' => true],
            );

            $defaults = $tenantDefaults->provision($tenant);
            $extensionNumbers = $this->extensionNumbers($start, $extensionCount);

            foreach ($extensionNumbers as $number) {
                /** @var Extension $extension */
                $extension = Extension::withoutGlobalScope('tenant')->updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'extension_number' => $number,
                    ],
                    [
                        'display_name' => "Load Test {$number}",
                        'voicemail_enabled' => false,
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
                        'tenant_domain_id' => $tenantDomain->id,
                        'identity_mode' => 'domain_username',
                        'auth_password' => $password,
                        'global_auth_key' => "domain:{$tenantDomain->id}:{$number}",
                        'user_context' => $dialplanContext->internal((string) $tenant->id),
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
                    'name' => 'SIPp inbound DID to first extension',
                    'action' => 'bridge',
                    'action_data' => "user/{$extensionNumbers[0]}@{$domain}",
                    'priority' => 10,
                    'enabled' => true,
                ],
            );

            /** @var Gateway $gateway */
            $gateway = Gateway::withoutGlobalScope('tenant')->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'name' => 'sipp-load-uac',
                ],
                [
                    'description' => 'Synthetic SIPp UAS gateway for outbound load tests.',
                    'host' => $sippHost,
                    'port' => $sippPort,
                    'username' => null,
                    'password' => null,
                    'realm' => null,
                    'proxy' => "{$sippHost}:{$sippPort}",
                    'register' => false,
                    'context' => $dialplanContext->public((string) $tenant->id),
                    'profile' => 'external',
                    'settings' => ['register' => 'false'],
                    'enabled' => true,
                ],
            );

            OutboundRoute::withoutGlobalScope('tenant')->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'name' => 'SIPp outbound 9-prefix',
                ],
                [
                    'dial_pattern' => '9(\d{10})',
                    'gateway_id' => null,
                    'gateway' => "sofia/external/\$1@{$sippHost}:{$sippPort}",
                    'caller_id_name' => 'PBX Load Test',
                    'caller_id_number' => $extensionNumbers[0],
                    'priority' => 90,
                    'enabled' => true,
                ],
            );

            if ($includeMediaFixtures) {
                $this->seedMediaFixtures($tenant->id);
            }

            if ($includeExtendedFixtures) {
                $this->seedExtendedFixtures($tenant->id, $extensionNumbers, $sippHost, $sippPort);
            }

            return [
                'tenant' => $tenant,
                'domain' => $tenantDomain,
                'defaults' => $defaults,
                'extension_numbers' => $extensionNumbers,
            ];
        });

        $this->writeSippCsv($output, $summary['extension_numbers'], $password, $domain);

        /** @var Tenant $tenant */
        $tenant = $summary['tenant'];

        $this->components->info('PBX load-test data is ready.');
        $this->components->twoColumnDetail('Tenant', "{$tenant->name} ({$tenant->slug}, id {$tenant->id})");
        $this->components->twoColumnDetail('SIP realm', $domain);
        $this->components->twoColumnDetail('Extensions', (string) count($summary['extension_numbers']));
        $this->components->twoColumnDetail('Media fixtures', $includeMediaFixtures ? 'yes' : 'no');
        $this->components->twoColumnDetail('Extended fixtures', $includeExtendedFixtures ? 'yes' : 'no');
        $this->components->twoColumnDetail('SIPp CSV', base_path($output));

        return self::SUCCESS;
    }

    /**
     * Read a required string option.
     */
    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("The [{$name}] option must be a non-empty string.");
        }

        return trim($value);
    }

    /**
     * Read a bounded integer option.
     */
    private function integerOption(string $name, int $minimum, int $maximum): int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT);

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException("The [{$name}] option must be between {$minimum} and {$maximum}.");
        }

        return $value;
    }

    /**
     * Return the generated extension range.
     *
     * @return array<int, string>
     */
    private function extensionNumbers(int $start, int $count): array
    {
        return array_map(
            fn (int $offset): string => (string) ($start + $offset),
            range(0, $count - 1),
        );
    }

    /**
     * Write a SIPp CSV file with caller/destination credentials.
     *
     * @param  array<int, string>  $extensionNumbers
     */
    private function writeSippCsv(string $path, array $extensionNumbers, string $password, string $domain): void
    {
        $absolutePath = base_path($path);
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $handle = fopen($absolutePath, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Unable to write SIPp CSV file [{$absolutePath}].");
        }

        fwrite($handle, "SEQUENTIAL\n");
        $count = count($extensionNumbers);

        foreach ($extensionNumbers as $index => $number) {
            $destination = $extensionNumbers[($index + 1) % $count];
            fputcsv($handle, [$number, $password, $domain, $destination, $number], separator: ';');
        }

        fclose($handle);
    }

    /**
     * Seed optional media destinations for SIPp/RTP validation.
     */
    private function seedMediaFixtures(int $tenantId): void
    {
        Queue::withoutGlobalScope('tenant')->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'name' => 'load_test_moh',
            ],
            [
                'strategy' => 'ring-all',
                'timeout' => 30,
                'music_on_hold' => 'local_stream://moh',
                'enabled' => true,
            ],
        );

        IvrMenu::withoutGlobalScope('tenant')->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'name' => 'load_test_announcement',
            ],
            [
                'greeting' => '$${sounds_dir}/en/us/callie/ivr/8000/ivr-thank_you_for_calling.wav',
                'timeout' => 3,
                'max_failures' => 1,
                'digit_length' => 1,
                'description' => 'Synthetic announcement prompt used by optional SIPp/RTP validation.',
                'enabled' => true,
            ],
        );
    }

    /**
     * Seed extended fixtures for comprehensive SIPp scenario coverage.
     *
     * Creates ring groups, voicemail, call forwards, time conditions, follow-me,
     * conferences, emergency config, and call blocks so that every
     * ContextWideDialplanXmlContributor module has runtime test data.
     *
     * @param  array<int, string>  $extensionNumbers
     */
    private function seedExtendedFixtures(int $tenantId, array $extensionNumbers, string $sippHost, int $sippPort): void
    {
        // Ring group: simultaneous ring to first 3 extensions
        $ringGroup = RingGroup::withoutGlobalScope('tenant')->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'name' => '2400',
            ],
            [
                'strategy' => 'simultaneous',
                'ring_timeout' => 30,
                'description' => 'Synthetic ring group for extended SIPp validation.',
                'enabled' => true,
            ],
        );

        // Attach first 3 extension UUIDs to the ring group
        $ringGroupExtensions = Extension::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('extension_number', array_slice($extensionNumbers, 0, 3))
            ->orderBy('extension_number')
            ->get();

        foreach ($ringGroupExtensions as $index => $ext) {
            RingGroupExtension::updateOrCreate(
                [
                    'ring_group_id' => $ringGroup->id,
                    'extension_uuid' => $ext->id,
                ],
                [
                    'position' => $index + 1,
                ],
            );
        }

        // Voicemail mailbox for the 4th extension (index 3)
        $vmExtension = $extensionNumbers[3] ?? $extensionNumbers[0];
        Voicemail::withoutGlobalScope('tenant')->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'voicemail_id' => $vmExtension,
            ],
            [
                'mailbox' => $vmExtension,
                'name' => "Load Test VM {$vmExtension}",
                'password' => '1234',
                'email' => null,
                'greeting_message' => null,
                'require_password' => false,
                'forward_to_email' => false,
                'delete_after_email' => false,
                'enabled' => true,
            ],
        );

        // Unconditional call forward: extension 2001 → 2000
        $cfExt = Extension::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('extension_number', $extensionNumbers[1] ?? '2001')
            ->first();

        if ($cfExt) {
            CallForward::withoutGlobalScope('tenant')->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'extension_uuid' => $cfExt->id,
                    'forward_type' => 'unconditional',
                ],
                [
                    'destination' => $extensionNumbers[0] ?? '2000',
                    'ring_timeout' => 20,
                    'enabled' => true,
                ],
            );
        }

        // Always-match time condition → extension 2000
        TimeCondition::withoutGlobalScope('tenant')->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'name' => '2401',
            ],
            [
                'timezone' => null,
                'weekdays' => 'mon,tue,wed,thu,fri,sat,sun',
                'start_time' => '00:00',
                'end_time' => '23:59',
                'destination_on_match' => $extensionNumbers[0] ?? '2000',
                'destination_on_no_match' => null,
                'enabled' => true,
            ],
        );

        // Follow-me: extension 2002 → external number
        FollowMe::withoutGlobalScope('tenant')->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'name' => 'load_test_follow_me',
            ],
            [
                'extension' => $extensionNumbers[2] ?? '2002',
                'destination' => 'sofia/external/15551234567',
                'ring_timeout' => 20,
                'enabled' => true,
            ],
        );

        // Conference room
        Conference::withoutGlobalScope('tenant')->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'name' => '2500',
            ],
            [
                'profile' => 'default',
                'pin' => null,
                'max_members' => 10,
                'enabled' => true,
            ],
        );

        // Emergency config
        Emergency::withoutGlobalScope('tenant')->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'caller_id' => '15551239876',
            ],
            [
                'address' => '123 Main St, Anytown, CA 90210',
                'latitude' => '34.0522',
                'longitude' => '-118.2437',
            ],
        );

        // Gateway for emergency routing — FreeSWITCH needs a gateway named
        // 'emergency' on the external profile to route 911/112 calls.
        Gateway::withoutGlobalScope('tenant')->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'name' => 'emergency',
            ],
            [
                'description' => 'Synthetic emergency gateway for SIPp 911 validation.',
                'host' => $sippHost,
                'port' => $sippPort,
                'username' => null,
                'password' => null,
                'realm' => null,
                'proxy' => "{$sippHost}:{$sippPort}",
                'register' => false,
                'context' => "tenant_{$tenantId}_public",
                'profile' => 'external',
                'settings' => ['register' => 'false'],
                'enabled' => true,
            ],
        );

        // Call block: reject numbers starting with 15550000
        CallBlock::withoutGlobalScope('tenant')->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'name' => 'load_test_block_inbound',
            ],
            [
                'caller_id_number' => '^15550000\\d{3}$',
                'description' => 'Synthetic call block rule for extended SIPp validation.',
                'enabled' => true,
            ],
        );

        // Feature code: *98 for voicemail access (lookup by tenant+code to avoid unique constraint)
        FeatureCode::withoutGlobalScope('tenant')->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'code' => '*98',
            ],
            [
                'name' => 'voicemail_access',
                'application' => 'voicemail',
                'application_data' => 'check',
                'description' => 'Synthetic voicemail access feature code for SIPp validation.',
                'enabled' => true,
            ],
        );
    }
}
