<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('defaults the installer to production data and supports the approved demo choice', function (): void {
    $script = (string) file_get_contents(base_path('scripts/install.sh'));
    $tallScript = (string) file_get_contents(base_path('scripts/resources/tall.sh'));
    $environmentExample = (string) file_get_contents(base_path('.env.example'));
    $demoModePosition = strpos($tallScript, 'set_env_value .env FSPBX_DEMO_MODE');
    $clearCachePosition = strpos($tallScript, 'php artisan optimize:clear');
    $seedPosition = strpos($tallScript, 'php artisan db:seed --force');

    expect($script)->toContain('DEMO_MODE=false')
        ->and($script)->toMatch('/--no-demo\)\s+DEMO_MODE=false/')
        ->and($script)->not->toContain('--demo) DEMO_MODE=true')
        ->and($script)->toContain('resolve_boolean_env_value /var/www/tallpbx/.env FSPBX_DEMO_MODE')
        ->and($script)->toContain('Reusing existing demo data mode: $DEMO_MODE')
        ->and($script)->toContain("default_hint='Y/n'")
        ->and($script)->toContain('prompt_boolean_choice DEMO_MODE "Install demo data?" "$DEMO_MODE"')
        ->and($script)->toContain('export FSPBX_DEMO_MODE="$DEMO_MODE"')
        ->and($environmentExample)->toContain('FSPBX_DEMO_MODE=false')
        ->and($demoModePosition)->not->toBeFalse()
        ->and($clearCachePosition)->not->toBeFalse()
        ->and($seedPosition)->not->toBeFalse()
        ->and($demoModePosition)->toBeLessThan($clearCachePosition)
        ->and($clearCachePosition)->toBeLessThan($seedPosition);
});

it('installs net-tools as a core Debian dependency', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));

    expect($installer)->toMatch('/apt_get_with_lock_wait install -y\\s+\\\\[\\s\\S]*?\\bnet-tools\\b/');
});

it('explains that Enter generates the database password', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));

    expect($installer)->toContain('Press Enter to generate a secure random password.')
        ->and($installer)->toContain('Enter 1 or 2 [1]: ');
});

it('waits for APT locks in every resource script that changes packages', function (): void {
    $resourceScripts = [
        'freeswitch.sh',
        'letsencrypt.sh',
        'mariadb.sh',
        'nginx.sh',
        'php.sh',
    ];

    foreach ($resourceScripts as $resourceScript) {
        $script = (string) file_get_contents(base_path("scripts/resources/{$resourceScript}"));

        expect($script)->not->toContain('apt-get ');
    }
});

it('requires an interactive FreeSWITCH installation method choice and persists it', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));
    $tallScript = (string) file_get_contents(base_path('scripts/resources/tall.sh'));
    $freeSwitchScript = (string) file_get_contents(base_path('scripts/resources/freeswitch.sh'));
    $methodSelectionPosition = strpos($installer, 'prompt_freeswitch_install_method "$FREESWITCH_INSTALL_METHOD"');
    $mariaDbPosition = strpos($installer, 'run_step "MariaDB" resources/mariadb.sh');
    $freeSwitchPosition = strpos($installer, 'run_step "FreeSWITCH" resources/freeswitch.sh');

    expect($installer)->toContain('Choose how to install FreeSWITCH:')
        ->and($installer)->toContain('Source: no SignalWire Personal Access Token is required, but compilation takes longer and software updates require recompilation.')
        ->and($installer)->toContain('Packages: a SignalWire Personal Access Token is required, but installation and updates are faster and easier.')
        ->and($installer)->toContain('FSPBX_FREESWITCH_INSTALL_METHOD')
        ->and($installer)->toContain('FSPBX_FREESWITCH_INSTALLED_METHOD')
        ->and($installer)->toContain('prompt_freeswitch_install_method')
        ->and($installer)->toContain('set_secure_env_value "$INSTALLER_STATE_FILE" FSPBX_FREESWITCH_INSTALL_METHOD')
        ->and($tallScript)->toContain('set_env_value .env FSPBX_FREESWITCH_INSTALL_METHOD "${FSPBX_FREESWITCH_INSTALL_METHOD}"')
        ->and($freeSwitchScript)->toContain('Recompile FreeSWITCH from source? [y/N]')
        ->and($freeSwitchScript)->toContain('source_build_is_installed')
        ->and($freeSwitchScript)->toContain('FSPBX_FREESWITCH_INSTALLED_METHOD')
        ->and($freeSwitchScript)->toContain('disable_tallpbx_source_modules')
        ->and($freeSwitchScript)->toContain('endpoints/mod_verto')
        ->and($freeSwitchScript)->toContain('applications/mod_signalwire')
        ->and($freeSwitchScript)->toContain('set -e')
        ->and($freeSwitchScript)->toContain('git clone https://github.com/signalwire/libks.git /usr/src/libks')
        ->and($freeSwitchScript)->toContain('cmake .')
        ->and($freeSwitchScript)->toContain("pkg-config --exists 'libks2 >= 2.0.11'")
        ->and($freeSwitchScript)->toContain('rm -rf /usr/src/libks')
        ->and($freeSwitchScript)->toContain('libswscale-dev')
        ->and($freeSwitchScript)->toContain('/var/lib/freeswitch/db')
        ->and($freeSwitchScript)->toContain('/var/cache/freeswitch')
        ->and($freeSwitchScript)->toContain('chown -R freeswitch:freeswitch /var/lib/freeswitch')
        ->and(strpos($freeSwitchScript, './bootstrap.sh -j'))->toBeLessThan(strpos($freeSwitchScript, 'enable_tallpbx_source_modules modules.conf'))
        ->and($freeSwitchScript)->toContain('uninstall_freeswitch_packages')
        ->and($freeSwitchScript)->toContain('uninstall_freeswitch_source')
        ->and($freeSwitchScript)->toContain('--prefix=/usr --localstatedir=/var --sysconfdir=/etc')
        ->and($freeSwitchScript)->toContain('enable_tallpbx_source_modules')
        ->and($methodSelectionPosition)->not->toBeFalse()
        ->and($mariaDbPosition)->not->toBeFalse()
        ->and($freeSwitchPosition)->not->toBeFalse()
        ->and($methodSelectionPosition)->toBeLessThan($mariaDbPosition)
        ->and($methodSelectionPosition)->toBeLessThan($freeSwitchPosition);
});

