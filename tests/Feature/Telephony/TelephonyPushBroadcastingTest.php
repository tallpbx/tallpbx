<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Events\FreeSwitch\CallCenterEvent;
use App\Events\FreeSwitch\ChannelCreate;
use App\Events\FreeSwitch\ConferenceEvent;
use App\Events\FreeSwitch\Heartbeat;
use App\Events\FreeSwitch\SofiaRegister;
use App\Events\Telephony\TelephonyUpdated;
use App\Listeners\BroadcastTelephonyOnFreeSwitchEvent;
use App\Models\Admin;
use App\Models\Group;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Livewire\Attributes\On;
use Livewire\Livewire;
use Modules\ActiveCalls\Livewire\ActiveCallsList;
use Modules\ActiveConferences\Livewire\ActiveConferencesList;
use Modules\CallCenterActive\Livewire\CallCenterActiveList;
use Modules\OperatorPanel\Livewire\OperatorPanelIndex;
use Modules\Registrations\Livewire\RegistrationsList;
use Modules\SipStatus\Livewire\SipStatusList;
use ReflectionClass;
use ReflectionProperty;

beforeEach(function () {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($superAdminGroup->id);
});

test('telephony status views do not contain wire:poll directives', function () {
    $views = [
        'active-calls::active-calls-list' => base_path('app-modules/active-calls/resources/views/active-calls-list.blade.php'),
        'active-conferences::active-conferences-list' => base_path('app-modules/active-conferences/resources/views/active-conferences-list.blade.php'),
        'registrations::registrations-list' => base_path('app-modules/registrations/resources/views/registrations-list.blade.php'),
        'sip-status::sip-status-list' => base_path('app-modules/sip-status/resources/views/sip-status-list.blade.php'),
        'call-center-active::call-center-active-list' => base_path('app-modules/call-center-active/resources/views/call-center-active-list.blade.php'),
        'operator-panel::operator-panel-index' => base_path('app-modules/operator-panel/resources/views/operator-panel-index.blade.php'),
    ];

    foreach ($views as $name => $filePath) {
        expect(file_exists($filePath))->toBeTrue("View file for {$name} should exist.");
        $content = file_get_contents($filePath);
        expect($content)->not->toContain('wire:poll', "View {$name} contains forbidden wire:poll directive.");
    }
});

test('only legitimate non-telephony views contain wire:poll across all blade templates', function () {
    $allBladeFiles = array_merge(
        glob(base_path('app-modules/*/resources/views/**/*.blade.php')) ?: [],
        glob(base_path('app-modules/*/resources/views/*.blade.php')) ?: [],
        glob(base_path('resources/views/**/*.blade.php')) ?: [],
        glob(base_path('resources/views/*.blade.php')) ?: []
    );

    $pollingViews = [];
    foreach ($allBladeFiles as $file) {
        $content = file_get_contents($file);
        if (str_contains($content, 'wire:poll')) {
            $pollingViews[] = str_replace(base_path().'/', '', $file);
        }
    }

    sort($pollingViews);

    $expectedPollingViews = [
        'app-modules/admin/resources/views/git-update.blade.php',
        'app-modules/backups/resources/views/restore-archive.blade.php',
        'app-modules/logs/resources/views/log-viewer.blade.php',
    ];
    sort($expectedPollingViews);

    expect($pollingViews)->toBe($expectedPollingViews);
});

test('all six telephony livewire components listen to echo push events', function () {
    $components = [
        ActiveCallsList::class => 'refreshCalls',
        ActiveConferencesList::class => 'refresh',
        RegistrationsList::class => 'refresh',
        SipStatusList::class => 'refresh',
        CallCenterActiveList::class => 'refresh',
        OperatorPanelIndex::class => 'refresh',
    ];

    foreach ($components as $class => $targetMethod) {
        $reflection = new ReflectionClass($class);
        expect($reflection->hasProperty('tenantId'))->toBeTrue("Component {$class} must have public \$tenantId property.");

        $prop = $reflection->getProperty('tenantId');
        expect($prop->isPublic())->toBeTrue("Property \$tenantId on {$class} must be public.");

        expect($reflection->hasMethod($targetMethod))->toBeTrue("Component {$class} must have {$targetMethod} method.");
        $method = $reflection->getMethod($targetMethod);
        $attributes = $method->getAttributes(On::class);

        $eventNames = array_map(fn ($attr) => $attr->getArguments()[0] ?? '', $attributes);

        expect($eventNames)->toContain('echo-private:tenant.{tenantId}.telephony,.TelephonyUpdated');
        expect($eventNames)->toContain('echo:dashboard.monitoring,.DashboardStatsUpdated');
    }
});

