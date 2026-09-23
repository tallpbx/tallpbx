<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FreeSwitchSoundManager;
use Illuminate\Console\Command;

/**
 * Set the default FreeSWITCH sound prompt language.
 */
class PbxSoundsDefaultCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pbx:sounds:default
                            {language : Language code to set as default (e.g., es, fr, en)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set the system default FreeSWITCH sound prompt language in vars.xml';

    /**
     * Execute the console command.
     */
    public function handle(FreeSwitchSoundManager $soundManager): int
    {
        $code = strtolower(trim((string) $this->argument('language')));
        $lang = $soundManager->getLanguage($code);

        if ($lang === null) {
            $supported = implode(', ', array_keys($soundManager->getSupportedLanguages()));
            $this->components->error("Unsupported language code '{$code}'. Supported languages: {$supported}");

            return self::FAILURE;
        }

        if (! $soundManager->isInstalled($code)) {
            $this->components->warn("Sound files for {$lang['name']} ({$code}) are not currently installed.");
            if ($this->confirm("Do you want to install {$lang['name']} sound prompts now?", true)) {
                $result = $soundManager->install($code, makeDefault: true);
                if (! $result['success']) {
                    $this->components->error($result['message']);

                    return self::FAILURE;
                }
                $this->components->info($result['message']);
                $this->components->info("Set {$lang['name']} ({$code}) as the default FreeSWITCH sound prompt language.");

                return self::SUCCESS;
            }

            return self::FAILURE;
        }

        $success = $soundManager->setDefaultLanguage($code);

        if (! $success) {
            $this->components->error("Failed to update vars.xml for language '{$code}'.");

            return self::FAILURE;
        }

        $this->components->info("FreeSWITCH default sound prompt language set to {$lang['name']} ({$code}: {$lang['dialect']}/{$lang['voice']}).");

        return self::SUCCESS;
    }
}
