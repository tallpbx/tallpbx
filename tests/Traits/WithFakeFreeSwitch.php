<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Services\FreeSwitchService;

/**
 * Trait for tests that need a running fake FreeSWITCH ESL server.
 *
 * Starts a FakeFreeSwitchServer subprocess on a random available port,
 * waits for it to be ready, and provides a pre-configured FreeSwitchService
 * connected to it. The server process is terminated when the test completes.
 *
 * Usage:
 *   uses(WithFakeFreeSwitch::class);
 *
 *   it('executes an API command', function () {
 *       $fs = $this->newFreeSwitchService();
 *       $fs->connect();
 *       $response = $fs->api('version');
 *       expect($response)->toContain('FreeSWITCH Version');
 *   });
 */
trait WithFakeFreeSwitch
{
    /** @var resource|null The subprocess resource */
    private mixed $fakeServerProc = null;

    /** @var int The port the fake server is listening on */
    private int $fakeServerPort = 0;

    /** @var string The password the fake server expects */
    private string $fakeServerPassword = 'ClueCon';

    /**
     * Start a fake FreeSWITCH server on a random port.
     *
     * @param  string  $scenario  The scenario for the fake server (default, wrong_password, slow_auth, disconnect_after_auth)
     * @param  string|null  $password  Password override (defaults to 'ClueCon')
     * @return int The port number the server is listening on
     */
    protected function startFakeFreeSwitch(string $scenario = 'default', ?string $password = null): int
    {
        $this->fakeServerPassword = $password ?? 'ClueCon';

        // Find an available port by binding to port 0, then extract the assigned port
        $sock = @stream_socket_server('tcp://127.0.0.1:0');

        if (! $sock) {
            throw new \RuntimeException('Cannot find an available port.');
        }

        $socketName = stream_socket_get_name($sock, false);
        fclose($sock);

        // Parse port from "127.0.0.1:XXXXX"
        $parts = explode(':', $socketName);
        $this->fakeServerPort = (int) end($parts);

        // The fake server is a standalone CLI script, not a test class,
        // so it lives outside the autoloaded tests/ directory.
        $serverScript = __DIR__.'/../../scripts/testing/FakeFreeSwitchServer.php';
        $escapedPassword = escapeshellarg($this->fakeServerPassword);
        $escapedScenario = escapeshellarg($scenario);

        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout (READY signal)
            2 => ['pipe', 'w'],  // stderr
        ];

        $cmd = PHP_BINARY." {$serverScript} {$this->fakeServerPort} {$escapedPassword} {$escapedScenario}";

        $this->fakeServerProc = proc_open($cmd, $descriptorSpec, $pipes);

        if (! $this->fakeServerProc) {
            throw new \RuntimeException("Cannot start fake FreeSWITCH server: {$cmd}");
        }

        // Wait for the READY signal from the server
        $readyLine = fgets($pipes[1], 64);

        if ($readyLine === false || ! str_starts_with($readyLine, 'READY:')) {
            // Read stderr for error details
            $stderr = stream_get_contents($pipes[2]);
            throw new \RuntimeException("Fake FreeSWITCH server failed to start. stderr: {$stderr}");
        }

        // Close unused pipe ends — keep proc open, pipes will be closed on cleanup
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return $this->fakeServerPort;
    }

    /**
     * Stop the fake FreeSWITCH server subprocess.
     */
    protected function stopFakeFreeSwitch(): void
    {
        if ($this->fakeServerProc !== null) {
            // Graceful termination
            proc_terminate($this->fakeServerProc, SIGTERM);
            proc_close($this->fakeServerProc);
            $this->fakeServerProc = null;
        }

        $this->fakeServerPort = 0;
    }

    /**
     * Create a FreeSwitchService connected to the fake server.
     *
     * The service is NOT auto-connected — call $fs->connect() explicitly
     * in your test so you can control the timing and test connection failures.
     *
     * @param  int  $timeout  Connection timeout in seconds
     */
    protected function newFreeSwitchService(int $timeout = 2): FreeSwitchService
    {
        if ($this->fakeServerPort === 0) {
            throw new \RuntimeException('Call startFakeFreeSwitch() before newFreeSwitchService().');
        }

        return new FreeSwitchService(
            host: '127.0.0.1',
            port: $this->fakeServerPort,
            password: $this->fakeServerPassword,
            timeout: $timeout,
        );
    }

    /**
     * Automatically stop the fake server after each test.
     *
     * @after
     */
    protected function tearDownFakeFreeSwitch(): void
    {
        $this->stopFakeFreeSwitch();
    }
}