it('accepts the FreeSWITCH installation method from the environment on headless installs', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));
    $environmentReadPosition = strpos($installer, 'requested_freeswitch_install_method="${FSPBX_FREESWITCH_INSTALL_METHOD:-}"');
    $nonInteractiveErrorPosition = strpos($installer, 'A non-interactive install must set FSPBX_FREESWITCH_INSTALL_METHOD');

    expect($installer)->toContain('requested_freeswitch_install_method="${FSPBX_FREESWITCH_INSTALL_METHOD:-}"')
        ->and($installer)->toContain('Using FreeSWITCH install method from the environment')
        ->and($environmentReadPosition)->not->toBeFalse()
        ->and($nonInteractiveErrorPosition)->not->toBeFalse()
        ->and($environmentReadPosition)->toBeLessThan($nonInteractiveErrorPosition);
});

it('defaults to production tooling and supports the approved development choice', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));
    $tallScript = (string) file_get_contents(base_path('scripts/resources/tall.sh'));
    $environmentExample = (string) file_get_contents(base_path('.env.example'));
    $boostConfiguration = json_decode((string) file_get_contents(base_path('boost.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($installer)->toContain('DEVELOPMENT_MODE=false')
        ->and($installer)->toContain('--no-development)')
        ->and($installer)->not->toContain('--development) DEVELOPMENT_MODE=true')
        ->and($installer)->not->toContain('--production) DEVELOPMENT_MODE=false')
        ->and($installer)->toContain('resolve_boolean_env_value /var/www/tallpbx/.env FSPBX_DEVELOPMENT_MODE')
        ->and($installer)->toContain('vendor/laravel/boost/composer.json')
        ->and($installer)->toContain('Preserving installed Laravel Boost development tooling.')
        ->and($installer)->toContain('Reusing existing development mode: $DEVELOPMENT_MODE')
        ->and($installer)->toContain('prompt_boolean_choice DEVELOPMENT_MODE "Install development packages and tooling?" "$DEVELOPMENT_MODE"')
        ->and($installer)->toContain('FSPBX_DEVELOPMENT_MODE="$DEVELOPMENT_MODE"')
        ->and($environmentExample)->toContain('FSPBX_DEVELOPMENT_MODE=false')
        ->and($tallScript)->toContain('set_env_value .env FSPBX_DEVELOPMENT_MODE "${FSPBX_DEVELOPMENT_MODE:-false}"')
        ->and($tallScript)->toContain('composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader')
        ->and($tallScript)->toContain('php artisan boost:install --guidelines --skills --mcp --no-interaction')
        ->and($tallScript)->toContain('npm ci --no-audit --no-fund')
        ->and($tallScript)->not->toContain('php artisan livewire:publish')
        ->and($tallScript)->not->toContain('npm install -D tailwindcss')
        ->and($tallScript)->not->toContain('cat > resources/css/app.css')
        ->and($tallScript)->not->toContain('composer require --no-interaction --dev laravel/boost')
        ->and($tallScript)->not->toContain('composer require --no-interaction livewire/livewire:^4.0')
        ->and($tallScript)->toContain('set_env_value .env APP_ENV production')
        ->and($tallScript)->toContain('set_env_value .env APP_ENV local')
        ->and($tallScript)->toContain('set_env_value .env APP_DEBUG false')
        ->and($tallScript)->toContain('set_env_value .env APP_DEBUG true')
        ->and($boostConfiguration['agents'])->toBe([
            'amp',
            'antigravity',
            'claude_code',
            'codex',
            'copilot',
            'cursor',
            'factory',
            'grok_build',
            'junie',
            'kiro',
            'opencode',
            'pi',
            'zed',
        ]);
});

it('collects the selected initial administrator setup mode during preflight', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));
    $tallScript = (string) file_get_contents(base_path('scripts/resources/tall.sh'));
    $finalPermissionRepairPosition = strrpos($installer, 'php artisan permissions:repair --scope=full');
    $administratorSetupPosition = strpos($installer, 'php artisan initial-admin:configure');
    $summaryPosition = strpos($installer, '# --- Summary ---');

    expect($installer)->toContain('Initial administrator setup')
        ->and($installer)->toContain('Create administrator during installation (default)')
        ->and($installer)->toContain('Create administrator in the web browser with a one-time activation code')
        ->and($installer)->toContain('Create administrator in the web browser without an activation code — trusted network only')
        ->and($installer)->toContain('FSPBX_INITIAL_ADMIN_MODE')
        ->and($installer)->toContain('requested_initial_admin_mode="${FSPBX_INITIAL_ADMIN_MODE:-}"')
        ->and($installer)->toContain('FSPBX_ADMIN_PASSWORD')
        ->and($installer)->toContain('read -rsp "Admin password: "')
        ->and($installer)->toContain('read -rsp "Confirm admin password: "')
        ->and($installer)->not->toContain('Default password:')
        ->and($installer)->not->toContain('Admin password [default:')
        ->and($installer)->toContain('php artisan initial-admin:configure activation-code')
        ->and($installer)->not->toContain('> /dev/tty')
        ->and($tallScript)->not->toContain('initial-admin:configure')
        ->and($tallScript)->not->toContain('php artisan db:seed --force\n\n# Remember that the initial administrator has been seeded successfully');

    expect($finalPermissionRepairPosition)->not->toBeFalse()
        ->and($administratorSetupPosition)->not->toBeFalse()
        ->and($summaryPosition)->not->toBeFalse()
        ->and($finalPermissionRepairPosition)->toBeLessThan($administratorSetupPosition)
        ->and($administratorSetupPosition)->toBeLessThan($summaryPosition);
});

