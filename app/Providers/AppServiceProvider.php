<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ProcessRunner;
use App\Database\SafeMigrator;
use App\Events\FreeSwitch\ChannelAnswer;
use App\Events\FreeSwitch\ChannelCreate;
use App\Events\FreeSwitch\ChannelDestroy;
use App\Events\FreeSwitch\Heartbeat;
use App\Listeners\BroadcastDashboardStatsOnFreeSwitchEvent;
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
use App\Support\DuskDatabaseSafety;
use App\Support\GeneratedFilePermissions;
use App\Support\MigrationSafetyGuard;
use App\Support\PrimaryDatabaseSafety;
use App\Support\SystemProcessRunner;
use App\Support\TestDatabaseSafety;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Modules\Backups\Services\BackupService;
use Modules\Backups\Services\BackupServiceInterface;
use Modules\CallRecordings\Models\CallRecording;
use Modules\ConferenceCenters\Models\ConferenceCenter;
use Modules\Fax\Models\FaxInbox;
use Modules\Fax\Models\FaxOutgoing;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\MusicOnHold\Models\MusicOnHold;
use Modules\Recordings\Models\Recording;
use Modules\VoicemailMessages\Models\VoicemailMessage;
use Modules\Voicemails\Models\Voicemail;

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
        $this->app->bind(BackupServiceInterface::class, BackupService::class);
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

        // Backups module: manually register view namespace and service
        // binding since the module's provider cannot be loaded through
        // bootstrap/providers.php (sandbox composer double-load edge case).
        $this->loadViewsFrom(
            base_path('app-modules/backups/resources/views'),
            'backups',
        );
        $this->app->bind(
            BackupServiceInterface::class,
            BackupService::class,
        );
    }

    /**
     * Bootstrap application services.
     *
     * Protects the primary database and tests, and prevents lazy loading outside production.
     */
    public function boot(): void
    {
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

        Event::listen([
            ChannelCreate::class,
            ChannelAnswer::class,
            ChannelDestroy::class,
            Heartbeat::class,
        ], BroadcastDashboardStatsOnFreeSwitchEvent::class);

        if ($this->app->environment('testing')) {
            TestDatabaseSafety::enforce();
        }

        if (config('app.dusk_testing')) {
            DuskDatabaseSafety::enforce();
        }

        Model::preventLazyLoading(! $this->app->isProduction());

        if ($this->app->runningInConsole()) {
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
                // The full repair already includes generated paths. Avoid a
                // redundant second pass when the repair command itself exits.
                if ($event->command === 'permissions:repair') {
                    return;
                }

                GeneratedFilePermissions::repair();
            });
        }

        // Define Gates after all module providers have registered their permissions
        $this->app->booted(function () {
            $permissionService = $this->app->make(PermissionService::class);

            // Auto-sync permissions to the database so admin.can:* middleware works
            try {
                if (Schema::hasTable('permissions')) {
                    $permissionService->syncToDatabase();
                    // Group assignments remain explicit so revoked permissions stay revoked.
                }
            } catch (\Throwable $e) {
                // Table may not exist yet during initial setup — skip gracefully
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
            \Modules\Extensions\Models\Extension::class,
            \Modules\SipAccounts\Models\SipAccount::class,
            \Modules\RingGroups\Models\RingGroup::class,
            \Modules\RingGroups\Models\RingGroupExtension::class,
            \Modules\InboundRoutes\Models\InboundRoute::class,
            \Modules\Destinations\Models\Destination::class,
            \Modules\OutboundRoutes\Models\OutboundRoute::class,
            \Modules\IvrMenus\Models\IvrMenu::class,
            \Modules\IvrMenus\Models\IvrMenuOption::class,
            \Modules\TimeConditions\Models\TimeCondition::class,
            \Modules\CallFlows\Models\CallFlow::class,
            \Modules\CallBlocks\Models\CallBlock::class,
            \Modules\Bridges\Models\Bridge::class,
            \Modules\FollowMe\Models\FollowMe::class,
            \Modules\HotDesking\Models\HotDeskSession::class,
            \Modules\Voicemails\Models\Voicemail::class,
            \Modules\Conferences\Models\Conference::class,
            \Modules\ConferenceCenters\Models\ConferenceCenter::class,
            \Modules\CallCenters\Models\Queue::class,
            \Modules\CallCenters\Models\Agent::class,
            \Modules\CallCenters\Models\Tier::class,
            \Modules\CallForwards\Models\CallForward::class,
            \Modules\FeatureCodes\Models\FeatureCode::class,
            \Modules\Emergency\Models\Emergency::class,
            \Modules\Dialplans\Models\Dialplan::class,
            \Modules\Dialplans\Models\DialplanDetail::class,
            \Modules\NumberTranslations\Models\NumberTranslation::class,
            \Modules\PinNumbers\Models\PinNumber::class,
            \Modules\TenantLimits\Models\TenantLimit::class,
        ];

        foreach ($telephonyModels as $modelClass) {
            if (class_exists($modelClass)) {
                $modelClass::observe(RoutingCacheObserver::class);
            }
        }
    }
}
