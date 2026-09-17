<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-base-content">{{ __('admin.security_title') }}</h1>
            <p class="text-sm text-base-content/70">{{ __('admin.security_description') }}</p>
        </div>
    </div>

    {{-- System Status Overview Strip --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-base-content/70">{{ __('admin.security_firewall_status') }}</span>
                    <span class="badge badge-success badge-sm gap-1">
                        <span class="inline-block w-2 h-2 rounded-full bg-success-content"></span>
                        {{ __('admin.active') }}
                    </span>
                </div>
                <div class="mt-2 text-lg font-semibold text-base-content">nftables</div>
                <p class="text-xs text-base-content/60">{{ __('admin.security_firewall_help') }}</p>
            </div>
        </div>

        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-base-content/70">{{ __('admin.security_attack_protection') }}</span>
                    <span class="badge badge-success badge-sm gap-1">
                        <span class="inline-block w-2 h-2 rounded-full bg-success-content"></span>
                        {{ __('admin.active') }}
                    </span>
                </div>
                <div class="mt-2 text-lg font-semibold text-base-content">SIP, Web, SSH</div>
                <p class="text-xs text-base-content/60">{{ __('admin.security_attack_help') }}</p>
            </div>
        </div>

        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-base-content/70">{{ __('admin.security_blocked_attackers') }}</span>
                    <span class="badge badge-neutral badge-sm">0</span>
                </div>
                <div class="mt-2 text-lg font-semibold text-base-content">0 {{ __('admin.security_active_bans') }}</div>
                <p class="text-xs text-base-content/60">{{ __('admin.security_bans_help') }}</p>
            </div>
        </div>

        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-base-content/70">{{ __('admin.security_your_connection') }}</span>
                    <span class="badge badge-success badge-sm">{{ __('admin.security_protected') }}</span>
                </div>
                <div class="mt-2 text-lg font-semibold text-base-content">{{ request()->ip() }}</div>
                <p class="text-xs text-base-content/60">{{ __('admin.security_lockout_help') }}</p>
            </div>
        </div>
    </div>
</div>