test('TelephonyUpdated event implements ShouldBroadcastNow and formats broadcast payloads', function () {
    $globalEvent = new TelephonyUpdated(tenantId: null, eventName: 'test-event', data: ['call_count' => 5]);

    expect($globalEvent)->toBeInstanceOf(ShouldBroadcastNow::class);
    expect($globalEvent->broadcastAs())->toBe('TelephonyUpdated');

    $globalChannels = $globalEvent->broadcastOn();
    expect($globalChannels)->toHaveCount(1);
    expect($globalChannels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($globalChannels[0]->name)->toBe('private-tenant.global.telephony');

    $tenantEvent = new TelephonyUpdated(tenantId: '42', eventName: 'test-event', data: ['call_count' => 2]);
    $tenantChannels = $tenantEvent->broadcastOn();
    expect($tenantChannels)->toHaveCount(2);

    $channelNames = array_map(fn ($c) => $c->name, $tenantChannels);
    expect($channelNames)->toContain('private-tenant.global.telephony');
    expect($channelNames)->toContain('private-tenant.42.telephony');

    $payload = $tenantEvent->broadcastWith();
    expect($payload['tenant_id'])->toBe('42');
    expect($payload['event_name'])->toBe('test-event');
    expect($payload['data'])->toBe(['call_count' => 2]);
    expect($payload)->toHaveKey('timestamp');
});

test('BroadcastTelephonyOnFreeSwitchEvent listener dispatches TelephonyUpdated for telephony lifecycle events', function () {
    Event::fake([TelephonyUpdated::class]);
    config(['broadcasting.test_broadcasts' => true]);

    $listener = new BroadcastTelephonyOnFreeSwitchEvent;

    // 1. ChannelCreate event with tenant variable
    $channelCreate = new ChannelCreate(
        eventName: 'CHANNEL_CREATE',
        headers: ['Unique-ID' => 'call-uuid-1', 'variable_tenant_id' => '10'],
        body: ''
    );
    $listener->handle($channelCreate);

    Event::assertDispatched(TelephonyUpdated::class, function (TelephonyUpdated $event) {
        return $event->eventName === 'CHANNEL_CREATE' && $event->tenantId === '10';
    });

    // 2. SofiaRegister event
    $sofiaReg = new SofiaRegister(
        eventName: 'CUSTOM',
        headers: ['Event-Subclass' => 'sofia::register', 'profile_name' => 'internal', 'from_user' => '1001'],
        body: ''
    );
    $listener->handle($sofiaReg);

    Event::assertDispatched(TelephonyUpdated::class, function (TelephonyUpdated $event) {
        return $event->eventName === 'CUSTOM';
    });

    // 3. ConferenceEvent
    $confEvent = new ConferenceEvent(
        eventName: 'CUSTOM',
        headers: ['Event-Subclass' => 'conference::maintenance', 'Action' => 'add-member', 'Conference-Name' => 'room-300'],
        body: ''
    );
    $listener->handle($confEvent);

    Event::assertDispatched(TelephonyUpdated::class, function (TelephonyUpdated $event) {
        return $event->eventName === 'CUSTOM';
    });

    // 4. CallCenterEvent
    $ccEvent = new CallCenterEvent(
        eventName: 'CUSTOM',
        headers: ['Event-Subclass' => 'callcenter::info', 'CC-Action' => 'member-queue-start'],
        body: ''
    );
    $listener->handle($ccEvent);

    Event::assertDispatched(TelephonyUpdated::class, function (TelephonyUpdated $event) {
        return $event->eventName === 'CUSTOM';
    });

    // 5. Heartbeat
    $heartbeat = new Heartbeat(
        eventName: 'HEARTBEAT',
        headers: ['Up-Time' => '1000'],
        body: ''
    );
    $listener->handle($heartbeat);

    Event::assertDispatched(TelephonyUpdated::class, function (TelephonyUpdated $event) {
        return $event->eventName === 'HEARTBEAT' && $event->tenantId === null;
    });
});

test('channel authorization callback grants admins access to global and tenant telephony channels', function () {
    $broadcaster = Broadcast::driver();
    $channels = $broadcaster->getChannels();

    expect($channels->has('tenant.{tenantId}.telephony'))->toBeTrue('Channel tenant.{tenantId}.telephony must be registered');

    $callback = $channels->get('tenant.{tenantId}.telephony');

    // Admin can subscribe to global and any tenant channel
    expect($callback($this->admin, 'global'))->toBeTrue();
    expect($callback($this->admin, '123'))->toBeTrue();
    expect($callback($this->admin, '999'))->toBeTrue();
});

test('channel authorization callback enforces strict tenant boundary isolation for users', function () {
    $broadcaster = Broadcast::driver();
    $channels = $broadcaster->getChannels();
    $callback = $channels->get('tenant.{tenantId}.telephony');

    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    $user = User::factory()->create();
    $user->tenants()->attach($tenant1->id, ['role' => 'user']);

    // User is authorized for their own tenant channel
    expect($callback($user, (string) $tenant1->id))->toBeTrue();

    // User is denied access to other tenant channels
    expect($callback($user, (string) $tenant2->id))->toBeFalse();

    // User is denied access to global channel
    expect($callback($user, 'global'))->toBeFalse();
});

test('channel authorization is configured with web and admin guards', function () {
    $broadcaster = Broadcast::driver();

    $prop = new ReflectionProperty($broadcaster, 'channelOptions');
    $prop->setAccessible(true);
    $options = $prop->getValue($broadcaster);

    expect($options)->toHaveKey('tenant.{tenantId}.telephony');
    expect($options['tenant.{tenantId}.telephony']['guards'] ?? [])->toBe(['web', 'admin']);
});

test('all five live telephony components handle echo-reconnected event and define resyncAfterReconnect', function () {
    $components = [
        ActiveCallsList::class,
        ActiveConferencesList::class,
        CallCenterActiveList::class,
        RegistrationsList::class,
        OperatorPanelIndex::class,
    ];

    foreach ($components as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->hasMethod('resyncAfterReconnect'))->toBeTrue("Component {$class} must have resyncAfterReconnect method.");
        $method = $reflection->getMethod('resyncAfterReconnect');
        $attributes = $method->getAttributes(On::class);
        $eventNames = array_map(fn ($attr) => $attr->getArguments()[0] ?? '', $attributes);
        expect($eventNames)->toContain('echo-reconnected');

        Livewire::actingAs($this->admin, 'admin')
            ->test($class)
            ->dispatch('echo-reconnected')
            ->assertStatus(200);
    }
});

