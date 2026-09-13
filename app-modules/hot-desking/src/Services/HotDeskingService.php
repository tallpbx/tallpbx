<?php

declare(strict_types=1);

namespace Modules\HotDesking\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Extensions\Models\Extension;
use Modules\HotDesking\Models\HotDeskSession;

/**
 * Service managing hot desking sessions and dynamic FreeSWITCH dialplan routing.
 */
class HotDeskingService implements ContextWideDialplanXmlContributor, HotDeskingServiceInterface
{
    /**
     * Dialplan priority: 65.
     * Evaluated with feature routing before default local extensions.
     */
    public function getDialplanPriority(): int
    {
        return 65;
    }

    /**
     * Start a hot desking session linking a user extension to a physical desk phone extension.
     */
    public function login(int $tenantId, string $extensionId, string $deviceExtensionId, ?string $ipAddress = null, ?string $description = null): HotDeskSession
    {
        return DB::transaction(function () use ($tenantId, $extensionId, $deviceExtensionId, $ipAddress, $description): HotDeskSession {
            // Deactivate any existing active session for this user extension or desk phone extension
            HotDeskSession::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where(function ($query) use ($extensionId, $deviceExtensionId): void {
                    $query->where('extension_id', $extensionId)
                        ->orWhere('device_extension_id', $deviceExtensionId);
                })
                ->update([
                    'is_active' => false,
                    'logout_at' => now(),
                ]);

            return HotDeskSession::create([
                'tenant_id' => $tenantId,
                'extension_id' => $extensionId,
                'device_extension_id' => $deviceExtensionId,
                'ip_address' => $ipAddress,
                'description' => $description,
                'is_active' => true,
                'login_at' => now(),
            ]);
        });
    }

    /**
     * Terminate any active hot desking session for the given extension.
     */
    public function logout(int $tenantId, string $extensionId): bool
    {
        $updated = HotDeskSession::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('extension_id', $extensionId)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'logout_at' => now(),
            ]);

        return $updated > 0;
    }

    /**
     * End a specific hot desking session.
     */
    public function endSession(HotDeskSession $session): bool
    {
        return $session->update([
            'is_active' => false,
            'logout_at' => now(),
        ]);
    }

    /**
     * Retrieve all active hot desking sessions for a tenant.
     *
     * @return Collection<int, HotDeskSession>
     */
    public function getActiveSessions(int $tenantId): Collection
    {
        return HotDeskSession::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->active()
            ->with(['extension', 'deviceExtension'])
            ->latest('login_at')
            ->get();
    }

    /**
     * Find active session for a visiting user extension.
     */
    public function getActiveSessionForExtension(int $tenantId, string $extensionId): ?HotDeskSession
    {
        return HotDeskSession::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('extension_id', $extensionId)
            ->active()
            ->first();
    }

    /**
     * Find active session currently occupying a desk phone extension.
     */
    public function getActiveSessionForDeviceExtension(int $tenantId, string $deviceExtensionId): ?HotDeskSession
    {
        return HotDeskSession::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('device_extension_id', $deviceExtensionId)
            ->active()
            ->first();
    }

    /**
     * Generate dialplan XML for Hot Desking feature codes and dynamic extension routing.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        // Hot desking feature codes and redirection only apply within internal tenant contexts
        if (! str_contains($context, 'internal')) {
            return null;
        }

        $activeSessions = HotDeskSession::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->active()
            ->with(['extension', 'deviceExtension'])
            ->get();

        $isStarCode = in_array($destination, ['*11', '*12'], true);

        if ($activeSessions->isEmpty() && ! $isStarCode) {
            return null;
        }

        $xml = '';

        // Feature code *11: Hot Desk Login
        $xml .= "      <extension name=\"hot_desk_login\">\n";
        $xml .= "        <condition field=\"destination_number\" expression=\"^\\*11$\">\n";
        $xml .= "          <action application=\"answer\"/>\n";
        $xml .= "          <action application=\"set\" data=\"hotdesk_action=login\"/>\n";
        $xml .= "          <action application=\"play_and_get_digits\" data=\"1 10 3 10000 # ivr/ivr-please_enter_extension_followed_by_pound.wav silence_stream://250 hotdesk_user_ext \\d+\"/>\n";
        $xml .= "          <action application=\"play_and_get_digits\" data=\"1 10 3 10000 # ivr/ivr-please_enter_pin_followed_by_pound.wav silence_stream://250 hotdesk_pin \\d+\"/>\n";
        $xml .= "          <action application=\"playback\" data=\"ivr/ivr-you_are_now_logged_in.wav\"/>\n";
        $xml .= "        </condition>\n";
        $xml .= "      </extension>\n";

        // Feature code *12: Hot Desk Logout
        $xml .= "      <extension name=\"hot_desk_logout\">\n";
        $xml .= "        <condition field=\"destination_number\" expression=\"^\\*12$\">\n";
        $xml .= "          <action application=\"answer\"/>\n";
        $xml .= "          <action application=\"set\" data=\"hotdesk_action=logout\"/>\n";
        $xml .= "          <action application=\"playback\" data=\"ivr/ivr-you_are_now_logged_out.wav\"/>\n";
        $xml .= "        </condition>\n";
        $xml .= "      </extension>\n";

        // Dynamic routing for each active hot-desked extension
        foreach ($activeSessions as $session) {
            $userExt = $session->extension?->extension_number;
            $deskExt = $session->deviceExtension?->extension_number;

            if ($userExt === null || $deskExt === null || $userExt === $deskExt) {
                continue;
            }

            $safeUserExt = htmlspecialchars($userExt, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeDeskExt = htmlspecialchars($deskExt, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeSessionId = htmlspecialchars($session->id, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $xml .= "      <extension name=\"hotdesk_route_{$safeSessionId}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeUserExt}$\">\n";
            $xml .= "          <action application=\"set\" data=\"hotdesk_active=true\"/>\n";
            $xml .= "          <action application=\"set\" data=\"hotdesk_target={$safeDeskExt}\"/>\n";
            $xml .= "          <action application=\"transfer\" data=\"{$safeDeskExt} XML \${context}\"/>\n";
            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }
}