it('restarts PHP-FPM after final application configuration', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));
    $tallStackPosition = strpos($installer, 'run_step "TALL Stack (Laravel, Livewire, Tailwind, DaisyUI)" resources/tall.sh');
    $finalPhpFpmRestartPosition = strrpos($installer, 'systemctl restart "php$php_version-fpm"');
    $administratorSetupPosition = strpos($installer, 'configure_initial_administrator ()');

    expect($tallStackPosition)->not->toBeFalse()
        ->and($finalPhpFpmRestartPosition)->not->toBeFalse()
        ->and($administratorSetupPosition)->not->toBeFalse()
        ->and($tallStackPosition)->toBeLessThan($finalPhpFpmRestartPosition)
        ->and($finalPhpFpmRestartPosition)->toBeLessThan($administratorSetupPosition);
});

it('restores PHP-FPM access to the environment file before booting Laravel during emergency repair', function (): void {
    $repairScript = (string) file_get_contents(base_path('scripts/repair-application-permissions.sh'));
    $environmentRepairPosition = strpos($repairScript, 'repair_environment_file_access');
    $artisanRepairPosition = strpos($repairScript, 'php artisan permissions:repair --scope=full');

    expect($environmentRepairPosition)->not->toBeFalse()
        ->and($artisanRepairPosition)->not->toBeFalse()
        ->and($environmentRepairPosition)->toBeLessThan($artisanRepairPosition);
});

it('restores tracked executable modes after a successful Artisan permission repair', function (): void {
    $repairScript = (string) file_get_contents(base_path('scripts/repair-application-permissions.sh'));

    expect($repairScript)->toMatch('/php artisan permissions:repair --scope=full\); then.*?restore_tracked_modes\s+exit 0/s');
});

