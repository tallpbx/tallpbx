<?php

declare(strict_types=1);

namespace App\Support;

use Modules\NumberTranslations\Providers\ModuleServiceProvider;

final class TelephonyWorkerProviders
{
    /**
     * Module providers required to render FreeSWITCH XML (directory, dialplan, sofia).
     * Excludes all 40+ panel modules, billing, audit logs, and web UI modules.
     *
     * @var list<class-string>
     */
    public const MODULE_PROVIDERS = [
        ModuleServiceProvider::class,
        \Modules\SipProfiles\Providers\ModuleServiceProvider::class,
        \Modules\FileStores\Providers\ModuleServiceProvider::class,
        \Modules\Gateways\Providers\ModuleServiceProvider::class,
        \Modules\Extensions\Providers\ModuleServiceProvider::class,
        \Modules\Bridges\Providers\ModuleServiceProvider::class,
        \Modules\CallBlocks\Providers\ModuleServiceProvider::class,
        \Modules\CallCenters\Providers\ModuleServiceProvider::class,
        \Modules\CallFlows\Providers\ModuleServiceProvider::class,
        \Modules\CallForwards\Providers\ModuleServiceProvider::class,
        \Modules\ConferenceCenters\Providers\ModuleServiceProvider::class,
        \Modules\Conferences\Providers\ModuleServiceProvider::class,
        \Modules\Emergency\Providers\ModuleServiceProvider::class,
        \Modules\FeatureCodes\Providers\ModuleServiceProvider::class,
        \Modules\FollowMe\Providers\ModuleServiceProvider::class,
        \Modules\HotDesking\Providers\ModuleServiceProvider::class,
        \Modules\InboundRoutes\Providers\ModuleServiceProvider::class,
        \Modules\IvrMenus\Providers\ModuleServiceProvider::class,
        \Modules\OutboundRoutes\Providers\ModuleServiceProvider::class,
        \Modules\PinNumbers\Providers\ModuleServiceProvider::class,
        \Modules\RingGroups\Providers\ModuleServiceProvider::class,
        \Modules\Security\Providers\ModuleServiceProvider::class,
        \Modules\TimeConditions\Providers\ModuleServiceProvider::class,
        \Modules\Voicemails\Providers\ModuleServiceProvider::class,
    ];
}
