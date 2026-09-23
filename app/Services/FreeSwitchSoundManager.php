<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ReloadFreeSwitchXml;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Manages FreeSWITCH sound prompt language packages, grammar modules,
 * and the system default sound prompt language configuration.
 */
class FreeSwitchSoundManager
{
    /**
     * Map of supported sound languages, dialects, voices, and package metadata.
     *
     * @var array<string, array{code: string, name: string, dialect: string, voice: string, package: string, say_package: string, say_module: string, sound_path: string, tarball_prefix: string}>
     */
    protected const SUPPORTED_LANGUAGES = [
        'en' => [
            'code' => 'en',
            'name' => 'English',
            'dialect' => 'us',
            'voice' => 'callie',
            'package' => 'freeswitch-sounds-en-us-callie',
            'say_package' => 'freeswitch-mod-say-en',
            'say_module' => 'mod_say_en',
            'sound_path' => 'en/us/callie',
            'tarball_prefix' => 'freeswitch-sounds-en-us-callie',
        ],
        'es' => [
            'code' => 'es',
            'name' => 'Spanish',
            'dialect' => 'ar',
            'voice' => 'mario',
            'package' => 'freeswitch-sounds-es-ar-mario',
            'say_package' => 'freeswitch-mod-say-es',
            'say_module' => 'mod_say_es',
            'sound_path' => 'es/ar/mario',
            'tarball_prefix' => 'freeswitch-sounds-es-ar-mario',
        ],
        'fr' => [
            'code' => 'fr',
            'name' => 'French',
            'dialect' => 'ca',
            'voice' => 'june',
            'package' => 'freeswitch-sounds-fr-ca-june',
            'say_package' => 'freeswitch-mod-say-fr',
            'say_module' => 'mod_say_fr',
            'sound_path' => 'fr/ca/june',
            'tarball_prefix' => 'freeswitch-sounds-fr-ca-june',
        ],
    ];

    /**
     * Create a new sound manager instance.
     */
    public function __construct(
        protected ?FreeSwitchServiceInterface $freeSwitch = null,
        protected ?string $soundsDirectory = null,
        protected ?string $confDirectory = null,
    ) {}

    /**
     * Return all supported language definitions.
     *
     * @return array<string, array{code: string, name: string, dialect: string, voice: string, package: string, say_package: string, say_module: string, sound_path: string, tarball_prefix: string}>
     */
    public function getSupportedLanguages(): array
    {
        return self::SUPPORTED_LANGUAGES;
    }

    /**
     * Look up metadata for a specific language code.
     *
     * @return array{code: string, name: string, dialect: string, voice: string, package: string, say_package: string, say_module: string, sound_path: string, tarball_prefix: string}|null
     */
    public function getLanguage(string $code): ?array
    {
        return self::SUPPORTED_LANGUAGES[strtolower(trim($code))] ?? null;
    }

    /**
     * Resolve the active FreeSWITCH sounds root directory.
     */
    public function getSoundsDirectory(): string
    {
        if ($this->soundsDirectory !== null) {
            return $this->soundsDirectory;
        }

        $candidates = [
            '/usr/share/freeswitch/sounds',
            '/usr/local/freeswitch/sounds',
            '/var/lib/freeswitch/sounds',
        ];

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return '/usr/share/freeswitch/sounds';
    }

    /**
     * Resolve the active FreeSWITCH configuration directory.
     */
    public function getConfDirectory(): string
    {
        if ($this->confDirectory !== null) {
            return $this->confDirectory;
        }

        $candidates = [
            '/etc/freeswitch',
            '/usr/local/freeswitch/conf',
            '/etc/freeswitch.stock',
        ];

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return '/etc/freeswitch';
    }

    /**
     * Resolve the path to vars.xml.
     */
    public function getVarsXmlPath(): string
    {
        return $this->getConfDirectory().'/vars.xml';
    }

    /**
     * Resolve the path to modules.conf.xml.
     */
    public function getModulesConfPath(): string
    {
        return $this->getConfDirectory().'/autoload_configs/modules.conf.xml';
    }

    /**
     * Check if a given language's sound files are installed on the local filesystem.
     */
    public function isInstalled(string $code): bool
    {
        $lang = $this->getLanguage($code);
        if ($lang === null) {
            return false;
        }

        $dir = $this->getSoundsDirectory().'/'.$lang['sound_path'];

        return is_dir($dir) && count((array) scandir($dir)) > 2;
    }