it('idempotently writes active Laravel database environment values', function (): void {
    $environmentPath = tempnam(sys_get_temp_dir(), 'pbx-installer-env-');

    expect($environmentPath)->not->toBeFalse();

    file_put_contents($environmentPath, implode("\n", [
        'DB_CONNECTION=sqlite',
        '# DB_HOST=127.0.0.1',
        '#DB_PORT=3306',
        '# DB_DATABASE=laravel',
        '# DB_USERNAME=root',
        '# DB_PASSWORD=',
        '',
    ]));

    $helperPath = escapeshellarg(base_path('scripts/resources/environment.sh'));
    $environmentArgument = escapeshellarg($environmentPath);
    $commands = [
        "set_env_value {$environmentArgument} DB_CONNECTION mysql",
        "set_env_value {$environmentArgument} DB_HOST 127.0.0.1",
        "set_env_value {$environmentArgument} DB_PORT 3306",
        "set_env_value {$environmentArgument} DB_DATABASE tallpbx",
        "set_env_value {$environmentArgument} DB_USERNAME tallpbx",
        "set_env_value {$environmentArgument} DB_PASSWORD safe-password.123",
        "set_env_value {$environmentArgument} CACHE_STORE redis",
    ];
    $command = 'source '.$helperPath.' && '.implode(' && ', [...$commands, ...$commands]);

    try {
        $process = new Process(['bash', '-c', $command], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $environment = (string) file_get_contents($environmentPath);

        expect($environment)->toContain('DB_CONNECTION=mysql')
            ->and($environment)->toContain('DB_HOST=127.0.0.1')
            ->and($environment)->toContain('DB_PORT=3306')
            ->and($environment)->toContain('DB_DATABASE=tallpbx')
            ->and($environment)->toContain('DB_USERNAME=tallpbx')
            ->and($environment)->toContain('DB_PASSWORD=safe-password.123')
            ->and($environment)->toContain('CACHE_STORE=redis')
            ->and(substr_count($environment, 'DB_CONNECTION='))->toBe(1)
            ->and(substr_count($environment, 'DB_HOST='))->toBe(1)
            ->and(substr_count($environment, 'DB_PORT='))->toBe(1)
            ->and(substr_count($environment, 'DB_DATABASE='))->toBe(1)
            ->and(substr_count($environment, 'DB_USERNAME='))->toBe(1)
            ->and(substr_count($environment, 'DB_PASSWORD='))->toBe(1)
            ->and(substr_count($environment, 'CACHE_STORE='))->toBe(1);
    } finally {
        unlink($environmentPath);
    }
});

it('resolves persisted installer modes consistently', function (): void {
    $environmentPath = tempnam(sys_get_temp_dir(), 'pbx-installer-mode-');

    expect($environmentPath)->not->toBeFalse();

    file_put_contents($environmentPath, "FSPBX_DEMO_MODE=true\nFSPBX_DEVELOPMENT_MODE=false\n");

    $helperPath = escapeshellarg(base_path('scripts/resources/environment.sh'));
    $environmentArgument = escapeshellarg($environmentPath);
    $command = implode(' && ', [
        'source '.$helperPath,
        "test \"\$(resolve_boolean_env_value {$environmentArgument} FSPBX_DEMO_MODE)\" = true",
        "test \"\$(resolve_boolean_env_value {$environmentArgument} FSPBX_DEVELOPMENT_MODE)\" = false",
        "! resolve_boolean_env_value {$environmentArgument} MISSING_MODE",
    ]);

    try {
        $process = new Process(['bash', '-c', $command], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    } finally {
        unlink($environmentPath);
    }
});

it('passes the installer database password consistently to every resource step', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));
    $mariadbScript = (string) file_get_contents(base_path('scripts/resources/mariadb.sh'));
    $tallScript = (string) file_get_contents(base_path('scripts/resources/tall.sh'));

    expect($installer)->toContain('export FSPBX_DB_PASSWORD="$database_password"')
        ->and($mariadbScript)->toContain('$FSPBX_DB_PASSWORD')
        ->and($tallScript)->toContain('$FSPBX_DB_PASSWORD')
        ->and($mariadbScript)->not->toContain('$PBX_DB_PASSWORD')
        ->and($tallScript)->not->toContain('$PBX_DB_PASSWORD');
});

it('registers the application clone as a trusted system Git directory before deployment', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));
    $trustPosition = strpos($installer, 'git config --system --add safe.directory "$application_root"');
    $deploymentPosition = strpos($installer, 'run_step "TALL Stack (Laravel, Livewire, Tailwind, DaisyUI)"');

    expect($installer)->toContain('application_root=/var/www/tallpbx')
        ->and($installer)->toContain('git config --system --get-all safe.directory')
        ->and($trustPosition)->not->toBeFalse()
        ->and($deploymentPosition)->not->toBeFalse()
        ->and($trustPosition)->toBeLessThan($deploymentPosition);
});

it('runs installer resource scripts without changing tracked file modes', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));
    $tallScript = (string) file_get_contents(base_path('scripts/resources/tall.sh'));

    expect($installer)->toContain('if bash "$step_script"; then')
        ->and($installer)->not->toContain('chmod +x resources/*.sh')
        ->and($installer)->not->toContain('chmod +x "$step_script"')
        ->and($tallScript)->not->toContain('find /var/www/tallpbx -type f -exec chmod 644')
        ->and($tallScript)->not->toContain('chmod +x /var/www/tallpbx/artisan')
        ->and($tallScript)->toContain('bash /var/www/tallpbx/scripts/resources/freeswitch.sh --configure-only');
});

it('securely persists and reuses the installer database password', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/pbx-installer-state-'.bin2hex(random_bytes(8));
    $statePath = $temporaryDirectory.'/installer.env';
    $applicationEnvironmentPath = $temporaryDirectory.'/application.env';

    mkdir($temporaryDirectory, 0700);
    file_put_contents($applicationEnvironmentPath, "DB_PASSWORD=existing-password.123\n");

    $helperPath = escapeshellarg(base_path('scripts/resources/environment.sh'));
    $stateArgument = escapeshellarg($statePath);
    $environmentArgument = escapeshellarg($applicationEnvironmentPath);
    $command = implode(' && ', [
        'source '.$helperPath,
        "password=\$(resolve_database_password random {$stateArgument} {$environmentArgument})",
        'test "$password" = existing-password.123',
        "set_secure_env_value {$stateArgument} DB_PASSWORD \"\$password\"",
        "password=\$(resolve_database_password random {$stateArgument} /does/not/exist)",
        'test "$password" = existing-password.123',
        "test \"\$(stat -c %a {$stateArgument})\" = 600",
    ]);

    try {
        $process = new Process(['bash', '-c', $command], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and((string) file_get_contents($statePath))->toBe("DB_PASSWORD=existing-password.123\n");
    } finally {
        if (file_exists($statePath)) {
            unlink($statePath);
        }

        unlink($applicationEnvironmentPath);
        rmdir($temporaryDirectory);
    }
});

