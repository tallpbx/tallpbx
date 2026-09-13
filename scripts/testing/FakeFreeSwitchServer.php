<?php

declare(strict_types=1);

/**
 * Fake FreeSWITCH ESL server for integration testing.
 *
 * Runs as a standalone CLI subprocess, listens on a TCP socket,
 * and simulates the FreeSWITCH Event Socket Layer protocol:
 *
 *   1. Sends "Content-Type: auth/request" on connect
 *   2. Validates "auth <password>" and replies "+OK accepted"
 *   3. Handles "api <command>" with Content-Length-delimited responses
 *   4. Supports event subscription and fake event delivery
 *   5. Accepts "exit" to shut down gracefully
 *
 * Usage from tests:
 *   $port = findAvailablePort();
 *   $proc = proc_open("php tests/Support/FakeFreeSwitchServer.php {$port} 'ClueCon'", ...);
 *   // Wait for port to be open, then connect FreeSwitchService
 *   // When done: proc_terminate($proc)
 *
 * Run directly:
 *   php tests/Support/FakeFreeSwitchServer.php <port> [password] [scenario]
 *
 * Scenarios:
 *   - default: Normal FreeSWITCH behavior (auth + api + events)
 *   - reply_text_auth: Auth success is returned in FreeSWITCH's Reply-Text header
 *   - wrong_password: Sends auth/request but rejects all passwords
 *   - slow_auth: Delays the auth response by 3 seconds (timeout test)
 *   - disconnect_after_auth: Closes connection after successful auth
 */
class FakeFreeSwitchServer
{
    /** @var resource|null */
    private mixed $serverSocket = null;

    /** @var resource|null */
    private mixed $clientSocket = null;

    /** @var array<int, string> */
    private array $subscribedEvents = [];

    /**
     * Create the fake server on the given port.
     */
    public function __construct(
        private readonly int $port,
        private readonly string $password,
        private readonly string $scenario = 'default',
    ) {}

