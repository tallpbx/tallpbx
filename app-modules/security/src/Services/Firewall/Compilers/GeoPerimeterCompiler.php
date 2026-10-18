<?php

declare(strict_types=1);

namespace Modules\Security\Services\Firewall\Compilers;

use Modules\Security\Models\SecurityGeoRule;

/**
 * Compiles the dedicated Geo-Blocking perimeter subchain and input chain jump stage.
 */
class GeoPerimeterCompiler
{
    public function __construct(
        protected BlocklistCompiler $blocklistCompiler = new BlocklistCompiler,
    ) {}

    /**
     * Compile the dedicated Geo-Blocking perimeter chain definitions.
     *
     * @return array<int, string>
     */
    public function compileSubchain(
        ?SecurityGeoRule $geoRule,
        bool $firewallEnabled,
        bool $observeMode,
    ): array {
        $geoRule ??= SecurityGeoRule::instance();

        if (! $firewallEnabled || ! $geoRule->enabled || empty($geoRule->countries)) {
            return [];
        }

        $lines = [];
        $lines[] = '    # Dedicated Geo-Blocking Perimeter Chain (drops unpermitted traffic, never accepts)';
        $lines[] = '    chain geo_perimeter {';
        $geoVerdict = $this->blocklistCompiler->dropStatement('geo_blocking', $observeMode, true);

        if ($geoRule->mode === 'whitelist') {
            // In "Block All Except Selected" mode, private LAN/CGNAT/loopback traffic bypasses the country drop without accepting
            $lines[] = '        ip saddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10 } return';
            $lines[] = '        ip6 saddr { fc00::/7, fe80::/10 } return';
            $lines[] = "        ip saddr != @geo_allowed_ips {$geoVerdict}";
            $lines[] = "        ip6 saddr != @geo_allowed_ips6 {$geoVerdict}";
        } else {
            $lines[] = "        ip saddr @geo_blocked_ips {$geoVerdict}";
            $lines[] = "        ip6 saddr @geo_blocked_ips6 {$geoVerdict}";
        }

        $lines[] = '    }';
        $lines[] = '';

        return $lines;
    }

    /**
     * Compile the Stage 9.5 Geo-Blocking jump rule inside chain input.
     *
     * @return array<int, string>
     */
    public function compileInputStage(?SecurityGeoRule $geoRule): array
    {
        $geoRule ??= SecurityGeoRule::instance();

        if (! $geoRule->enabled || empty($geoRule->countries)) {
            return [];
        }

        return [
            '        # STAGE 9.5: GEO-BLOCKING',
            '        jump geo_perimeter',
            '',
        ];
    }
}