it('resolves a saved database password before asking for a new one', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));
    $resolvePosition = strpos($installer, 'resolve_database_password');
    $promptPosition = strpos($installer, 'read -rp "Enter 1 or 2 [1]: " password_choice');

    expect($installer)->toContain('PBX_INSTALLER_STATE_FILE')
        ->and($installer)->not->toContain('sed -i "s/^database_password=random$')
        ->and($resolvePosition)->not->toBeFalse()
        ->and($promptPosition)->not->toBeFalse()
        ->and($resolvePosition)->toBeLessThan($promptPosition);
});

it('securely persists and reuses the SignalWire token', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/pbx-signalwire-state-'.bin2hex(random_bytes(8));
    $statePath = $temporaryDirectory.'/installer.env';
    $aptAuthPath = $temporaryDirectory.'/freeswitch.conf';

    mkdir($temporaryDirectory, 0700);
    file_put_contents($aptAuthPath, "machine freeswitch.signalwire.com login signalwire password PT-reusable-token\n");

    $helperPath = escapeshellarg(base_path('scripts/resources/environment.sh'));
    $stateArgument = escapeshellarg($statePath);
    $aptAuthArgument = escapeshellarg($aptAuthPath);
    $command = implode(' && ', [
        'source '.$helperPath,
        "token=\$(resolve_signalwire_token '' {$stateArgument} {$aptAuthArgument})",
        'test "$token" = PT-reusable-token',
        "set_secure_env_value {$stateArgument} SWITCH_TOKEN \"\$token\"",
        "token=\$(resolve_signalwire_token '' {$stateArgument} /does/not/exist)",
        'test "$token" = PT-reusable-token',
        "test \"\$(stat -c %a {$stateArgument})\" = 600",
    ]);

    try {
        $process = new Process(['bash', '-c', $command], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and((string) file_get_contents($statePath))->toBe("SWITCH_TOKEN=PT-reusable-token\n");
    } finally {
        if (file_exists($statePath)) {
            unlink($statePath);
        }

        unlink($aptAuthPath);
        rmdir($temporaryDirectory);
    }
});

it('resolves a saved SignalWire token before asking for a new one', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/freeswitch.sh'));
    $resolvePosition = strpos($script, 'resolve_signalwire_token');
    $promptPosition = strpos($script, "read -rsp \"\$(verbose 'Enter your Personal Access Token: ')\"");

    expect($script)->toContain('PBX_INSTALLER_STATE_FILE')
        ->and($script)->not->toContain('sed -i "s/^switch_token=')
        ->and($resolvePosition)->not->toBeFalse()
        ->and($promptPosition)->not->toBeFalse()
        ->and($resolvePosition)->toBeLessThan($promptPosition);
});

it('deploys the cloned FreeSwitchPBX application before installing dependencies', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/tall.sh'));
    $deploymentPosition = strpos($script, 'Installing FreeSwitchPBX application source');
    $databasePosition = strpos($script, 'set_env_value .env DB_DATABASE');
    $composerPosition = strpos($script, 'composer install --no-interaction --prefer-dist');

    expect($script)->toContain('app/Support/ModuleServiceProvider.php')
        ->and($script)->toContain('git clone "$source_root" "$application_root"')
        ->and($script)->toContain('git -C "$application_root" remote set-url origin "$source_origin_url"')
        ->and($script)->toContain('[ ! -d "$application_root/.git" ]')
        ->and($script)->not->toContain('git -C "$source_root" archive HEAD')
        ->and($script)->not->toContain('composer create-project')
        ->and($deploymentPosition)->not->toBeFalse()
        ->and($databasePosition)->not->toBeFalse()
        ->and($composerPosition)->not->toBeFalse()
        ->and($deploymentPosition)->toBeLessThan($databasePosition)
        ->and($databasePosition)->toBeLessThan($composerPosition);
});

it('preserves encryption and storage link state on installer re-runs', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/tall.sh'));

    expect($script)->toContain("current_app_key=\"$(grep -E '^APP_KEY=' .env")
        ->and($script)->toContain('Preserving existing Laravel APP_KEY')
        ->and($script)->toContain('if [ -z "$current_app_key" ]; then')
        ->and($script)->toContain('php artisan key:generate --force')
        ->and($script)->not->toContain('php artisan key:generate --force 2>/dev/null || true')
        ->and($script)->toContain('if [ -L /var/www/tallpbx/public/storage ]; then')
        ->and($script)->toContain('Laravel public storage link already exists')
        ->and($script)->toContain('public/storage already exists and is not a symlink')
        ->and($script)->toContain('php artisan storage:link');
});

