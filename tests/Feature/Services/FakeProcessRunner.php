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

    /** @var array<string, true> Commands that should throw. */
    public array $throwOn = [];

    /**
     * Run a command and return the configured scripted result.
     */
    public function run(string $command): ProcessResult
    {
        $this->commands[] = $command;

        foreach ($this->throwOn as $needle => $_) {
            if (str_contains($command, $needle)) {
                throw new RuntimeException('Simulated process failure.');
            }
        }

        $exitCode = 0;
        $output = 'output of: '.$command;

        foreach ($this->exitCodes as $needle => $code) {
            if (str_contains($command, $needle)) {
                $exitCode = $code;

                break;
            }
        }

        foreach ($this->outputs as $needle => $text) {
            if (str_contains($command, $needle)) {
                $output = $text;

                break;
            }
        }

        return new ProcessResult($exitCode, $output);
    }
}
