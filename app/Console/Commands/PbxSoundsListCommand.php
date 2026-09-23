<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FreeSwitchSoundManager;
use Illuminate\Console\Command;

/**
 * List available and installed FreeSWITCH sound prompt languages.
 */
class PbxSoundsListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pbx:sounds:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List available and installed FreeSWITCH sound prompt languages';

    /**
     * The console command aliases.
     *
     * @var array<int, string>
     */
    protected $aliases = ['pbx:sounds'];

    /**
     * Execute the console command.
     */
    public function handle(FreeSwitchSoundManager $soundManager): int
    {
        $languages = $soundManager->listLanguages();

        $rows = array_map(function (array $lang): array {
            return [
                $lang['code'],
                $lang['name'],
                $lang['dialect'].'/'.$lang['voice'],
                $lang['installed'] ? 'installed' : 'available',
                $lang['is_default'] ? 'yes' : 'no',
                $lang['path'],
            ];
        }, $languages);

        $this->components->info('FreeSWITCH Sound Prompt Languages');

        $this->table(
            ['Code', 'Language', 'Dialect / Voice', 'Status', 'Default', 'Sound Path'],
            $rows,
        );

        return self::SUCCESS;
    }
}
