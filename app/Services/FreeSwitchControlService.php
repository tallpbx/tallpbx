<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Executes FreeSWITCH control actions from the operational modules.
 *
 * Every command is built here so input sanitization lives in one place:
 * UUIDs are stripped to hex/dash (the FusionPBX discipline) and verified
 * against the caller-provided current list before use; destinations use a
 * strict extension grammar. Offline, invalid input, and -ERR/empty
 * responses return a typed failure — never an exception.
 */
class FreeSwitchControlService
{
    public function __construct(private readonly FreeSwitchServiceInterface $fs) {}

    /**
     * Hang up a channel.
     *
     * @param  array<int, string>  $currentUuids  The channels currently listed
     * @return array{success: bool, message: string}
     */
    public function hangup(string $uuid, array $currentUuids): array
    {
        if (! $this->fs->isConnected()) {
            return ['success' => false, 'message' => 'FreeSWITCH is not connected.'];
        }

        $cleanUuid = $this->validateUuid($uuid, $currentUuids);

        if ($cleanUuid === null) {
            return ['success' => false, 'message' => 'The channel is no longer active.'];
        }

        return $this->run("uuid_kill {$cleanUuid}");
    }

    /**
     * Transfer a channel to a destination in its current context.
     *
     * @param  array<int, string>  $currentUuids
     * @return array{success: bool, message: string}
     */
    public function transfer(string $uuid, string $destination, array $currentUuids): array
    {
        if (! $this->fs->isConnected()) {
            return ['success' => false, 'message' => 'FreeSWITCH is not connected.'];
        }

        $cleanUuid = $this->validateUuid($uuid, $currentUuids);

        if ($cleanUuid === null) {
            return ['success' => false, 'message' => 'The channel is no longer active.'];
        }

        if (! preg_match('/^[0-9*#+A-Za-z]+$/', $destination)) {
            return ['success' => false, 'message' => 'The transfer destination is not a valid number or extension.'];
        }

        return $this->run("uuid_transfer {$cleanUuid} {$destination}");
    }

    /**
     * Mute or unmute a conference member.
     *
     * @return array{success: bool, message: string}
     */
    public function conferenceMute(string $conference, string $memberId, bool $muted): array
    {
        if (! $this->fs->isConnected()) {
            return ['success' => false, 'message' => 'FreeSWITCH is not connected.'];
        }

        $conference = $this->validateToken($conference);
        $memberId = $this->validateToken($memberId);

        if ($conference === null || $memberId === null) {
            return ['success' => false, 'message' => 'The conference or member is not valid.'];
        }

        return $this->run("conference {$conference} mute {$memberId} ".($muted ? 'on' : 'off'));
    }

    /**
     * Kick a member from a conference.
     *
     * @return array{success: bool, message: string}
     */
    public function conferenceKick(string $conference, string $memberId): array
    {
        if (! $this->fs->isConnected()) {
            return ['success' => false, 'message' => 'FreeSWITCH is not connected.'];
        }

        $conference = $this->validateToken($conference);
        $memberId = $this->validateToken($memberId);

        if ($conference === null || $memberId === null) {
            return ['success' => false, 'message' => 'The conference or member is not valid.'];
        }

        return $this->run("conference {$conference} kick {$memberId}");
    }

    /**
     * Pause a call center agent.
     *
     * @return array{success: bool, message: string}
     */
    public function agentPause(string $agent): array
    {
        if (! $this->fs->isConnected()) {
            return ['success' => false, 'message' => 'FreeSWITCH is not connected.'];
        }

        $agent = $this->validateToken($agent);

        if ($agent === null) {
            return ['success' => false, 'message' => 'The agent id is not valid.'];
        }

        return $this->run("callcenter_config agent pause {$agent}");
    }

    /**
     * Unpause a call center agent.
     *
     * @return array{success: bool, message: string}
     */
    public function agentUnpause(string $agent): array
    {
        if (! $this->fs->isConnected()) {
            return ['success' => false, 'message' => 'FreeSWITCH is not connected.'];
        }

        $agent = $this->validateToken($agent);

        if ($agent === null) {
            return ['success' => false, 'message' => 'The agent id is not valid.'];
        }

        return $this->run("callcenter_config agent unpause {$agent}");
    }

    /**
     * Log out a call center agent.
     *
     * @return array{success: bool, message: string}
     */
    public function agentLogout(string $agent): array
    {
        if (! $this->fs->isConnected()) {
            return ['success' => false, 'message' => 'FreeSWITCH is not connected.'];
        }

        $agent = $this->validateToken($agent);

        if ($agent === null) {
            return ['success' => false, 'message' => 'The agent id is not valid.'];
        }

        return $this->run("callcenter_config agent logout {$agent}");
    }

    /**
     * Originate a click-to-call from the operator panel.
     *
     * The source leg is originated (the operator's phone rings first)
     * and bridges to the destination — the FusionPBX click-to-call
     * pattern. An &hangup() application on the destination leg would
     * ring it and drop immediately instead of connecting.
     *
     * @return array{success: bool, message: string}
     */
    public function originate(string $source, string $destination, string $context): array
    {
        if (! $this->fs->isConnected()) {
            return ['success' => false, 'message' => 'FreeSWITCH is not connected.'];
        }

        $source = $this->validateToken($source);
        $destination = $this->validateToken($destination);
        $context = $this->validateToken($context);

        if ($source === null || $destination === null || $context === null) {
            return ['success' => false, 'message' => 'The source, destination, or context is not valid.'];
        }

        return $this->run("originate {origination_caller_id_number={$source}}user/{$source}@{$context} &bridge(user/{$destination}@{$context})");
    }

    /**
     * Validate a channel uuid against the current list after stripping
     * hostile characters (defense in depth against injection and stale rows).
     *
     * @param  array<int, string>  $currentUuids
     */
    private function validateUuid(string $uuid, array $currentUuids): ?string
    {
        $clean = preg_replace('/[^-0-9a-f]/i', '', $uuid) ?? '';

        if ($clean === '' || ! in_array($clean, $currentUuids, true)) {
            return null;
        }

        return $clean;
    }

    /**
     * Strip anything outside a conservative token grammar.
     */
    private function validateToken(string $value): ?string
    {
        $clean = preg_replace('/[^0-9A-Za-z*#+_.@-]/', '', $value) ?? '';

        return $clean === '' ? null : $clean;
    }

    /**
     * Run a command and map the ESL result to a typed outcome.
     *
     * @return array{success: bool, message: string}
     */
    private function run(string $command): array
    {
        $response = $this->fs->api($command);

        if ($response === '' || str_contains($response, '-ERR')) {
            return ['success' => false, 'message' => 'FreeSWITCH rejected the command: '.trim($response)];
        }

        return ['success' => true, 'message' => 'Command accepted.'];
    }
}