it('installs the root-owned restore helper and its systemd template', function (): void {
    $tallScript = (string) file_get_contents(base_path('scripts/resources/tall.sh'));
    $restoreHelper = (string) file_get_contents(base_path('scripts/resources/tallpbx-restore'));
    $restoreUnit = (string) file_get_contents(base_path('scripts/resources/tallpbx-restore.service'));

    expect($restoreHelper)->toContain('php artisan backups:restore-operation "$operation_id"')
        ->and($restoreHelper)->toContain('invalid restore operation UUID')
        ->and($restoreUnit)->toContain('ExecStart=/usr/local/sbin/tallpbx-restore %i')
        ->and($restoreUnit)->toContain('User=root')
        ->and($tallScript)->toContain('install -m 0700 /var/www/tallpbx/scripts/resources/tallpbx-restore /usr/local/sbin/tallpbx-restore')
        ->and($tallScript)->toContain('install -m 0644 /var/www/tallpbx/scripts/resources/tallpbx-restore.service /etc/systemd/system/tallpbx-restore@.service')
        ->and($tallScript)->toContain('systemctl daemon-reload');
});

it('rejects non-UUID restore helper arguments before Laravel is invoked', function (): void {
    $process = new Process([
        'bash',
        base_path('scripts/resources/tallpbx-restore'),
        'aaaaaaaa-aaaa',
    ], base_path());

    $process->run();

    expect($process->getExitCode())->toBe(64)
        ->and($process->getErrorOutput())->toContain('invalid restore operation UUID');
});

it('installs the restore request dispatcher without granting the web worker systemctl access', function (): void {
    $tallScript = (string) file_get_contents(base_path('scripts/resources/tall.sh'));
    $dispatcher = (string) file_get_contents(base_path('scripts/resources/tallpbx-restore-dispatch'));
    $pathUnit = (string) file_get_contents(base_path('scripts/resources/tallpbx-restore-dispatch.path'));

    expect($tallScript)->toContain('install -d -m 0770 -o root -g www-data /var/lib/tallpbx/restore-requests')
        ->and($tallScript)->toContain('systemctl enable --now tallpbx-restore-dispatch.path')
        ->and($dispatcher)->toContain('systemctl start "tallpbx-restore@${operation_id}.service"')
        ->and($dispatcher)->toContain('*.request')
        ->and($pathUnit)->toContain('PathChanged=/var/lib/tallpbx/restore-requests');
});

it('installs and enables FreeSWITCH modules needed by the default PBX runtime', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/freeswitch.sh'));

    expect($script)->toContain('freeswitch-mod-xml-curl')
        ->and($script)->toContain('freeswitch-mod-sofia')
        ->and($script)->toContain('freeswitch-mod-callcenter')
        ->and($script)->toContain('freeswitch-mod-dptools')
        ->and($script)->toContain('freeswitch-mod-hiredis')
        ->and($script)->toContain('install_freeswitch_hiredis_package')
        ->and($script)->toContain('libhiredis1.1.0')
        ->and($script)->toContain('dpkg --ignore-depends=libhiredis0.10,libhiredis0.13,libhiredis0.14')
        ->and($script)->toContain('Package: libhiredis0.14')
        ->and($script)->toContain('apt_get_with_lock_wait check')
        ->and($script)->toContain('freeswitch-mod-local-stream')
        ->and($script)->toContain('freeswitch-mod-sndfile')
        ->and($script)->toContain('freeswitch-mod-voicemail')
        ->and($script)->toContain('freeswitch-mod-png')
        ->and($script)->toContain('freeswitch-mod-opus')
        ->and($script)->toContain('freeswitch-mod-av')
        ->and($script)->toContain('freeswitch-mod-rtc')
        ->and($script)->toContain('freeswitch-mod-verto')
        ->and($script)->toContain('freeswitch-mod-signalwire')
        ->and($script)->toContain('ensure_freeswitch_module_enabled')
        ->and($script)->toContain('ensure_freeswitch_module_enabled "$modules_conf" "mod_sofia"')
        ->and($script)->toContain('ensure_freeswitch_module_enabled "$modules_conf" "mod_callcenter"')
        ->and($script)->toContain('ensure_freeswitch_module_enabled "$modules_conf" "mod_xml_curl"')
        ->and($script)->toContain('ensure_freeswitch_module_enabled "$modules_conf" "mod_hiredis"')
        ->and($script)->toContain('ensure_freeswitch_module_enabled "$modules_conf" "mod_voicemail"')
        ->and($script)->toContain('disable_freeswitch_module "$modules_conf" "mod_redis"')
        ->and($script)->toContain('disable_freeswitch_module "$modules_conf" "mod_memcache"')
        ->and($script)->toContain("fs_cli -x 'load mod_sofia'")
        ->and($script)->toContain("fs_cli -x 'load mod_callcenter'")
        ->and($script)->toContain("fs_cli -x 'load mod_hiredis'")
        ->and($script)->toContain("fs_cli -x 'load mod_voicemail'")
        ->and($script)->toContain("fs_cli -x 'unload mod_redis'")
        ->and($script)->toContain("fs_cli -x 'unload mod_memcache'")
        ->and($script)->toContain('bindings="directory|dialplan|configuration"')
        ->and($script)->toContain('FREESWITCH_XML_HANDLER_TOKEN');
});

