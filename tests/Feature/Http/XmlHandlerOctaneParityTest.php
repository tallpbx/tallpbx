<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\TelephonyWorkerProviders;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Modules\Admin\Providers\ModuleServiceProvider as AdminModuleServiceProvider;
use Modules\Backups\Providers\ModuleServiceProvider as BackupsModuleServiceProvider;
use Modules\Extensions\Models\Extension;
use Modules\RingGroups\Models\RingGroup;
use Modules\RingGroups\Models\RingGroupExtension;
use Modules\SipAccounts\Models\SipAccount;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    config([
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.directory_cache_store' => 'array',
        'freeswitch.xml_handler.directory_cache_ttl' => 0,
    ]);
});

/**
 * Dispatch a request through a fresh container booted with TALLPBX_WORKER_ROLE=telephony.
 */
function handleLeanTelephonyRequest(Request $request): Response
{
    $previousApp = Container::getInstance();
    $previousFacadeApp = Facade::getFacadeApplication();
    $previousRole = getenv('TALLPBX_WORKER_ROLE');

    putenv('TALLPBX_WORKER_ROLE=telephony');
    $_ENV['TALLPBX_WORKER_ROLE'] = 'telephony';
    $_SERVER['TALLPBX_WORKER_ROLE'] = 'telephony';

    try {
        /** @var Application $app */
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';

        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();

        if ($previousApp !== null && $previousApp->bound('config')) {
            $app->instance('config', $previousApp->make('config'));
        }

        if ($previousApp !== null && $previousApp->bound('db')) {
            $db = $previousApp->make('db');
            $app->instance('db', $db);
            Model::setConnectionResolver($db);
        }

        return $kernel->handle($request);
    } finally {
        if ($previousRole === false) {
            putenv('TALLPBX_WORKER_ROLE');
            unset($_ENV['TALLPBX_WORKER_ROLE'], $_SERVER['TALLPBX_WORKER_ROLE']);
        } else {
            putenv("TALLPBX_WORKER_ROLE={$previousRole}");
            $_ENV['TALLPBX_WORKER_ROLE'] = $previousRole;
            $_SERVER['TALLPBX_WORKER_ROLE'] = $previousRole;
        }

        Container::setInstance($previousApp);
        Facade::setFacadeApplication($previousFacadeApp);
    }
}

/**
 * Create a tenant with a SIP domain and one registered SIP account.
 *
 * @return array{0: Tenant, 1: TenantDomain, 2: SipAccount}
 */
function seedParityDirectoryTenant(): array
{
    $tenant = Tenant::factory()->create();

    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'sip.octane-parity.test',
        'enabled' => true,
    ]);

    $account = SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'user6001',
        'user_context' => 'tenant_'.$tenant->id.'_internal',
        'enabled' => true,
    ]);

    return [$tenant, $domain, $account];
}

it('filters registered module providers when TALLPBX_WORKER_ROLE is telephony', function (): void {
    $previousRole = getenv('TALLPBX_WORKER_ROLE');
    putenv('TALLPBX_WORKER_ROLE=telephony');
    $_ENV['TALLPBX_WORKER_ROLE'] = 'telephony';

    try {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();

        $loadedProviders = array_keys($app->getLoadedProviders());

        foreach (TelephonyWorkerProviders::MODULE_PROVIDERS as $provider) {
            expect($loadedProviders)->toContain($provider);
        }

        expect($loadedProviders)->not->toContain(AdminModuleServiceProvider::class)
            ->not->toContain(BackupsModuleServiceProvider::class);
    } finally {
        if ($previousRole === false) {
            putenv('TALLPBX_WORKER_ROLE');
            unset($_ENV['TALLPBX_WORKER_ROLE']);
        } else {
            putenv("TALLPBX_WORKER_ROLE={$previousRole}");
            $_ENV['TALLPBX_WORKER_ROLE'] = $previousRole;
        }
    }
});

it('returns byte-identical XML for a domain directory lookup', function (): void {
    $uri = '/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'example.com',
        'key_value' => 'example.com',
    ]);

    $normal = $this->get($uri);
    $normal->assertOk();

    $lean = handleLeanTelephonyRequest(Request::create($uri, 'GET'));

    expect($lean->getContent())->toBe($normal->getContent());
});

it('returns byte-identical XML for a not-found user lookup', function (): void {
    $uri = '/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'user',
        'key_value' => '1001',
        'sip_auth_username' => 'user1001',
    ]);

    $normal = $this->get($uri);
    $normal->assertOk();

    $lean = handleLeanTelephonyRequest(Request::create($uri, 'GET'));

    expect($lean->getContent())->toBe($normal->getContent());
});

it('returns byte-identical XML for a dialplan with a ring group contributor', function (): void {
    [$tenant] = seedParityDirectoryTenant();

    $extension = Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '2101',
    ]);

    $group = RingGroup::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'support_team',
        'strategy' => 'simultaneous',
        'ring_timeout' => 20,
        'enabled' => true,
    ]);

    RingGroupExtension::factory()->create([
        'ring_group_id' => $group->id,
        'extension_uuid' => $extension->id,
        'position' => 1,
    ]);

    $uri = '/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => 'tenant_'.$tenant->id.'_internal',
        'Caller-Destination-Number' => 'support_team',
        'Caller-Caller-ID-Number' => 'user6001',
        'domain' => 'sip.octane-parity.test',
        'sip_auth_username' => 'user6001',
    ]);

    $normal = $this->get($uri);
    $normal->assertOk();

    $lean = handleLeanTelephonyRequest(Request::create($uri, 'GET'));

    expect($lean->getContent())->toBe($normal->getContent());
});

it('returns byte-identical XML for a seeded domain directory', function (): void {
    seedParityDirectoryTenant();

    $uri = '/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'sip.octane-parity.test',
        'key_value' => 'sip.octane-parity.test',
    ]);

    $normal = $this->get($uri);
    $normal->assertOk();

    $lean = handleLeanTelephonyRequest(Request::create($uri, 'GET'));

    expect($lean->getContent())->toBe($normal->getContent());
});

it('returns byte-identical XML for a seeded user entry', function (): void {
    seedParityDirectoryTenant();

    $uri = '/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'user',
        'domain' => 'sip.octane-parity.test',
        'key_value' => 'user6001',
        'sip_auth_username' => 'user6001',
    ]);

    $normal = $this->get($uri);
    $normal->assertOk();

    $lean = handleLeanTelephonyRequest(Request::create($uri, 'GET'));

    expect($lean->getContent())->toBe($normal->getContent());
});
