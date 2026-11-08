<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\FreeSwitchXmlCurlConfig;
use Illuminate\Console\Command;

/**
 * Checks that mod_xml_curl's configuration cannot crash FreeSWITCH.
 *
 * The installer and the upgrade path call this before the switch restarts, so
 * a binding without a gateway-url is refused at deploy time rather than taking
 * the phone system down at the next directory lookup.
 */
class ValidateFreeSwitchXmlCurlCommand extends Command
{
    /**
     * Console signature, including the optional path override.
     *
     * @var string
     */
    protected $signature = 'freeswitch:validate-xml-curl
        {--path= : Absolute path to xml_curl.conf.xml (defaults to the appliance location)}';

    /**
     * One-line description used by the console command list.
     *
     * @var string
     */
    protected $description = 'Refuse an xml_curl configuration that would crash the switch';

    /**
     * Validate the configuration and report every problem it contains.
     *
     * @return int SUCCESS when the configuration is safe, FAILURE otherwise
     */
    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: FreeSwitchXmlCurlConfig::DEFAULT_PATH);

        // A missing file means mod_xml_curl has nothing to load, which is not
        // a crash risk; report it so an operator knows the appliance is not
        // serving XML through the module at all.
        if (! is_file($path)) {
            $this->warn("xml_curl.conf.xml not found at {$path}");

            return self::SUCCESS;
        }

        $errors = FreeSwitchXmlCurlConfig::errors($path);

        if ($errors === []) {
            $this->info("xml_curl.conf.xml is valid: {$path}");

            return self::SUCCESS;
        }

        foreach ($errors as $error) {
            $this->error($error);
        }

        $this->error('Refusing this configuration: FreeSWITCH crashes on the first lookup that reaches such a binding.');

        return self::FAILURE;
    }
}