it('configures Laravel XML handler defaults before reconciling FreeSWITCH XML curl', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/tall.sh'));
    $envExample = (string) file_get_contents(base_path('.env.example'));
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));

    expect($script)->toContain('FREESWITCH_XML_HANDLER_AUTH=true')
        ->and($script)->toContain('FREESWITCH_XML_HANDLER_PATH=/api/v1/xml-handler')
        ->and($script)->toContain('FREESWITCH_XML_HANDLER_LOG_REQUESTS=false')
        ->and($script)->toContain('FREESWITCH_SERVER=http://127.0.0.1')
        ->and($script)->toContain('FREESWITCH_DEFAULT_SIP_REALM=127.0.0.1')
        ->and($script)->toContain('PBX_DEFAULT_SIP_PASSWORD')
        ->and($script)->toContain('cut -d= -f2-')
        ->and($script)->toContain('bin2hex(random_bytes(32))')
        ->and($script)->toContain('bin2hex(random_bytes(12))')
        ->and($script)->toContain('FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE=redis')
        ->and($script)->toContain('FREESWITCH_HIREDIS_DIALPLAN_LIMIT_ENABLED=false')
        ->and($script)->toContain('FREESWITCH_HIREDIS_DIALPLAN_LIMIT_MAX=100000')
        ->and($script)->toContain('FREESWITCH_HIREDIS_DIALPLAN_MARKER_ENABLED=false')
        ->and($script)->toContain('php artisan db:seed --force')
        ->and($script)->toContain('freeswitch.sh --configure-only')
        ->and($envExample)->toContain('FREESWITCH_SERVER=http://127.0.0.1')
        ->and($envExample)->toContain('FREESWITCH_DEFAULT_SIP_REALM=127.0.0.1')
        ->and($envExample)->toContain('FREESWITCH_XML_HANDLER_AUTH=true')
        ->and($envExample)->toContain('FREESWITCH_XML_HANDLER_PATH=/api/v1/xml-handler')
        ->and($envExample)->toContain('FREESWITCH_XML_HANDLER_TOKEN=')
        ->and($envExample)->toContain('FREESWITCH_XML_HANDLER_LOG_REQUESTS=false')
        ->and($envExample)->toContain('FREESWITCH_HIREDIS_DIALPLAN_LIMIT_ENABLED=false')
        ->and($envExample)->toContain('FREESWITCH_HIREDIS_DIALPLAN_LIMIT_MAX=100000')
        ->and($envExample)->toContain('FREESWITCH_HIREDIS_DIALPLAN_MARKER_ENABLED=false')
        ->and($envExample)->toContain('PBX_DEFAULT_SIP_PASSWORD=')
        ->and($installer)->toContain('Confirm FreeSWITCH is running')
        ->and($installer)->not->toContain('Configure FreeSWITCH integration');
});

it('leaves tracked Dusk fixtures and package metadata unchanged during installation', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/tall.sh'));
    $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($script)->not->toContain('set_env_value .env.dusk')
        ->and($package['name'])->toBe('tallpbx')
        ->and($lock['name'])->toBe($package['name'])
        ->and($lock['packages']['']['name'])->toBe($package['name']);
});

it('prepares the isolated Dusk database with the shared administrator seeder', function (): void {
    $script = (string) file_get_contents(base_path('scripts/dusk.sh'));

    expect($script)->toContain('php artisan migrate --force --env=dusk')
        ->and($script)->toContain('php artisan db:seed --class=AdminSeeder --force --env=dusk')
        ->and($script)->toContain('chromedriver --port="$DUSK_CHROMEDRIVER_PORT"');
});

it('tracks .env.dusk.example without secrets and auto-provisions isolated test key in dusk.sh', function (): void {
    $duskExample = (string) file_get_contents(base_path('.env.dusk.example'));
    $gitignore = (string) file_get_contents(base_path('.gitignore'));
    $script = (string) file_get_contents(base_path('scripts/dusk.sh'));

    expect($duskExample)->toContain('APP_KEY=')
        ->and($duskExample)->not->toMatch('/^APP_KEY=.+/m')
        ->and($gitignore)->toContain('.env.dusk')
        ->and($script)->toContain('cp .env.dusk.example .env.dusk')
        ->and($script)->toContain('php artisan key:generate --env=dusk --force');
});

it('stops the TALL setup when migrations or seeding fail', function (): void {
    $script = (string) file_get_contents(base_path('scripts/resources/tall.sh'));
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));

    expect($script)->toContain('set -e')
        ->and($script)->toContain('php artisan migrate --force')
        ->and($script)->toContain('php artisan db:seed --force')
        ->and($installer)->toContain('if ! run_step "TALL Stack (Laravel, Livewire, Tailwind, DaisyUI)" resources/tall.sh; then')
        ->and($installer)->toContain('Stopping because the application setup failed.')
        ->and($installer)->toContain('Installation finished with ${#FAILED_STEPS[@]} failed step(s):');
});

it('waits for APT locks and stops before continuing when a package command fails', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));
    $environment = (string) file_get_contents(base_path('scripts/resources/environment.sh'));

    expect($environment)->toContain('apt_get_with_lock_wait ()')
        ->and($environment)->toContain('DPkg::Lock::Timeout=')
        ->and($environment)->toContain('Unable to complete the required APT command after waiting for the package manager lock.')
        ->and($installer)->toContain('apt_get_with_lock_wait update')
        ->and($installer)->toContain('apt_get_with_lock_wait upgrade -y')
        ->and($installer)->toContain('apt_get_with_lock_wait install -y \\')
        ->and($installer)->not->toContain('apt-get update && apt-get upgrade -y');
});

