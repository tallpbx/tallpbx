<?php

declare(strict_types=1);

namespace Modules\Security\Livewire\Concerns;

use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Rules\ValidFirewallAddress;
use Modules\Security\Services\LockoutGuardService;

/**
 * Livewire component concern for managing IP allow and block lists.
 */
trait ManagesAllowBlockLists
{
    public string $newBlacklistIp = '';

    public string $newBlacklistDescription = '';

    public string $newWhitelistIp = '';

    public string $newWhitelistDescription = '';

    public string $ipSearch = '';

    public string $whitelistSearch = '';

    public string $blacklistSearch = '';

    /**
     * 1-click rescue action: unconditionally whitelist the current administrator IP.
     */
    public function whitelistCurrentIp(LockoutGuardService $lockoutGuard): void
    {
        $lockoutGuard->whitelistIp($this->adminIp, 'Auto-whitelisted administrator session');
        $this->isCurrentIpWhitelisted = true;

        // Report success only when the kernel actually accepted the ruleset.
        if ($this->autoApplyFirewallRuleset($lockoutGuard)) {
            $this->notifySuccess((string) __('admin.security_ip_protected_success'));
        }
    }

    /**
     * Add a new IP or CIDR subnet to the permanent blacklist.
     */
    public function addBlacklistIp(LockoutGuardService $lockoutGuard, SecurityBanServiceInterface $banService): void
    {
        $this->validate([
            'newBlacklistIp' => [
                'required',
                // Both IPv4 and IPv6 entries (with optional CIDR) are validated
                // by the shared rule, which also rejects malformed values.
                new ValidFirewallAddress((string) __('admin.security_ip_format_invalid')),
            ],
            'newBlacklistDescription' => ['nullable', 'string', 'max:255'],
        ]);

        $ip = trim($this->newBlacklistIp);

        // Whitelisted addresses are immune to blocking, and the kernel drop
        // rules evaluate before the whitelist bypass, so blacklisting a
        // trusted address would silently defeat its protection (and can lock
        // out the administrator's own session). Refuse with an inline error.
        if ($lockoutGuard->isWhitelisted($ip)) {
            $this->addError('newBlacklistIp', (string) __('admin.security_cannot_blacklist_whitelisted', ['ip' => $ip]));

            return;
        }

        if (SecurityIpList::where('type', 'blacklist')->where('ip_address', $ip)->exists()) {
            $this->addError('newBlacklistIp', 'This IP address is already present in the blacklist.');

            return;
        }

        SecurityIpList::create([
            'type' => 'blacklist',
            'ip_address' => $ip,
            'description' => $this->newBlacklistDescription ? trim($this->newBlacklistDescription) : null,
        ]);

        $this->newBlacklistIp = '';
        $this->newBlacklistDescription = '';
        $this->checkAdminIpStatus($lockoutGuard);

        if ($this->autoApplyFirewallRuleset($lockoutGuard)) {
            // The block only filters new flows (blocklists sit behind the
            // stateful fast path), so sever the address's live sessions now
            // that its block is live in the kernel (invariant 4).
            $banService->flushConntrack($ip);
            $this->notifySuccess((string) __('admin.security_ip_added'));
        }
    }

    /**
     * Add a new IP or CIDR subnet to the trusted whitelist.
     */
    public function addWhitelistIp(LockoutGuardService $lockoutGuard): void
    {
        $this->validate([
            'newWhitelistIp' => [
                'required',
                // Both IPv4 and IPv6 entries (with optional CIDR) are validated
                // by the shared rule, which also rejects malformed values.
                new ValidFirewallAddress((string) __('admin.security_ip_format_invalid')),
            ],
            'newWhitelistDescription' => ['nullable', 'string', 'max:255'],
        ]);

        $ip = trim($this->newWhitelistIp);

        if (SecurityIpList::where('type', 'whitelist')->where('ip_address', $ip)->exists()) {
            $this->addError('newWhitelistIp', 'This IP address is already present in the whitelist.');

            return;
        }

        SecurityIpList::create([
            'type' => 'whitelist',
            'ip_address' => $ip,
            'description' => $this->newWhitelistDescription ? trim($this->newWhitelistDescription) : null,
        ]);

        $this->newWhitelistIp = '';
        $this->newWhitelistDescription = '';
        $this->checkAdminIpStatus($lockoutGuard);

        if ($this->autoApplyFirewallRuleset($lockoutGuard)) {
            $this->notifySuccess((string) __('admin.security_ip_added'));
        }
    }

    /**
     * Delete an entry from the IP lists table.
     */
    public function deleteIp(int $id, LockoutGuardService $lockoutGuard): void
    {
        $entry = SecurityIpList::findOrFail($id);

        // Remember the entry so a refused apply can be rolled back: removing
        // the administrator's own trusted address while the default policy
        // blocks would lock them out, so the guard refuses the apply — and
        // the panel must not then claim the address is gone while the kernel
        // still trusts it.
        $attributes = $entry->only(['type', 'ip_address', 'description']);

        $entry->delete();

        $this->checkAdminIpStatus($lockoutGuard);

        if (! $this->autoApplyFirewallRuleset($lockoutGuard)) {
            SecurityIpList::create($attributes);

            return;
        }

        $this->notifySuccess((string) __('admin.security_ip_deleted'));
    }

    /**
     * Promote an active ban to the permanent Trusted whitelist.
     */
    public function promoteToWhitelist(string $ip, SecurityBanServiceInterface $banService, LockoutGuardService $lockoutGuard): void
    {
        if (method_exists($banService, 'promoteToWhitelist')) {
            $banService->promoteToWhitelist($ip, 'Promoted from active threats by administrator');
        } else {
            $banService->unban($ip);
            SecurityIpList::updateOrCreate(
                ['type' => 'whitelist', 'ip_address' => $ip],
                ['description' => 'Promoted from active threats by administrator']
            );
        }

        $this->checkAdminIpStatus($lockoutGuard);

        if ($this->autoApplyFirewallRuleset($lockoutGuard)) {
            $this->notifySuccess((string) __('admin.security_promoted_whitelist'));
        }
    }

    /**
     * Promote an active ban to the permanent Blocked blacklist.
     */
    public function promoteToBlacklist(string $ip, SecurityBanServiceInterface $banService): void
    {
        if (method_exists($banService, 'promoteToBlacklist')) {
            $banService->promoteToBlacklist($ip, 'Promoted from active threats to permanent blacklist');
        } else {
            $banService->unban($ip);
            SecurityIpList::updateOrCreate(
                ['type' => 'blacklist', 'ip_address' => $ip],
                ['description' => 'Promoted from active threats to permanent blacklist']
            );
        }

        if ($this->autoApplyFirewallRuleset()) {
            // Sever the promoted address's live sessions now that its
            // permanent block is live in the kernel (invariant 4).
            $banService->flushConntrack($ip);
            $this->notifySuccess((string) __('admin.security_promoted_blacklist'));
        }
    }
}
