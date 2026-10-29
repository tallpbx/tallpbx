<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ProcessRunner;
use App\Database\SafeMigrator;
use App\Events\FreeSwitch\CallCenterEvent;
use App\Events\FreeSwitch\CallUpdate;
use App\Events\FreeSwitch\ChannelAnswer;
use App\Events\FreeSwitch\ChannelBridge;
use App\Events\FreeSwitch\ChannelCreate;
use App\Events\FreeSwitch\ChannelDestroy;
use App\Events\FreeSwitch\ChannelHangup;
use App\Events\FreeSwitch\ChannelHangupComplete;
use App\Events\FreeSwitch\ChannelState;
use App\Events\FreeSwitch\ChannelUnbridge;
use App\Events\FreeSwitch\ConferenceEvent;
use App\Events\FreeSwitch\Heartbeat;
use App\Events\FreeSwitch\SofiaExpire;
use App\Events\FreeSwitch\SofiaRegister;
use App\Listeners\BroadcastDashboardStatsOnFreeSwitchEvent;
use App\Listeners\BroadcastTelephonyOnFreeSwitchEvent;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Observers\DashboardStatsObserver;
use App\Observers\RoutingCacheObserver;
use App\Services\DialplanXmlCollector;
use App\Services\FreeSwitchService;
use App\Services\FreeSwitchServiceInterface;
use App\Services\GroupService;
use App\Services\GroupServiceInterface;
use App\Services\ImpersonationService;
use App\Services\ImpersonationServiceInterface;
use App\Services\MenuService;
use App\Services\ModuleState;
use App\Services\PermissionService;
use App\Services\SettingService;
use App\Services\SettingServiceInterface;
use App\Services\TenantContext;
use App\Services\TenantDomainService;
use App\Services\TenantDomainServiceInterface;
use App\Services\TenantIdentityResolver;
use App\Services\TenantIdentityResolverInterface;
use App\Services\TenantManager;
use App\Services\TenantService;
use App\Services\TenantServiceInterface;
use App\Services\UserService;
use App\Services\UserServiceInterface;
use App\Support\GeneratedFilePermissions;
use App\Support\MigrationSafetyGuard;
use App\Support\ModuleServiceProvider as BaseModuleServiceProvider;
use App\Support\PrimaryDatabaseSafety;
use App\Support\SystemProcessRunner;
use App\Support\TestDatabaseSafety;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Modules\Backups\Listeners\BlockFileStoreDeletion;
use Modules\Bridges\Models\Bridge;
use Modules\CallBlocks\Models\CallBlock;
use Modules\CallCenters\Models\Agent;
use Modules\CallCenters\Models\Queue;
use Modules\CallCenters\Models\Tier;
use Modules\CallFlows\Models\CallFlow;
use Modules\CallForwards\Models\CallForward;
use Modules\CallRecordings\Models\CallRecording;
use Modules\ConferenceCenters\Models\ConferenceCenter;
use Modules\Conferences\Models\Conference;
use Modules\Destinations\Models\Destination;
use Modules\Dialplans\Models\Dialplan;
use Modules\Dialplans\Models\DialplanDetail;
use Modules\Emergency\Models\Emergency;
use Modules\Extensions\Models\Extension;
use Modules\Fax\Models\FaxInbox;
use Modules\Fax\Models\FaxOutgoing;
use Modules\FeatureCodes\Models\FeatureCode;
use Modules\FileStores\Events\FileStoreDeleting;
use Modules\FollowMe\Models\FollowMe;
use Modules\HotDesking\Models\HotDeskSession;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\IvrMenus\Models\IvrMenuOption;
use Modules\MusicOnHold\Models\MusicOnHold;
use Modules\NumberTranslations\Models\NumberTranslation;
use Modules\OutboundRoutes\Models\OutboundRoute;
use Modules\PinNumbers\Models\PinNumber;
use Modules\Recordings\Models\Recording;
use Modules\RingGroups\Models\RingGroup;
use Modules\RingGroups\Models\RingGroupExtension;
use Modules\SipAccounts\Models\SipAccount;
use Modules\TenantLimits\Models\TenantLimit;
use Modules\TimeConditions\Models\TimeCondition;
use Modules\VoicemailMessages\Models\VoicemailMessage;
use Modules\Voicemails\Models\Voicemail;
use Symfony\Component\Console\Input\InputOption;