it('installs and enables the FreeSWITCH ESL listener with managed media write access', function (): void {
    $unit = (string) file_get_contents(base_path('scripts/freeswitch-listener.service'));
    $tallScript = (string) file_get_contents(base_path('scripts/resources/tall.sh'));

    expect($unit)->toContain('User=www-data')
        ->and($unit)->toContain('ProtectSystem=strict')
        ->and($unit)->toContain('ReadWritePaths=/var/www/tallpbx/storage /var/lib/tallpbx/media')
        ->and($tallScript)->toContain('cp /var/www/tallpbx/scripts/freeswitch-listener.service /etc/systemd/system/');
});

it('provisions separate media and backup roots with restricted access', function (): void {
    $tallScript = (string) file_get_contents(base_path('scripts/resources/tall.sh'));
    $freeSwitchScript = (string) file_get_contents(base_path('scripts/resources/freeswitch.sh'));
    $environmentExample = (string) file_get_contents(base_path('.env.example'));

    expect($environmentExample)->toContain('TALLPBX_MEDIA_ROOT=/var/lib/tallpbx/media')
        ->and($environmentExample)->toContain('TALLPBX_BACKUP_ROOT=/var/lib/tallpbx/backups')
        ->and($tallScript)->toContain('get_env_value .env TALLPBX_MEDIA_ROOT')
        ->and($tallScript)->toContain('set_env_value .env TALLPBX_MEDIA_ROOT "$media_root"')
        ->and($tallScript)->toContain('get_env_value .env TALLPBX_BACKUP_ROOT')
        ->and($tallScript)->toContain('set_env_value .env TALLPBX_BACKUP_ROOT "$backup_root"')
        ->and($tallScript)->toContain('install -d -m 0711 -o root -g root /var/lib/tallpbx')
        ->and($tallScript)->toContain('install -d -m 2770 -o root -g www-data "$backup_root"')
        ->and($freeSwitchScript)->toContain('configure_tallpbx_media_root')
        ->and($freeSwitchScript)->toContain('groupadd --system tallpbx-media')
        ->and($freeSwitchScript)->toContain('usermod -aG tallpbx-media "$service_user"')
        ->and($freeSwitchScript)->toContain('media_root="/var/lib/tallpbx/media"')
        ->and($freeSwitchScript)->toContain('"$media_root/store"')
        ->and($freeSwitchScript)->toContain('"$media_root/spool"')
        ->and($freeSwitchScript)->toContain('-m 2775')
        ->and($freeSwitchScript)->toContain('--configure-only');
});

it('runs FreeSWITCH with the shared media group so it can write managed media', function (): void {
    $freeSwitchScript = (string) file_get_contents(base_path('scripts/resources/freeswitch.sh'));

    // FreeSWITCH drops supplementary groups at startup, so the shared media
    // group must be its primary runtime group; the unit's optional
    // EnvironmentFile override is the supported way to set it. The UMask
    // keeps files and directories FreeSWITCH creates group-writable so the
    // web application can move them out during archival.
    expect($freeSwitchScript)->toContain('GROUP=tallpbx-media')
        ->and($freeSwitchScript)->toContain('/etc/default/freeswitch')
        ->and($freeSwitchScript)->toContain('install -d -m 2775 -o www-data -g tallpbx-media')
        ->and($freeSwitchScript)->toContain('UMask=0002');
});

it('neutralizes the stock FreeSWITCH directory so app-managed users resolve first', function (): void {
    $freeSwitchScript = (string) file_get_contents(base_path('scripts/resources/freeswitch.sh'));

    // The packaged directory (default.xml, default/1000-1019.xml) shadows the
    // app-managed directory for local-first lookups; move it aside on install.
    expect($freeSwitchScript)->toContain('directory.stock')
        ->and($freeSwitchScript)->toContain('freeswitch_conf_dir');
});

it('installs the queue worker and scheduler units so jobs and sweeps run', function (): void {
    $tallScript = (string) file_get_contents(base_path('scripts/resources/tall.sh'));
    $queueUnit = (string) file_get_contents(base_path('scripts/tallpbx-queue.service'));
    $schedulerUnit = (string) file_get_contents(base_path('scripts/tallpbx-scheduler.service'));

    // Without these units a fresh install never runs queue jobs (archive
    // transfers, notifications) or the media/broadcast reconcile sweeps.
    expect($tallScript)->toContain('cp /var/www/tallpbx/scripts/tallpbx-queue.service /etc/systemd/system/')
        ->and($tallScript)->toContain('cp /var/www/tallpbx/scripts/tallpbx-scheduler.service /etc/systemd/system/')
        ->and($tallScript)->toContain('systemctl enable tallpbx-queue')
        ->and($tallScript)->toContain('systemctl enable tallpbx-scheduler')
        ->and($queueUnit)->toContain('queue:work')
        ->and($queueUnit)->toContain('/var/lib/tallpbx/backups')
        ->and($queueUnit)->toContain('/var/lib/tallpbx/restore-requests')
        ->and($schedulerUnit)->toContain('schedule:work');
});
