<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Contracts\ProcessRunner;
use App\Support\ProcessResult;
use RuntimeException;

/**
 * A fake process runner scripted per command substring for testing the update pipeline.
 */
class FakeProcessRunner implements ProcessRunner
{
    /** @var array<string, int> */
    public array $exitCodes = [];

    /** @var array<string, string> */
    public array $outputs = [];

    /** @var array<int, string> */
    public array $commands = [];

    /** @var array<int, array<int, string>> */
    public array $argvCommands = [];

    /** @var array<string, true> Commands that should throw. */
    public array $throwOn = [];

    /**
     * Run a command and return the configured scripted result.
     *
     * @param  array<int, string>  $argv
     * @param  array<string, string>  $env
     */
    public function run(
        array $argv,
        ?string $cwd = null,
        ?int $timeoutSeconds = null,
        array $env = [],
    ): ProcessResult {
        $joined = implode(' ', $argv);
        $this->commands[] = $joined;
        $this->argvCommands[] = $argv;

        foreach ($this->throwOn as $needle => $_) {
            if (str_contains($joined, $needle)) {
                throw new RuntimeException('Simulated process failure.');
            }
        }

        $exitCode = 0;
        $output = 'output of: '.$joined;

        foreach ($this->exitCodes as $needle => $code) {
            if (str_contains($joined, $needle)) {
                $exitCode = $code;

                break;
            }
        }

        foreach ($this->outputs as $needle => $text) {
            if (str_contains($joined, $needle)) {
                $output = $text;

                break;
            }
        }

        return new ProcessResult($exitCode, $output);
    }
}
