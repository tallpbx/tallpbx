<div class="space-y-6">
    {{-- Header & Top Actions --}}
    <x-page-header :title="__('admin.certificates')" :tooltip="__('admin.certificates_description')" :subtitle="__('admin.certificates_description')" />

    {{-- Transient feedback toast (shared standard component) --}}
    <x-operational-toast :message="$operationalMessage" :type="$operationalMessageType" />

    {{-- Top Overview Cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {{-- Card 1: Web Server HTTPS --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-base-content/70">{{ __('admin.certificates_active_web') }}</span>
                    @if ($this->deploymentStatus['active_web_certificate'])
                        <span class="badge badge-success badge-sm gap-1">
                            <span class="inline-block w-2.5 h-2.5 rounded-full bg-success-content"></span>
                            {{ __('admin.certificates_active_badge') }}
                        </span>
                    @else
                        <span class="badge badge-warning badge-sm">Snakeoil</span>
                    @endif
                </div>
                <div class="mt-2 text-base font-semibold truncate text-base-content" title="{{ $this->deploymentStatus['active_web_certificate']?->name ?? 'Default Web Certificate' }}">
                    {{ $this->deploymentStatus['active_web_certificate']?->name ?? 'Default Nginx Cert' }}
                </div>
                <p class="text-xs text-base-content/60 truncate font-mono">
                    {{ $this->deploymentStatus['active_web_certificate']?->common_name ?? '127.0.0.1' }}
                </p>
                <div class="mt-2 flex items-center justify-between text-xs text-base-content/60">
                    <span>{{ $this->deploymentStatus['active_web_certificate']?->issuer ?? 'Self-Signed' }}</span>
                    @if ($this->deploymentStatus['active_web_certificate'])
                        <span class="{{ $this->deploymentStatus['active_web_certificate']->statusBadgeClass() }}">
                            {{ $this->deploymentStatus['active_web_certificate']->days_until_expiration }}d
                        </span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Card 2: Telephony SIP TLS & WebRTC --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-base-content/70">{{ __('admin.certificates_active_telephony') }}</span>
                    @if ($this->deploymentStatus['active_telephony_certificate'])
                        <span class="badge badge-success badge-sm gap-1">
                            <span class="inline-block w-2.5 h-2.5 rounded-full bg-success-content"></span>
                            {{ __('admin.certificates_active_badge') }}
                        </span>
                    @else
                        <span class="badge badge-neutral badge-sm">FreeSWITCH Default</span>
                    @endif
                </div>
                <div class="mt-2 text-base font-semibold truncate text-base-content" title="{{ $this->deploymentStatus['active_telephony_certificate']?->name ?? 'FreeSWITCH SIP TLS' }}">
                    {{ $this->deploymentStatus['active_telephony_certificate']?->name ?? 'Default FreeSWITCH Cert' }}
                </div>
                <p class="text-xs text-base-content/60 truncate font-mono">
                    {{ $this->deploymentStatus['active_telephony_certificate']?->common_name ?? 'localhost' }}
                </p>
                <div class="mt-2 flex items-center justify-between text-xs text-base-content/60">
                    <span>{{ $this->deploymentStatus['active_telephony_certificate']?->issuer ?? 'FreeSWITCH CA' }}</span>
                    @if ($this->deploymentStatus['active_telephony_certificate'])
                        <span class="{{ $this->deploymentStatus['active_telephony_certificate']->statusBadgeClass() }}">
                            {{ $this->deploymentStatus['active_telephony_certificate']->days_until_expiration }}d
                        </span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Card 3: Total Certificates Installed --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-base-content/70">Total Installed</span>
                    <span class="badge badge-neutral badge-sm">{{ $this->certificates->count() }}</span>
                </div>
                <div class="mt-2 text-2xl font-bold text-base-content">
                    {{ $this->certificates->count() }}
                </div>
                <p class="text-xs text-base-content/60">Active inventory certificates</p>
            </div>
        </div>

        {{-- Card 4: Expiring Soon Warning --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-base-content/70">Expiration Health</span>
                    @if ($this->expiringSoonCount > 0)
                        <span class="badge badge-warning badge-sm">{{ $this->expiringSoonCount }} expiring</span>
                    @else
                        <span class="badge badge-success badge-sm">Healthy</span>
                    @endif
                </div>
                <div class="mt-2 text-2xl font-bold {{ $this->expiringSoonCount > 0 ? 'text-warning' : 'text-success' }}">
                    {{ $this->expiringSoonCount > 0 ? $this->expiringSoonCount . ' Expiring' : 'All Valid' }}
                </div>
                <p class="text-xs text-base-content/60">
                    {{ $this->expiringSoonCount > 0 ? 'Certificates expiring within 30 days' : 'No certificates expiring soon' }}
                </p>
            </div>
        </div>
    </div>

    {{-- Tabs Strip (using accessible shared x-tabs component) --}}
    <x-tabs :tabs="[
        'inventory' => ['label' => __('admin.certificates_inventory'), 'icon' => 'heroicon-o-table-cells'],
        'letsencrypt' => ['label' => __('admin.certificates_lets_encrypt'), 'icon' => 'heroicon-o-arrow-path-rounded-square'],
        'import' => ['label' => __('admin.certificates_custom'), 'icon' => 'heroicon-o-arrow-up-tray'],
        'selfsigned' => ['label' => __('admin.certificates_self_signed'), 'icon' => 'heroicon-o-sparkles'],
        'dnsvault' => ['label' => __('admin.certificates_dnsvault'), 'icon' => 'heroicon-o-key'],
        'logs' => ['label' => __('admin.certificates_audit_logs'), 'icon' => 'heroicon-o-document-text'],
    ]" :active="$activeTab" wire-method="switchTab" />

    {{-- TAB 1: INVENTORY & SERVICES --}}
    @include('certificates::partials.tab-inventory')

    {{-- TAB 2: LET'S ENCRYPT ACME ISSUANCE --}}
    @include('certificates::partials.tab-letsencrypt')

    {{-- TAB 3: CUSTOM PEM IMPORT --}}
    @include('certificates::partials.tab-import')

    {{-- TAB 4: SELF-SIGNED CERTIFICATE GENERATOR --}}
    @include('certificates::partials.tab-selfsigned')

    {{-- TAB 5: DNS CREDENTIALS VAULT --}}
    @include('certificates::partials.tab-dnsvault')

    {{-- TAB 6: AUDIT LOGS --}}
    @include('certificates::partials.tab-logs')

    {{-- Modals Partial --}}
    @include('certificates::partials.certificate-modals')
</div>