    /**
     * Read the current system default sound prompt language from vars.xml.
     *
     * @return array{code: string, dialect: string, voice: string, sound_prefix: string}
     */
    public function getCurrentDefault(): array
    {
        $varsPath = $this->getVarsXmlPath();

        $default = [
            'code' => 'en',
            'dialect' => 'us',
            'voice' => 'callie',
            'sound_prefix' => '$${sounds_dir}/en/us/callie',
        ];

        if (! file_exists($varsPath)) {
            return $default;
        }

        $content = (string) file_get_contents($varsPath);

        if (preg_match('/<X-PRE-PROCESS\s+cmd="set"\s+data="default_language=([^"]+)"\s*\/>/i', $content, $m)) {
            $default['code'] = trim($m[1]);
        }
        if (preg_match('/<X-PRE-PROCESS\s+cmd="set"\s+data="default_dialect=([^"]+)"\s*\/>/i', $content, $m)) {
            $default['dialect'] = trim($m[1]);
        }
        if (preg_match('/<X-PRE-PROCESS\s+cmd="set"\s+data="default_voice=([^"]+)"\s*\/>/i', $content, $m)) {
            $default['voice'] = trim($m[1]);
        }
        if (preg_match('/<X-PRE-PROCESS\s+cmd="set"\s+data="sound_prefix=([^"]+)"\s*\/>/i', $content, $m)) {
            $default['sound_prefix'] = trim($m[1]);
        }

        return $default;
    }

    /**
     * List all supported languages along with installation and default status.
     *
     * @return array<int, array{code: string, name: string, dialect: string, voice: string, installed: bool, is_default: bool, path: string}>
     */
    public function listLanguages(): array
    {
        $currentDefault = $this->getCurrentDefault();
        $list = [];

        foreach (self::SUPPORTED_LANGUAGES as $code => $meta) {
            $isInstalled = $this->isInstalled($code);
            $isDefault = strtolower($currentDefault['code']) === $code;

            $list[] = [
                'code' => $code,
                'name' => $meta['name'],
                'dialect' => $meta['dialect'],
                'voice' => $meta['voice'],
                'installed' => $isInstalled,
                'is_default' => $isDefault,
                'path' => $this->getSoundsDirectory().'/'.$meta['sound_path'],
            ];
        }

        return $list;
    }

    /**
     * Update vars.xml to set the system default sound prompt language.
     */
    public function updateVarsXml(string $code): bool
    {
        $lang = $this->getLanguage($code);
        if ($lang === null) {
            return false;
        }

        $varsPath = $this->getVarsXmlPath();
        if (! file_exists($varsPath)) {
            // Create a minimal vars.xml if none exists
            $initial = "<include>\n</include>\n";
            file_put_contents($varsPath, $initial);
        }

        $content = (string) file_get_contents($varsPath);

        $params = [
            'default_language' => $lang['code'],
            'default_dialect' => $lang['dialect'],
            'default_voice' => $lang['voice'],
            'sound_prefix' => '$${sounds_dir}/'.$lang['sound_path'],
        ];

        foreach ($params as $key => $val) {
            $pattern = '/<X-PRE-PROCESS\s+cmd="set"\s+data="'.$key.'=[^"]*"\s*\/>/i';
            $replacement = '<X-PRE-PROCESS cmd="set" data="'.$key.'='.$val.'"/>';

            if (preg_match($pattern, $content)) {
                $content = (string) preg_replace($pattern, $replacement, $content);
            } else {
                // Insert near the top inside <include>
                if (str_contains($content, '<include>')) {
                    $content = (string) preg_replace('/<include>/', "<include>\n  ".$replacement, $content, 1);
                } else {
                    $content .= "\n".$replacement."\n";
                }
            }
        }

        return file_put_contents($varsPath, $content) !== false;
    }

    /**
     * Ensure the specified grammar say module is loaded in modules.conf.xml.
     */
    public function updateModulesConf(string $sayModule): bool
    {
        $modulesPath = $this->getModulesConfPath();
        if (! file_exists($modulesPath)) {
            return false;
        }

        $content = (string) file_get_contents($modulesPath);

        // Check if already active
        if (preg_match('/<load\s+module="'.preg_quote($sayModule, '/').'"\s*\/>/', $content)) {
            return true;
        }

        // Check if commented out
        $commentPattern = '/<!--\s*<load\s+module="'.preg_quote($sayModule, '/').'"\s*\/>\s*-->/';
        if (preg_match($commentPattern, $content)) {
            $content = (string) preg_replace($commentPattern, '<load module="'.$sayModule.'"/>', $content);

            return file_put_contents($modulesPath, $content) !== false;
        }

        // Add before closing </modules>
        if (str_contains($content, '</modules>')) {
            $content = (string) preg_replace('/<\/modules>/', '    <load module="'.$sayModule."\"/>\n  </modules>", $content, 1);

            return file_put_contents($modulesPath, $content) !== false;
        }

        return false;
    }

    /**
     * Set the system default sound prompt language and apply it to FreeSWITCH.
     */
    public function setDefaultLanguage(string $code): bool
    {
        $lang = $this->getLanguage($code);
        if ($lang === null) {
            return false;
        }

        $success = $this->updateVarsXml($code);
        $this->updateModulesConf($lang['say_module']);
        $this->reloadFreeSwitch($lang['say_module']);

        return $success;
    }

