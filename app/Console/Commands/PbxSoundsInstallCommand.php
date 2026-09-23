<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FreeSwitchSoundManager;
use Illuminate\Console\Command;

/**
 * Install FreeSWITCH sound prompt packages and grammar modules for a language.
 */
class PbxSoundsInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pbx:sounds:install
                            {language : Language code to install (e.g., es, fr, en)}
                            {--default : Set as system default prompt language after installation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install FreeSWITCH sound prompt packages and grammar modules for an alternate language';

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

        $makeDefault = (bool) $this->option('default');

        $this->components->info("Installing FreeSWITCH sound prompts for {$lang['name']} ({$lang['dialect']}/{$lang['voice']})...");

        $result = $soundManager->install($code, $makeDefault);

        if (! $result['success']) {
            $this->components->error($result['message']);

            return self::FAILURE;
        }

        $this->components->info($result['message']);

        if ($makeDefault) {
            $this->components->info("Set {$lang['name']} ({$code}) as the default FreeSWITCH sound prompt language.");
        }

        return self::SUCCESS;
    }
}