test('all five live telephony views include websocket health banner and contain zero wire:poll directives', function () {
    $views = [
        'active-calls' => base_path('app-modules/active-calls/resources/views/active-calls-list.blade.php'),
        'active-conferences' => base_path('app-modules/active-conferences/resources/views/active-conferences-list.blade.php'),
        'call-center-active' => base_path('app-modules/call-center-active/resources/views/call-center-active-list.blade.php'),
        'registrations' => base_path('app-modules/registrations/resources/views/registrations-list.blade.php'),
        'operator-panel' => base_path('app-modules/operator-panel/resources/views/operator-panel-index.blade.php'),
    ];

    foreach ($views as $name => $filePath) {
        expect(file_exists($filePath))->toBeTrue("View file for {$name} should exist.");
        $content = file_get_contents($filePath);
        expect($content)->toContain('<x-websocket-health-banner');
        expect($content)->not->toContain('wire:poll');
    }
});

test('websocket health banner component exists and contains zero wire:poll directives', function () {
    $bannerPath = resource_path('views/components/websocket-health-banner.blade.php');
    expect(file_exists($bannerPath))->toBeTrue('websocket-health-banner.blade.php must exist.');
    $content = file_get_contents($bannerPath);
    expect($content)->not->toContain('wire:poll');
    expect($content)->toContain('tallpbx:ws-disconnected');
    expect($content)->toContain('tallpbx:ws-reconnected');
    expect($content)->toContain("window.Livewire.dispatch('echo-reconnected')");
});
