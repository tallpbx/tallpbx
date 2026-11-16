<?php

declare(strict_types=1);

namespace App\Services\Cdr;

use App\Models\Tenant;
use App\Services\DialplanContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;

/**
 * Turns one raw mod_json_cdr document into a row for the cdrs table.
 *
 * Every field name below is verified against payloads captured from a real
 * bridged call: the originating leg carries last_bridge_role "originator"
 * (plus the originated_legs list and last_app "bridge"), both legs carry
 * signal_bond / bridge_uuid pointing at each other, caller identity lives in
 * the first callflow caller_profile, and every timestamp exists twice — as a
 * human-readable stamp and as an epoch, of which only the epoch is parsed.
 *
 * Anything that cannot be mapped — malformed JSON, a missing call uuid, or a
 * payload with no trustworthy call-time tenant identity — returns null so the
 * digest cycle quarantines the payload instead of inserting an incomplete or
 * misattributed record.
 */
class CdrPayloadParser
{
    /**
     * Create the parser.
     *
     * @param  string|null  $quarantineDir  Directory where unparseable
     *                                      payloads are preserved for review;
     *                                      null reads the configured default.
     */
    public function __construct(private readonly ?string $quarantineDir = null) {}

    /**
     * Parse one raw payload into a cdrs row, or null when it must be kept.
     *
     * @return array<string, mixed>|null
     */
    public function parse(string $rawJson): ?array
    {
        try {
            $payload = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Log::warning('CDR payload is not valid JSON; quarantining.');

            return null;
        }

        $variables = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
        $profile = is_array($payload['callflow'][0]['caller_profile'] ?? null) ? $payload['callflow'][0]['caller_profile'] : [];

        $callUuid = $this->string($variables['uuid'] ?? null);

        if ($callUuid === null) {
            // Without a uuid the (call_uuid, leg) deduplication key cannot
            // exist, so the record could never be replayed safely.
            Log::warning('CDR payload has no call uuid; quarantining.');

            return null;
        }

        $domain = $this->string($variables['domain_name'] ?? null);
        $tenantId = $this->resolveTenantId($variables, $profile, $domain);

        if ($tenantId === null) {
            // tenant_id is NOT NULL and constrained. The record is preserved in
            // the quarantine for explicit operator review rather than inserted
            // against a guessed owner.
            return null;
        }

        $bond = $this->string($variables['bridge_uuid'] ?? null) ?? $this->string($variables['signal_bond'] ?? null);
        $now = Carbon::now();

        return [
            // Bulk upserts bypass HasUuids, so the primary key is assigned here.
            'id' => (string) Str::orderedUuid(),
            'tenant_id' => (int) $tenantId,
            'call_uuid' => $callUuid,
            'b_leg_uuid' => $bond,
            'leg' => $this->originated($variables) ? 'a' : ($bond !== null ? 'b' : 'a'),
            'caller_id' => $this->string($profile['caller_id_number'] ?? null) ?? $this->string($variables['caller_id_number'] ?? null),
            'caller_id_name' => $this->string($profile['caller_id_name'] ?? null) ?? $this->string($variables['caller_id_name'] ?? null),
            'destination' => $this->string($profile['destination_number'] ?? null) ?? $this->string($variables['destination_number'] ?? null),
            'duration' => (int) ($variables['duration'] ?? 0),
            'billsec' => (int) ($variables['billsec'] ?? 0),
            'hangup_cause' => $this->string($variables['hangup_cause'] ?? null),
            'direction' => $this->string($variables['direction'] ?? null) ?? $this->string($payload['channel_data']['direction'] ?? null),
            'record_path' => $this->recordPath($variables),
            'start_stamp' => $this->stamp($variables, 'start'),
            'answer_stamp' => $this->stamp($variables, 'answer'),
            'end_stamp' => $this->stamp($variables, 'end'),
            // upsert() bypasses Eloquent casts, so the payload stays a string.
            'json_data' => $rawJson,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Resolve the call-time tenant identity from the leg itself.
     *
     * Two call-time sources can name the owner: the channel variable the
     * dialplan stamped on the leg, and the tenant context the channel executed.
     * They must agree. `domain_name` is deliberately not consulted: a domain is
     * diagnostic context, can be reassigned between hangup and ingestion, and
     * can describe the caller on a cross-tenant B-leg.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $profile
     */
    private function resolveTenantId(array $variables, array $profile, ?string $domain): ?int
    {
        $variable = $this->string($variables['tenant_id'] ?? null);
        $variableId = $variable !== null && is_numeric($variable) ? (int) $variable : null;

        $context = $this->string($variables['context'] ?? null)
            ?? $this->string($profile['context'] ?? null);
        $parsedContextId = $context === null ? null : app(DialplanContext::class)->parseTenantId($context);
        $contextId = $parsedContextId !== null && is_numeric($parsedContextId) ? (int) $parsedContextId : null;

        $logContext = ['domain' => $domain, 'context' => $context];

        if ($variableId !== null && $contextId !== null && $variableId !== $contextId) {
            Log::warning('CDR payload carries conflicting tenant identity; quarantining.', [
                ...$logContext,
                'reason' => 'conflicting-identity',
            ]);

            return null;
        }

        // A non-tenant context is normal traffic, not an error: `null` here
        // means "no context candidate", never "unresolved context".
        $candidate = $variableId ?? $contextId;

        if ($candidate === null) {
            Log::warning('CDR payload has no call-time tenant identity; quarantining.', [
                ...$logContext,
                'reason' => 'missing-identity',
            ]);

            return null;
        }

        if (! Tenant::query()->whereKey($candidate)->exists()) {
            Log::warning('CDR payload names a tenant that does not exist; quarantining.', [
                ...$logContext,
                'reason' => 'unknown-tenant',
                'tenant_id' => $candidate,
            ]);

            return null;
        }

        return $candidate;
    }

    /**
     * Preserve an unparsable payload on disk so it is never silently dropped.
     *
     * @param  string  $entryId  Redis stream id, used in the file name
     * @return bool True when the copy exists; the digest cycle keeps the
     *              stream entry when it does not.
     */
    public function quarantine(string $entryId, string $rawJson): bool
    {
        $dir = $this->quarantineDir ?? (string) config('freeswitch.cdr.quarantine_dir', '/var/log/freeswitch/json_cdr/quarantine');

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            Log::error('CDR quarantine directory is not writable.', ['dir' => $dir]);

            return false;
        }

        $safeId = (string) (preg_replace('/[^0-9A-Za-z._-]+/', '_', $entryId) ?: 'unknown');
        $written = @file_put_contents($dir.'/'.now()->format('YmdHis')."-{$safeId}.json", $rawJson);

        if ($written === false) {
            Log::error('CDR quarantine write failed.', ['dir' => $dir]);

            return false;
        }

        return true;
    }

    /**
     * Report whether this payload is the leg that originated or bridged the
     * call — the verified markers for the a-side.
     *
     * @param  array<string, mixed>  $variables
     */
    private function originated(array $variables): bool
    {
        return ($variables['last_bridge_role'] ?? null) === 'originator'
            || $this->string($variables['originated_legs'] ?? null) !== null
            || ($variables['last_app'] ?? null) === 'bridge';
    }

    /**
     * Combine FreeSWITCH's record directory and file name variables.
     *
     * @param  array<string, mixed>  $variables
     */
    private function recordPath(array $variables): ?string
    {
        $path = $this->string($variables['record_path'] ?? null);
        $name = $this->string($variables['record_name'] ?? null);

        return match (true) {
            $path !== null && $name !== null => rtrim($path, '/').'/'.$name,
            $path !== null => $path,
            default => $name,
        };
    }

    /**
     * Resolve one stamp from its epoch variable, ignoring unanswered zeros.
     *
     * @param  array<string, mixed>  $variables
     */
    private function stamp(array $variables, string $name): ?Carbon
    {
        $epoch = $variables[$name.'_epoch'] ?? null;

        return is_numeric($epoch) && (int) $epoch > 0
            ? Carbon::createFromTimestampUTC((int) $epoch)
            : null;
    }

    /**
     * Normalize one scalar to a non-empty trimmed string.
     */
    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