    /**
     * Trigger FreeSWITCH to reload its XML configuration and load the say module.
     */
    public function reloadFreeSwitch(?string $sayModule = null): bool
    {
        if ($sayModule !== null) {
            $this->loadModule($sayModule);
        }

        if ($this->freeSwitch !== null) {
            try {
                $this->freeSwitch->api('reloadxml');

                return true;
            } catch (Throwable) {
                // Fall back to CLI
            }
        }

        // Try fs_cli fallback
        $process = new Process(['fs_cli', '-x', 'reloadxml']);
        try {
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Send load module command to FreeSWITCH.
     */
    public function loadModule(string $module): bool
    {
        if ($this->freeSwitch !== null) {
            try {
                $this->freeSwitch->api('load '.$module);

                return true;
            } catch (Throwable) {
                // Fall back to CLI
            }
        }

        $process = new Process(['fs_cli', '-x', 'load '.$module]);
        try {
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Install sound files and grammar modules for the given language.
     *
     * Detects package manager vs source build and executes the appropriate installation.
     *
     * @return array{success: bool, message: string}
     */
    public function install(string $code, bool $makeDefault = false): array
    {
        $lang = $this->getLanguage($code);
        if ($lang === null) {
            return ['success' => false, 'message' => "Unsupported language code: {$code}"];
        }

        // Check if apt package installation is appropriate
        $isPackageHost = file_exists('/usr/bin/apt-get') && (
            file_exists('/etc/apt/sources.list.d/freeswitch.list') ||
            $this->isPackageInstalled('freeswitch')
        );

        if ($isPackageHost) {
            $result = $this->installViaApt($lang);
        } else {
            $result = $this->installViaTarball($lang);
        }

        if (! $result['success']) {
            return $result;
        }

        // Ensure module is registered in modules.conf.xml and loaded
        $this->updateModulesConf($lang['say_module']);
        $this->loadModule($lang['say_module']);

        if ($makeDefault) {
            $this->setDefaultLanguage($code);
        } else {
            $this->reloadFreeSwitch();
        }

        return ['success' => true, 'message' => "Successfully installed {$lang['name']} sound prompts."];
    }

    /**
     * Check if a deb package is installed.
     */
    protected function isPackageInstalled(string $package): bool
    {
        $process = new Process(['dpkg', '-s', $package]);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Install sound and say packages via apt-get.
     *
     * @param  array{code: string, name: string, package: string, say_package: string}  $lang
     * @return array{success: bool, message: string}
     */
    protected function installViaApt(array $lang): array
    {
        $process = new Process([
            'apt-get', 'install', '-y',
            $lang['package'],
            $lang['say_package'],
        ]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            return [
                'success' => false,
                'message' => 'Failed to install packages via apt: '.$process->getErrorOutput(),
            ];
        }

        return ['success' => true, 'message' => 'Packages installed successfully'];
    }

    /**
     * Download and extract official sound tarballs for source installations.
     *
     * @param  array{sound_path: string, tarball_prefix: string}  $lang
     * @return array{success: bool, message: string}
     */
    protected function installViaTarball(array $lang): array
    {
        $targetDir = $this->getSoundsDirectory().'/'.$lang['sound_path'];
        File::ensureDirectoryExists($targetDir);

        $rates = ['8000', '16000'];
        $version = '1.0.51';

        foreach ($rates as $rate) {
            $url = "https://files.freeswitch.org/releases/sounds/{$lang['tarball_prefix']}-{$rate}-{$version}.tar.gz";
            $tmpFile = tempnam(sys_get_temp_dir(), 'fs_sound_');

            $curlProcess = new Process(['curl', '-fsSL', '-o', $tmpFile, $url]);
            $curlProcess->setTimeout(300);
            $curlProcess->run();

            if (! $curlProcess->isSuccessful() || ! file_exists($tmpFile) || filesize($tmpFile) < 1000) {
                @unlink($tmpFile);

                return [
                    'success' => false,
                    'message' => "Failed to download sound archive from {$url}",
                ];
            }

            $tarProcess = new Process(['tar', '-xzf', $tmpFile, '-C', $targetDir]);
            $tarProcess->run();
            @unlink($tmpFile);

            if (! $tarProcess->isSuccessful()) {
                return [
                    'success' => false,
                    'message' => 'Failed to extract sound archive: '.$tarProcess->getErrorOutput(),
                ];
            }
        }

        // Set ownership to freeswitch
        $chownProcess = new Process(['chown', '-R', 'freeswitch:freeswitch', $targetDir]);
        $chownProcess->run();

        return ['success' => true, 'message' => 'Tarball sounds installed successfully'];
    }
}