    /**
     * Start listening and handle one client connection, then exit.
     */
    public function run(): void
    {
        $this->serverSocket = @stream_socket_server("tcp://127.0.0.1:{$this->port}", $errno, $errstr);

        if (! $this->serverSocket) {
            fwrite(STDERR, "FakeFreeSwitchServer: cannot bind port {$this->port}: [{$errno}] {$errstr}\n");

            exit(1);
        }

        // Signal to the parent that we're ready — the port is bound
        fwrite(STDOUT, "READY:{$this->port}\n");
        fflush(STDOUT);

        // Accept one client (blocking)
        $this->clientSocket = @stream_socket_accept($this->serverSocket, 30);

        if (! $this->clientSocket) {
            exit(0);
        }

        stream_set_blocking($this->clientSocket, true);

        try {
            $this->handleClient();
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Handle the complete client lifecycle.
     */
    private function handleClient(): void
    {
        match ($this->scenario) {
            'reply_text_auth' => $this->scenarioNormal(replyTextAuth: true),
            'wrong_password' => $this->scenarioWrongPassword(),
            'slow_auth' => $this->scenarioSlowAuth(),
            'disconnect_after_auth' => $this->scenarioDisconnectAfterAuth(),
            default => $this->scenarioNormal(),
        };
    }

    // ─── Scenarios ──────────────────────────────────────────────────

    /**
     * Normal FreeSWITCH behavior: auth → api commands → events.
     */
    private function scenarioNormal(bool $replyTextAuth = false): void
    {
        if (! $this->doAuthHandshake(replyText: $replyTextAuth)) {
            return;
        }

        // Process commands in a loop until client disconnects or sends "exit"
        while ($this->clientSocket !== null) {
            $line = $this->readLine();

            if ($line === null) {
                break; // Client disconnected
            }

            if ($line === 'exit') {
                break;
            }

            if (str_starts_with($line, 'api ')) {
                $command = substr($line, 4);
                $this->handleApiCommand($command);

                continue;
            }

            if (str_starts_with($line, 'bgapi ')) {
                $command = substr($line, 6);
                $this->handleBgapiCommand($command);

                continue;
            }

            if (str_starts_with($line, 'event plain ')) {
                $events = substr($line, 12);
                $this->subscribedEvents = array_filter(explode(' ', $events));
                $this->writeLine('Content-Type: command/reply');
                $this->writeLine('');
                $this->writeLine('+OK event listener enabled plain');

                continue;
            }

            if ($line === 'noevents') {
                $this->subscribedEvents = [];
                $this->writeLine('Content-Type: command/reply');
                $this->writeLine('');
                $this->writeLine('+OK no events');

                continue;
            }

            if (str_starts_with($line, 'sendevent ')) {
                // Consume sendevent — acknowledge silently
                $this->readUntilBlankLine();

                continue;
            }
        }
    }

    /**
     * Reply with auth/request but reject any password.
     */
    private function scenarioWrongPassword(): void
    {
        $this->sendAuthRequest();

        // Read auth attempt
        $line = $this->readLine();

        // Reply with error
        $this->writeLine('Content-Type: command/reply');
        $this->writeLine('');
        $this->writeLine('-ERR invalid password');
    }

    /**
     * Delay auth response to test timeout behavior.
     */
    private function scenarioSlowAuth(): void
    {
        $this->sendAuthRequest();

        // Read auth attempt but don't reply yet
        $this->readLine();

        // Simulate a slow FreeSWITCH
        sleep(3);

        $this->writeLine('Content-Type: command/reply');
        $this->writeLine('');
        $this->writeLine('+OK accepted');
    }

    /**
     * Auth succeeds, then immediately close the connection.
     * Tests reconnect/resilience behavior.
     */
    private function scenarioDisconnectAfterAuth(): void
    {
        if (! $this->doAuthHandshake()) {
            return;
        }

        // Close the connection unexpectedly after successful auth
        $this->cleanup();
    }

    // ─── ESL Protocol Helpers ───────────────────────────────────────

    /**
     * Perform the standard auth/request → auth <pass> → +OK handshake.
     *
     * @return bool True if auth succeeded
     */
    private function doAuthHandshake(bool $replyText = false): bool
    {
        $this->sendAuthRequest();

        $authLine = $this->readLine();

        if ($authLine === null) {
            return false;
        }

        // Parse "auth <password>"
        if (! str_starts_with($authLine, 'auth ')) {
            $this->writeLine('Content-Type: command/reply');
            $this->writeLine('');
            $this->writeLine('-ERR bad command');

            return false;
        }

        $providedPassword = substr($authLine, 5);

        if ($providedPassword !== $this->password) {
            $this->writeLine('Content-Type: command/reply');
            $this->writeLine('');
            $this->writeLine('-ERR invalid password');

            return false;
        }

        if ($replyText) {
            $this->writeLine('Content-Type: command/reply');
            $this->writeLine('Reply-Text: +OK accepted');
            $this->writeLine('');
        } else {
            $this->writeLine('Content-Type: command/reply');
            $this->writeLine('');
            $this->writeLine('+OK accepted');
        }

        return true;
    }

    /**
     * Send the FreeSWITCH auth/request greeting.
     */
    private function sendAuthRequest(): void
    {
        $this->writeLine('Content-Type: auth/request');
        $this->writeLine('');
    }

    /**
     * Handle an "api <command>" request with a realistic response.
     */
    private function handleApiCommand(string $command): void
    {
        $response = $this->generateApiResponse($command);

        $body = $response['body'];
        $contentLength = strlen($body);

        $this->writeLine('Content-Type: api/response');
        $this->writeLine("Content-Length: {$contentLength}");
        $this->writeLine('');

        if ($contentLength > 0) {
            $this->writeRaw($body);
        }
    }

    /**
     * Handle a "bgapi <command>" with a job UUID response.
     */
    private function handleBgapiCommand(string $command): void
    {
        $jobUuid = 'fake-job-'.bin2hex(random_bytes(4));

        $body = "+OK Job-UUID: {$jobUuid}";

        $this->writeLine('Content-Type: command/reply');
        $this->writeLine('Content-Length: '.strlen($body));
        $this->writeLine('');
        $this->writeRaw($body);
    }

    /**
     * Generate a realistic API response for common FreeSWITCH commands.
     *
     * @return array{body: string}
     */
    private function generateApiResponse(string $command): array
    {
        // Strip arguments for matching
        $cmdName = explode(' ', $command)[0];

        return match ($cmdName) {
            'sofia' => $this->sofiaStatusResponse($command),
            'show' => $this->showResponse($command),
            'status' => ['body' => "UP 0 years, 0 days, 1 hour, 23 minutes, 45 seconds\n0 session(s) since startup\n0 session(s) - 0 out of max 1000 sessions\n"],
            'version' => ['body' => "FreeSWITCH Version 1.10.12-release~64bit\n"],
            'uptime' => ['body' => "1 hour 23 minutes 45 seconds\n"],
            'reloadxml' => ['body' => "+OK [Success]\n"],
            'hupall' => ['body' => "+OK\n"],
            'fsctl' => ['body' => "+OK\n"],
            default => ['body' => "+OK {$command}\n"],
        };
    }

    /**
     * Build a realistic sofia status response.
     */
    private function sofiaStatusResponse(string $command): array
    {
        $parts = explode(' ', $command);

        if (in_array('status', $parts, true)) {
            return ['body' => <<<'EOF'
Name                    Type                                       Data      State
=================================================================================================
internal                profile                                    sip:mod_sofia@127.0.0.1:5060 RUNNING (0)
external                profile                                    sip:mod_sofia@127.0.0.1:5080 RUNNING (0)
internal-ipv6           profile                                    sip:mod_sofia@[::1]:5060     RUNNING (0)
=================================================================================================
3 profiles 0 aliases
EOF];
        }

        if (in_array('xmlstatus', $parts, true) && in_array('profile', $parts, true)) {
            return ['body' => <<<'EOF'
<profile name="internal">
  <aliases/>
  <gateways/>
  <domains>
    <domain name="example.com" alias="false">
      <users>
        <user id="1001">
          <params>
            <param name="username" value="1001"/>
          </params>
        </user>
      </users>
    </domain>
  </domains>
</profile>
EOF];
        }

        return ['body' => "+OK sofia command processed\n"];
    }

    /**
     * Build a realistic "show" command response.
     */
    private function showResponse(string $command): array
    {
        return match ($command) {
            'show channels' => ['body' => "0 total.\n"],
            'show calls' => ['body' => "0 total.\n"],
            'show registrations' => ['body' => "0 total.\n"],
            default => ['body' => "+OK\n"],
        };
    }

    // ─── Socket I/O ─────────────────────────────────────────────────

    /**
     * Read a single line from the client (strips trailing \r\n).
     */
    private function readLine(): ?string
    {
        if ($this->clientSocket === null) {
            return null;
        }

        $line = fgets($this->clientSocket, 8192);

        if ($line === false || $line === '') {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    /**
     * Read lines from the client until a blank line is encountered.
     */
    private function readUntilBlankLine(): string
    {
        $lines = [];

        while (true) {
            $line = $this->readLine();

            if ($line === null || $line === '') {
                break;
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Write a line to the client (appends \n).
     */
    private function writeLine(string $line): void
    {
        $this->writeRaw($line."\n");
    }

    /**
     * Write raw bytes to the client socket.
     */
    private function writeRaw(string $data): void
    {
        if ($this->clientSocket !== null) {
            fwrite($this->clientSocket, $data);
        }
    }

    /**
     * Close sockets and clean up.
     */
    private function cleanup(): void
    {
        if ($this->clientSocket !== null && is_resource($this->clientSocket)) {
            fclose($this->clientSocket);
            $this->clientSocket = null;
        }

        if ($this->serverSocket !== null && is_resource($this->serverSocket)) {
            fclose($this->serverSocket);
            $this->serverSocket = null;
        }
    }
}

// ─── CLI entry point ────────────────────────────────────────────────

if (PHP_SAPI !== 'cli' || ! isset($argv)) {
    return;
}

// Usage: php FakeFreeSwitchServer.php <port> [password] [scenario]
$port = isset($argv[1]) ? (int) $argv[1] : 18021;
$password = $argv[2] ?? 'ClueCon';
$scenario = $argv[3] ?? 'default';

$server = new FakeFreeSwitchServer($port, $password, $scenario);
$server->run();
