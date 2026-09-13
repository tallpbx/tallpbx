<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\InitialAdminProvisioningException;
use App\Services\InitialAdminProvisioner;
use Illuminate\Console\Command;

/**
 * Prepares or creates TallPBX's first administrator through the shared PHP service.
 */
class ConfigureInitialAdminCommand extends Command
{
    protected $signature = 'initial-admin:configure
        {mode : installer, activation-code, or trusted-network}';

    protected $description = 'Configure how TallPBX creates its first administrator';

    /**
     * Configure the selected setup mode without duplicating setup logic in Bash.
     */
    public function handle(InitialAdminProvisioner $provisioner): int
    {
        try {
            return match ((string) $this->argument('mode')) {
                InitialAdminProvisioner::MODE_INSTALLER => $this->provisionFromInstaller($provisioner),
                InitialAdminProvisioner::MODE_ACTIVATION_CODE,
                InitialAdminProvisioner::MODE_TRUSTED_NETWORK => $this->prepareBrowserSetup($provisioner),
                default => $this->unsupportedMode(),
            };
        } catch (InitialAdminProvisioningException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Create an administrator from the credentials that the installer collected securely.
     */
    private function provisionFromInstaller(InitialAdminProvisioner $provisioner): int
    {
        $email = (string) getenv('FSPBX_ADMIN_USERNAME');
        $password = (string) getenv('FSPBX_ADMIN_PASSWORD');

        if ($email === '' || $password === '') {
            $this->components->error('Installer administrator credentials are required for installer mode.');

            return self::FAILURE;
        }

        $provisioner->provisionFromInstaller($email, $password);
        $this->components->info('Initial administrator created.');

        return self::SUCCESS;
    }

    /**
     * Prepare a browser setup mode and display an activation code only when one is required.
     */
    private function prepareBrowserSetup(InitialAdminProvisioner $provisioner): int
    {
        $mode = (string) $this->argument('mode');
        $activationCode = $provisioner->configureBrowserSetup($mode);

        if ($activationCode !== null) {
            $this->components->warn('One-time administrator activation code: '.$activationCode);
            $this->line('Open /panel/setup in a web browser and enter this code to create the administrator.');
        } elseif ($mode === InitialAdminProvisioner::MODE_ACTIVATION_CODE) {
            $this->line('An activation code is already pending. It is preserved and not replaced on this re-run.');
        } else {
            $this->line('Open /panel/setup in a trusted web browser to create the administrator.');
        }

        return self::SUCCESS;
    }

    /**
     * Report a mode name that does not correspond to a supported installer choice.
     */
    private function unsupportedMode(): int
    {
        $this->components->error('Mode must be installer, activation-code, or trusted-network.');

        return self::FAILURE;
    }
}