/**
 * Application service provider.
 *
 * Registers core application services, binds interfaces to implementations,
 * and configures global framework behavior.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     *
     * Binds service interfaces to their concrete implementations and
     * registers application-level singletons (TenantManager, MenuService,
     * PermissionService, FreeSwitchService).
     */
    public function register(): void
    {
        // Fail fast during provider registration too so later providers'
        // register() hooks (e.g. ModuleServiceProvider dependency checks) can
        // never reach a cached production database before boot() runs.
        TestDatabaseSafety::enforce();

        $this->app->singleton(TenantManager::class, function (): TenantManager {
            return new TenantManager;
        });

        $this->app->singleton(TenantContext::class, function (): TenantContext {
            return new TenantContext(
                manager: $this->app->make(TenantManager::class),
            );
        });

        $this->app->singleton(MenuService::class, function (): MenuService {
            return new MenuService;
        });

        $this->app->singleton(PermissionService::class, function (): PermissionService {
            return new PermissionService;
        });

        $this->app->singleton(ModuleState::class, function (): ModuleState {
            return new ModuleState;
        });

        $this->app->singleton(MigrationSafetyGuard::class);

        // Decorate Laravel's deferred migrator after it is registered so every
        // normal migration run checks pending source before altering the primary database.
        $this->app->extend('migrator', function (Migrator $migrator): SafeMigrator {
            return new SafeMigrator(
                repository: $migrator->getRepository(),
                resolver: $this->app['db'],
                files: $this->app['files'],
                dispatcher: $this->app['events'],
                migrationSafetyGuard: $this->app->make(MigrationSafetyGuard::class),
            );
        });

        $this->app->bind(GroupServiceInterface::class, GroupService::class);
        $this->app->bind(UserServiceInterface::class, UserService::class);
        $this->app->bind(TenantServiceInterface::class, TenantService::class);
        $this->app->bind(SettingServiceInterface::class, SettingService::class);
        $this->app->bind(TenantDomainServiceInterface::class, TenantDomainService::class);
        $this->app->bind(TenantIdentityResolverInterface::class, TenantIdentityResolver::class);
        $this->app->bind(ImpersonationServiceInterface::class, ImpersonationService::class);
        $this->app->bind(ProcessRunner::class, SystemProcessRunner::class);

        $this->app->singleton(FreeSwitchServiceInterface::class, function (): FreeSwitchService {
            return new FreeSwitchService(
                host: (string) config('freeswitch.esl.host', '127.0.0.1'),
                port: (int) config('freeswitch.esl.port', 8021),
                password: (string) config('freeswitch.esl.password', 'ClueCon'),
                timeout: (int) config('freeswitch.esl.timeout', 10),
            );
        });

        // Dialplan XML collector aggregates contributions from all
        // feature modules implementing DialplanXmlContributor.
        $this->app->singleton(DialplanXmlCollector::class);
    }

    /**
     * Bootstrap application services.
     *
     * Protects the primary database and tests, and prevents lazy loading outside production.
     */
    public function boot(): void
    {
        // Resolve module model factories from each module's own namespace
        // (Modules\X\Models\Y -> Modules\X\Database\Factories\YFactory) so
        // models use standard HasFactory resolution without newFactory()
        // overrides. Everything else keeps the framework's App default.
        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            if (preg_match('/^(Modules\\\\[A-Za-z0-9_]+)\\\\Models\\\\([A-Za-z0-9_]+)$/', $modelName, $matches) === 1) {
                return "{$matches[1]}\\Database\\Factories\\{$matches[2]}Factory";
            }

            $appNamespace = $this->app->getNamespace();

            $modelName = Str::startsWith($modelName, $appNamespace.'Models\\')
                ? Str::after($modelName, $appNamespace.'Models\\')
                : Str::after($modelName, $appNamespace);

            return 'Database\\Factories\\'.$modelName.'Factory';
        });

        Relation::enforceMorphMap([
            'voicemail-message' => VoicemailMessage::class,
            'call-recording' => CallRecording::class,
            'fax-inbound' => FaxInbox::class,
            'fax-outbound' => FaxOutgoing::class,
            'recording' => Recording::class,
            'music-on-hold' => MusicOnHold::class,
            'voicemail' => Voicemail::class,
            'ivr-menu' => IvrMenu::class,
            'conference-center' => ConferenceCenter::class,
            // Notifiable actors receive database notifications through this map.
            'admin' => Admin::class,
            'user' => User::class,
        ]);

        DB::prohibitDestructiveCommands(PrimaryDatabaseSafety::shouldProhibitDestructiveCommands());

        // Keep dashboard monitoring reactive: broadcast stats updates when models or FreeSWITCH channels change
        User::observe(DashboardStatsObserver::class);
        Tenant::observe(DashboardStatsObserver::class);

        // Keep dialplan XML routing caches reactive: invalidate on telephony model mutations
        $this->registerRoutingCacheObservers();

        RateLimiter::for('xml-handler', function (Request $request) {
            if (! config('freeswitch.xml_handler.throttle_enabled', true)) {
                return Limit::none();
            }

            $limit = (int) config('freeswitch.xml_handler.throttle_limit', 6000);
            $decayMinutes = (int) config('freeswitch.xml_handler.throttle_decay_minutes', 1);

            return Limit::perMinutes($decayMinutes, $limit)->by($request->ip() ?? '127.0.0.1');
        });

        Event::listen([
            ChannelCreate::class,
            ChannelAnswer::class,
            ChannelDestroy::class,
            Heartbeat::class,
        ], BroadcastDashboardStatsOnFreeSwitchEvent::class);

        Event::listen([
            ChannelCreate::class,
            ChannelAnswer::class,
            ChannelHangup::class,
            ChannelHangupComplete::class,
            ChannelDestroy::class,
            ChannelBridge::class,
            ChannelUnbridge::class,
            ChannelState::class,
            CallUpdate::class,
            SofiaRegister::class,
            SofiaExpire::class,
            ConferenceEvent::class,
            CallCenterEvent::class,
            Heartbeat::class,
        ], BroadcastTelephonyOnFreeSwitchEvent::class);

        // Keep the backups module's file-store deletion guard active even
        // while that module is disabled: the guard prevents backup profiles
        // from silently losing their destination store, so it must not
        // depend on the enable/disable toggle or on module provider boot
        // ordering. Skipped automatically when the module is uninstalled.
        if (class_exists(BlockFileStoreDeletion::class)) {
            Event::listen(FileStoreDeleting::class, BlockFileStoreDeletion::class);
        }

        // Fail fast inside test runs even when a cached configuration has
        // overridden APP_ENV (environment('testing') lies in that state).
        // enforce() decides by real test-runner detection, so ordinary
        // processes — including "php artisan config:clear" — stay bootable.
        TestDatabaseSafety::enforce();

        Model::preventLazyLoading(! $this->app->isProduction());

        if ($this->app->runningInConsole()) {
            ConsoleApplication::starting(function ($artisan): void {
                $artisan->getDefinition()->addOption(
                    new InputOption('all-modules', null, InputOption::VALUE_NONE, 'Run migrations for all modules, including disabled modules')
                );
            });

            Event::listen(CommandStarting::class, function (CommandStarting $event): void {
                if ($event->input->hasParameterOption('--all-modules')) {
                    ModuleState::setForceAllModulesMigrations(true);
                    $migrator = $this->app->make('migrator');
                    foreach (BaseModuleServiceProvider::allMigrationPaths() as $path) {
                        $migrator->path($path);
                    }
                }
            });

            Event::listen(MigrationStarted::class, function (MigrationStarted $event): void {
                if ($event->method === 'up') {
                    $this->app->make(MigrationSafetyGuard::class)->startMigration((string) $event->name);
                }
            });

            Event::listen(MigrationEnded::class, function (MigrationEnded $event): void {
                if ($event->method === 'up') {
                    $this->app->make(MigrationSafetyGuard::class)->finishMigration();
                }
            });

            Event::listen(CommandFinished::class, function (CommandFinished $event): void {
                ModuleState::setForceAllModulesMigrations(null);

                // The full repair already includes generated paths. Avoid a
                // redundant second pass when the repair command itself exits.
                if ($event->command === 'permissions:repair') {
                    return;
                }

                // Stale module manifests must never survive a cache clear.
                // Purge and immediately rebuild the generated module cache so
                // the next request never boot-cold-scans 60+ manifests; the
                // rebuild also closes the torn-read window for the CLI case.
                if ($event->command === 'optimize:clear') {
                    Artisan::call('module:clear');
                    Artisan::call('module:cache');
                }

                GeneratedFilePermissions::repair();
            });
        }

        // Define Gates after all module providers have registered their permissions
        $this->app->booted(function () {
            $request = $this->app->bound('request') ? $this->app->make('request') : null;

            if ($request?->is('api/v1/xml-handler*')) {
                return;
            }

            $permissionService = $this->app->make(PermissionService::class);

            // Auto-sync permissions to the database only when the registry is stale
            try {
                $permissionService->syncIfStale();
            } catch (\Throwable $e) {
                Log::warning('Permission database sync skipped during boot: '.$e->getMessage(), [
                    'exception' => $e,
                ]);
            }

            foreach ($permissionService->all() as $permission) {
                Gate::define($permission, function (?Authenticatable $user) use ($permission): bool {
                    if ($user === null) {
                        return false;
                    }

                    return $user->hasPermission($permission);
                });
            }
        });
    }

    /**
     * Register model observers that invalidate dialplan routing caches
     * whenever telephony models are created, updated, or deleted.
     */
    protected function registerRoutingCacheObservers(): void
    {
        $telephonyModels = [
            Extension::class,
            SipAccount::class,
            RingGroup::class,
            RingGroupExtension::class,
            InboundRoute::class,
            Destination::class,
            OutboundRoute::class,
            IvrMenu::class,
            IvrMenuOption::class,
            TimeCondition::class,
            CallFlow::class,
            CallBlock::class,
            Bridge::class,
            FollowMe::class,
            HotDeskSession::class,
            Voicemail::class,
            Conference::class,
            ConferenceCenter::class,
            Queue::class,
            Agent::class,
            Tier::class,
            CallForward::class,
            FeatureCode::class,
            Emergency::class,
            Dialplan::class,
            DialplanDetail::class,
            NumberTranslation::class,
            PinNumber::class,
            TenantLimit::class,
        ];

        foreach ($telephonyModels as $modelClass) {
            if (class_exists($modelClass)) {
                $modelClass::observe(RoutingCacheObserver::class);
            }
        }
    }
}
