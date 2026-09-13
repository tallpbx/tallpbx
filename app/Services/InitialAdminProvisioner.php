<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InitialAdminProvisioningException;
use App\Models\Admin;
use App\Models\Group;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates TallPBX's first super administrator through the installer or setup page.
 */
class InitialAdminProvisioner
{
    public const MODE_INSTALLER = 'installer';

    public const MODE_ACTIVATION_CODE = 'activation-code';

    public const MODE_TRUSTED_NETWORK = 'trusted-network';

    private const SETTING_KEY = 'initial_admin.setup';

    /**
     * Ensure a fresh installation has a persistent setup state record.
     */
    public function ensureSetupState(): void
    {
        Setting::system()->firstOrCreate(
            ['key' => self::SETTING_KEY],
            [
                'value' => $this->encodeState([
                    'status' => Admin::query()->exists() ? 'completed' : 'pending',
                    'mode' => self::MODE_TRUSTED_NETWORK,
                    'activation_code_hash' => null,
                ]),
                'type' => 'json',
            ],
        );
    }

    /**
     * Create the first administrator using credentials collected by the installer.
     */
    public function provisionFromInstaller(string $email, string $password): Admin
    {
        return DB::transaction(function () use ($email, $password): Admin {
            $state = $this->lockedState();

            $this->assertAdministratorIsPending($state);
            $this->saveState($state, self::MODE_INSTALLER, 'pending', null);

            return $this->createAdministrator($state, $email, $password);
        });
    }

    /**
     * Select a browser setup mode and return a one-time activation code when needed.
     */
    public function configureBrowserSetup(string $mode): ?string
    {
        if (! in_array($mode, [self::MODE_ACTIVATION_CODE, self::MODE_TRUSTED_NETWORK], true)) {
            throw new InitialAdminProvisioningException('The selected browser setup mode is not supported.');
        }

        return DB::transaction(function () use ($mode): ?string {
            $state = $this->lockedState();

            $this->assertAdministratorIsPending($state);

            $currentState = $this->decodeState($state);

            if ($mode === self::MODE_TRUSTED_NETWORK) {
                if (($currentState['mode'] ?? null) === self::MODE_ACTIVATION_CODE
                    && is_string($currentState['activation_code_hash'] ?? null)) {
                    throw new InitialAdminProvisioningException('An activation code is already pending and will not be replaced on a re-run.');
                }

                $this->saveState($state, $mode, 'pending', null);

                return null;
            }

            if (($currentState['mode'] ?? null) === self::MODE_ACTIVATION_CODE
                && is_string($currentState['activation_code_hash'] ?? null)) {
                return null;
            }

            $activationCode = bin2hex(random_bytes(32));
            $this->saveState($state, $mode, 'pending', Hash::make($activationCode));

            return $activationCode;
        });
    }

    /**
     * Return the current browser setup mode, or null when setup is unavailable.
     */
    public function browserSetupMode(): ?string
    {
        if (Admin::query()->exists()) {
            return null;
        }

        $this->ensureSetupState();
        $state = Setting::system()->where('key', self::SETTING_KEY)->firstOrFail();
        $data = $this->decodeState($state);

        if (($data['status'] ?? null) !== 'pending') {
            return null;
        }

        $mode = $data['mode'] ?? self::MODE_TRUSTED_NETWORK;

        return in_array($mode, [self::MODE_ACTIVATION_CODE, self::MODE_TRUSTED_NETWORK], true) ? $mode : null;
    }

    /**
     * Create the first administrator from the browser after checking its selected gate.
     */
    public function provisionFromBrowser(string $email, string $password, ?string $activationCode): Admin
    {
        return DB::transaction(function () use ($email, $password, $activationCode): Admin {
            $state = $this->lockedState();
            $data = $this->decodeState($state);

            $this->assertAdministratorIsPending($state);

            if (($data['mode'] ?? null) === self::MODE_ACTIVATION_CODE) {
                $hash = $data['activation_code_hash'] ?? null;

                if (! is_string($hash) || ! is_string($activationCode) || ! Hash::check($activationCode, $hash)) {
                    throw new InitialAdminProvisioningException('The activation code is invalid.');
                }
            } elseif (($data['mode'] ?? null) !== self::MODE_TRUSTED_NETWORK) {
                throw new InitialAdminProvisioningException('Browser administrator setup is not enabled.');
            }

            return $this->createAdministrator($state, $email, $password);
        });
    }

    /**
     * Lock and return the singleton setup state so competing requests cannot both create an admin.
     */
    private function lockedState(): Setting
    {
        $this->ensureSetupState();

        return Setting::system()
            ->where('key', self::SETTING_KEY)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Stop setup when an administrator already exists or setup was completed.
     */
    private function assertAdministratorIsPending(Setting $state): void
    {
        $data = $this->decodeState($state);

        if (Admin::query()->lockForUpdate()->exists() || ($data['status'] ?? null) !== 'pending') {
            throw new InitialAdminProvisioningException('An administrator has already been created.');
        }
    }

    /**
     * Create the admin, grant the superadmin group, and permanently close setup.
     */
    private function createAdministrator(Setting $state, string $email, string $password): Admin
    {
        $superAdminGroup = Group::query()
            ->where('name', 'Super Administrators')
            ->whereNull('tenant_id')
            ->firstOrFail();

        $admin = Admin::create([
            'name' => 'System Administrator',
            'email' => $email,
            'password' => Hash::make($password),
            'enabled' => true,
        ]);

        $admin->groups()->attach($superAdminGroup->id);
        $this->saveState(
            $state,
            (string) ($this->decodeState($state)['mode'] ?? self::MODE_INSTALLER),
            'completed',
            null,
        );

        return $admin;
    }

    /**
     * Save the setup state without keeping an activation code after completion.
     */
    private function saveState(Setting $state, string $mode, string $status, ?string $activationCodeHash): void
    {
        $state->update([
            'value' => $this->encodeState([
                'status' => $status,
                'mode' => $mode,
                // Completed setup omits the key entirely so a database export
                // cannot be mistaken for a still-usable activation code.
                ...($activationCodeHash === null && $status === 'completed'
                    ? []
                    : ['activation_code_hash' => $activationCodeHash]),
            ]),
            'type' => 'json',
        ]);
    }

    /**
     * Decode the stored state while failing closed if it is malformed.
     *
     * @return array<string, mixed>
     */
    private function decodeState(Setting $state): array
    {
        $data = json_decode((string) $state->value, true);

        if (! is_array($data)) {
            throw new InitialAdminProvisioningException('The initial administrator setup state is invalid.');
        }

        return $data;
    }

    /**
     * Convert setup state into its database representation.
     *
     * @param  array<string, mixed>  $state
     */
    private function encodeState(array $state): string
    {
        return json_encode($state, JSON_THROW_ON_ERROR);
    }
}
