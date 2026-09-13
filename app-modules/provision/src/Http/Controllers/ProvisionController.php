<?php

declare(strict_types=1);

namespace Modules\Provision\Http\Controllers;

use Illuminate\Http\Response;
use Modules\Provision\Services\ProvisionServiceInterface;

/**
 * HTTP controller for the public device provisioning endpoint.
 *
 * Handles the /provision/{mac} route — looks up the device by MAC
 * address and returns vendor-specific configuration rendered from
 * Blade templates (e.g., Grandstream CFG, Polycom XML).
 */
class ProvisionController
{
    /**
     * Look up a device by MAC address and return its rendered provisioning config.
     *
     * The response Content-Type is set to text/plain so that phones
     * receive raw config text (CFG, XML) without HTML wrapping.
     */
    public function __invoke(string $mac, ProvisionServiceInterface $provision): Response
    {
        $result = $provision->provision($mac);

        return response($result['content'], 200, [
            'Content-Type' => $result['content_type'],
        ]);
    }
}
