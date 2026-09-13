<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Manually creates or updates administrator credentials from the command line.
 *
 * Supports both interactive prompts and non-interactive arguments/options.
 * Automatically attaches the Super Administrators system group to guarantee
 * administrative access.
 */
class ManageAdminCredentialsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'admin:credentials
        {email? : The administrator email address}
        {--name= : The administrator full name}
        {--password= : The administrator password}
        {--enable : Explicitly enable the administrator account}';

    /**
     * The console command description.
     */
    protected $description = 'Create or update administrator credentials directly from the command line';

    /**
     * Command aliases for convenient administration.
     *
     * @var array<int, string>
     */
    protected $aliases = [
        'admin:password',
        'admin:user',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) ($this->argument('email') ?? '');

        if ($email === '') {
            $email = (string) $this->ask('Administrator email address');
        }

        $email = trim($email);

        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'string', 'email'],
        ]);

        if ($validator->fails()) {
            $this->components->error('A valid email address is required.');

            return self::FAILURE;
        }

        /** @var Admin|null $admin */
        $admin = Admin::where('email', $email)->first();

        if ($admin !== null) {
            return $this->updateExistingAdmin($admin);
        }

        return $this->createNewAdmin($email);
    }

    /**
     * Update credentials for an existing administrator account.
     */
    private function updateExistingAdmin(Admin $admin): int
    {
        $this->components->info("Found existing administrator: {$admin->name} <{$admin->email}>");

        $name = $this->option('name');
        if (is_string($name) && trim($name) !== '') {
            $admin->name = trim($name);
        }

        $password = $this->resolvePassword('Enter new password');
        if ($password === false) {
            return self::FAILURE;
        }

        if ($password !== null) {
            $admin->password = $password;
        }

        $admin->enabled = true;
        $admin->save();

        $this->ensureSuperAdminGroup($admin);
        $this->completeInitialSetupState();

        $this->components->info("Administrator [{$admin->email}] credentials updated successfully.");

        return self::SUCCESS;
    }

    /**
     * Create a new administrator account with Super Administrator privileges.
     */
    private function createNewAdmin(string $email): int
    {
        $password = $this->resolvePassword('Password');
        if ($password === false || $password === null) {
            $this->components->error('A password of at least 8 characters is required to create a new administrator.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?? '');

        if ($name === '' && $this->input->isInteractive()) {
            $name = (string) $this->ask('Administrator full name', 'Administrator');
        }

        if ($name === '') {
            $name = 'Administrator';
        }

        $admin = Admin::create([
            'name' => trim($name),
            'email' => $email,
            'password' => $password,
            'enabled' => true,
        ]);

        $this->ensureSuperAdminGroup($admin);
        $this->completeInitialSetupState();

        $this->components->info("New administrator [{$admin->name} <{$admin->email}>] created successfully with Super Administrator privileges.");

        return self::SUCCESS;
    }

    /**
     * Resolve and validate password from command option or interactive prompts.
     *
     * Returns string password on success, null if optional and not provided,
     * or false if validation failed.
     */
    private function resolvePassword(string $prompt): string|null|false
    {
        $password = $this->option('password');

        if (is_string($password) && $password !== '') {
            if (strlen($password) < 8) {
                $this->components->error('The password must be at least 8 characters.');

                return false;
            }

            return $password;
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $password = (string) $this->secret($prompt);

        if ($password === '') {
            return null;
        }

        if (strlen($password) < 8) {
            $this->components->error('The password must be at least 8 characters.');

            return false;
        }

        $confirmation = (string) $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->components->error('Password confirmation does not match.');

            return false;
        }

        return $password;
    }

    /**
     * Ensure the administrator belongs to the Super Administrators system group.
     */
    private function ensureSuperAdminGroup(Admin $admin): void
    {
        $superAdminGroup = Group::where('name', 'Super Administrators')
            ->whereNull('tenant_id')
            ->first();

        if ($superAdminGroup !== null && ! $admin->groups()->where('groups.id', $superAdminGroup->id)->exists()) {
            $admin->groups()->attach($superAdminGroup->id);
        }
    }

    /**
     * Mark the initial admin setup state as completed so web setup gates are resolved.
     */
    private function completeInitialSetupState(): void
    {
        $setting = Setting::system()->where('key', 'initial_admin.setup')->first();

        if ($setting !== null) {
            $setting->update([
                'value' => json_encode([
                    'status' => 'completed',
                    'mode' => 'installer',
                    'activation_code_hash' => null,
                ], JSON_THROW_ON_ERROR),
                'type' => 'json',
            ]);
        }
    }
}
