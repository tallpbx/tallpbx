<div class="space-y-6">
    {{-- Header & Top Actions --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-base-content">
                    {{ __('admin.certificates') }}
                </h1>
                <x-tooltip :tip="__('admin.certificates_description')" align="start" position="right">
                    <x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" />
                </x-tooltip>
            </div>
            <p class="text-sm text-base-content/70 mt-1">
                {{ __('admin.certificates_description') }}
            </p>
        </div>
    </div>

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
                            <span class="inline-block w-2 h-2 rounded-full bg-success-content"></span>
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
                            <span class="inline-block w-2 h-2 rounded-full bg-success-content"></span>
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

    {{-- Tabs Strip --}}
    <div class="overflow-x-auto">
        <div role="tablist" class="tabs tabs-lift tabs-sm">
            <button role="tab" type="button" wire:click="switchTab('inventory')" id="tab-inventory"
                    class="tab gap-1.5 {{ $activeTab === 'inventory' ? 'tab-active' : '' }}">
                <x-heroicon-o-table-cells class="w-4 h-4" />
                {{ __('admin.certificates_inventory') }}
            </button>
            <button role="tab" type="button" wire:click="switchTab('letsencrypt')" id="tab-letsencrypt"
                    class="tab gap-1.5 {{ $activeTab === 'letsencrypt' ? 'tab-active' : '' }}">
                <x-heroicon-o-arrow-path-rounded-square class="w-4 h-4" />
                {{ __('admin.certificates_lets_encrypt') }}
            </button>
            <button role="tab" type="button" wire:click="switchTab('import')" id="tab-import"
                    class="tab gap-1.5 {{ $activeTab === 'import' ? 'tab-active' : '' }}">
                <x-heroicon-o-arrow-up-tray class="w-4 h-4" />
                {{ __('admin.certificates_custom') }}
            </button>
            <button role="tab" type="button" wire:click="switchTab('selfsigned')" id="tab-selfsigned"
                    class="tab gap-1.5 {{ $activeTab === 'selfsigned' ? 'tab-active' : '' }}">
                <x-heroicon-o-sparkles class="w-4 h-4" />
                {{ __('admin.certificates_self_signed') }}
            </button>
            <button role="tab" type="button" wire:click="switchTab('dnsvault')" id="tab-dnsvault"
                    class="tab gap-1.5 {{ $activeTab === 'dnsvault' ? 'tab-active' : '' }}">
                <x-heroicon-o-key class="w-4 h-4" />
                {{ __('admin.certificates_dnsvault') }}
            </button>
            <button role="tab" type="button" wire:click="switchTab('logs')" id="tab-logs"
                    class="tab gap-1.5 {{ $activeTab === 'logs' ? 'tab-active' : '' }}">
                <x-heroicon-o-document-text class="w-4 h-4" />
                {{ __('admin.certificates_audit_logs') }}
            </button>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════════ --}}
    {{-- TAB 1: INVENTORY & SERVICES --}}
    {{-- ════════════════════════════════════════════════════════════════════ --}}
    @if ($activeTab === 'inventory')
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4 space-y-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="relative w-full sm:w-72">
                        <input type="text"
                               wire:model.live.debounce.300ms="search"
                               placeholder="Search certificates..."
                               class="input input-sm input-bordered w-full pr-8" />
                        <x-heroicon-o-magnifying-glass class="w-4 h-4 absolute right-2.5 top-2.5 text-base-content/40" />
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="switchTab('letsencrypt')" class="btn btn-primary btn-sm gap-1">
                            <x-heroicon-o-plus class="w-4 h-4" />
                            Issue Certificate
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="table table-zebra table-sm w-full">
                        <thead>
                            <tr>
                                <th>Name / Domain</th>
                                <th>Type</th>
                                <th>SAN Domains</th>
                                <th>Expires In</th>
                                <th>Assigned Services</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->certificates as $cert)
                                <tr>
                                    <td>
                                        <div class="font-medium text-base-content">{{ $cert->name }}</div>
                                        <div class="text-xs font-mono text-base-content/70">{{ $cert->common_name }}</div>
                                    </td>
                                    <td>
                                        @if ($cert->type === \Modules\Certificates\Models\Certificate::TYPE_LETS_ENCRYPT)
                                            <span class="badge badge-primary badge-sm">Let's Encrypt</span>
                                        @elseif ($cert->type === \Modules\Certificates\Models\Certificate::TYPE_CUSTOM)
                                            <span class="badge badge-info badge-sm">Custom</span>
                                        @else
                                            <span class="badge badge-secondary badge-sm">Self-Signed</span>
                                        @endif
                                    </td>
                                    <td>
                                        @php $sans = $cert->san_domains ?? []; @endphp
                                        @if (count($sans) > 0)
                                            <x-tooltip :tip="implode(', ', $sans)" position="top">
                                                <span class="badge badge-ghost badge-sm cursor-help">{{ count($sans) }} SANs</span>
                                            </x-tooltip>
                                        @else
                                            <span class="text-xs text-base-content/40">None</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="{{ $cert->statusBadgeClass() }}">
                                            @if ($cert->isExpired())
                                                Expired
                                            @else
                                                {{ $cert->days_until_expiration }} days
                                            @endif
                                        </span>
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-1">
                                            @if ($cert->is_default_web)
                                                <span class="badge badge-success badge-sm">Web HTTPS</span>
                                            @endif
                                            @if ($cert->is_default_telephony)
                                                <span class="badge badge-primary badge-sm">SIP / WSS</span>
                                            @endif
                                            @if (! $cert->is_default_web && ! $cert->is_default_telephony)
                                                <span class="badge badge-ghost badge-sm text-base-content/40">None</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <div class="inline-flex items-center gap-1">
                                            <button type="button"
                                                    id="assign-cert-{{ $cert->id }}"
                                                    wire:click="openAssignModal({{ $cert->id }})"
                                                    class="btn btn-ghost btn-xs"
                                                    title="Assign Services">
                                                Deploy
                                            </button>
                                            <button type="button"
                                                    wire:click="openDetailsModal({{ $cert->id }})"
                                                    class="btn btn-ghost btn-xs"
                                                    title="View Details">
                                                Details
                                            </button>
                                            @if ($cert->type === \Modules\Certificates\Models\Certificate::TYPE_LETS_ENCRYPT)
                                                <button type="button"
                                                        wire:click="renewCertificate({{ $cert->id }})"
                                                        wire:loading.attr="disabled"
                                                        class="btn btn-ghost btn-xs text-info"
                                                        title="Renew Now">
                                                    Renew
                                                </button>
                                            @endif
                                            <button type="button"
                                                    wire:click="deleteCertificate({{ $cert->id }})"
                                                    wire:confirm="Are you sure you want to delete certificate '{{ $cert->name }}'? This action cannot be undone."
                                                    @if ($cert->is_default_web || $cert->is_default_telephony) disabled @endif
                                                    class="btn btn-ghost btn-xs text-error"
                                                    title="Delete Certificate">
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-6 text-base-content/60">
                                        {{ __('admin.certificates_no_certificates') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- ════════════════════════════════════════════════════════════════════ --}}
    {{-- TAB 2: LET'S ENCRYPT ACME ISSUANCE --}}
    {{-- ════════════════════════════════════════════════════════════════════ --}}
    @if ($activeTab === 'letsencrypt')
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-6 space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-base-content">Issue Let's Encrypt Certificate</h2>
                    <p class="text-xs text-base-content/70">
                        Automatically request and validate free, trusted SSL/TLS certificates via the ACME v2 protocol.
                    </p>
                </div>

                <form wire:submit="issueLetsEncrypt" class="space-y-4 max-w-2xl">
                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">Certificate Display Name</span>
                        </label>
                        <input type="text" wire:model="le_name" placeholder="e.g. Primary PBX FQDN" class="input input-sm input-bordered w-full" required />
                        @error('le_name') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">Domain Name (FQDN)</span>
                            <x-tooltip tip="Public domain pointing to this server IP address" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50" />
                            </x-tooltip>
                        </label>
                        <input type="text" wire:model="le_domain" placeholder="e.g. pbx.example.com" class="input input-sm input-bordered w-full font-mono" required />
                        @error('le_domain') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">Administrator Email</span>
                            <x-tooltip tip="Used by Let's Encrypt to send urgent renewal and security notices" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50" />
                            </x-tooltip>
                        </label>
                        <input type="email" wire:model="le_email" placeholder="admin@example.com" class="input input-sm input-bordered w-full" required />
                        @error('le_email') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">ACME Validation Challenge</span>
                        </label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="flex items-center gap-3 p-3 rounded-lg border border-base-200 cursor-pointer hover:bg-base-200/50">
                                <input type="radio" wire:model.live="le_challenge_type" value="http" class="radio radio-primary radio-sm" />
                                <div>
                                    <div class="text-sm font-semibold">HTTP-01 Webroot</div>
                                    <div class="text-xs text-base-content/60">Requires Port 80 to reach this PBX</div>
                                </div>
                            </label>
                            <label class="flex items-center gap-3 p-3 rounded-lg border border-base-200 cursor-pointer hover:bg-base-200/50">
                                <input type="radio" wire:model.live="le_challenge_type" value="dns" class="radio radio-primary radio-sm" />
                                <div>
                                    <div class="text-sm font-semibold">DNS-01 Cloudflare</div>
                                    <div class="text-xs text-base-content/60">Supports wildcard (*.domain.com)</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    @if ($le_challenge_type === 'dns')
                        <div class="p-4 rounded-lg bg-base-200/50 border border-base-200 space-y-3">
                            <div class="form-control">
                                <label class="label justify-start gap-2">
                                    <span class="label-text font-medium">Cloudflare API Token</span>
                                    <a href="#" wire:click.prevent="switchTab('dnsvault')" class="text-xs link link-primary">Manage Vault</a>
                                </label>
                                <select wire:model="le_dns_credential_id" class="select select-sm select-bordered w-full">
                                    <option value="">Select a saved Cloudflare credential...</option>
                                    @foreach ($this->dnsCredentials as $cred)
                                        <option value="{{ $cred->id }}">{{ $cred->name }} ({{ $cred->provider }})</option>
                                    @endforeach
                                </select>
                                @error('le_dns_credential_id') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                            </div>

                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="le_wildcard" class="checkbox checkbox-primary checkbox-sm" />
                                <span class="text-sm">Include Wildcard (*.domain.com)</span>
                            </label>
                        </div>
                    @endif

                    <div class="space-y-2 pt-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="le_staging" class="checkbox checkbox-warning checkbox-sm" />
                            <span class="text-sm">Use Let's Encrypt Staging Environment (Test issuance without rate limits)</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="le_auto_deploy_web" class="checkbox checkbox-primary checkbox-sm" />
                            <span class="text-sm">Deploy to Nginx Web upon successful issuance</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="le_auto_deploy_telephony" class="checkbox checkbox-primary checkbox-sm" />
                            <span class="text-sm">Deploy to FreeSWITCH Telephony upon successful issuance</span>
                        </label>
                    </div>

                    <div class="pt-4">
                        <button type="submit" wire:loading.attr="disabled" class="btn btn-primary btn-sm gap-2">
                            <span wire:loading class="loading loading-spinner loading-xs"></span>
                            <x-heroicon-o-shield-check class="w-4 h-4" />
                            Issue Certificate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ════════════════════════════════════════════════════════════════════ --}}
    {{-- TAB 3: CUSTOM PEM IMPORT --}}
    {{-- ════════════════════════════════════════════════════════════════════ --}}
    @if ($activeTab === 'import')
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-6 space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-base-content">Import Custom SSL/TLS Certificate</h2>
                    <p class="text-xs text-base-content/70">
                        Paste standard PEM-formatted certificate files purchased from a commercial Certificate Authority.
                    </p>
                </div>

                <form wire:submit="importCustomCertificate" class="space-y-4 max-w-2xl">
                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">Certificate Display Name</span>
                        </label>
                        <input type="text" wire:model="custom_name" placeholder="e.g. Commercial Wildcard SSL" class="input input-sm input-bordered w-full" required />
                        @error('custom_name') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control">
                        <div class="flex items-center justify-between">
                            <label class="label justify-start gap-2">
                                <span class="label-text font-medium">Certificate PEM (cert.pem / fullchain.pem)</span>
                            </label>
                            @if ($this->modulusMatches === true)
                                <span class="badge badge-success badge-sm gap-1">✓ Modulus Matches</span>
                            @elseif ($this->modulusMatches === false)
                                <span class="badge badge-error badge-sm gap-1">✗ Key Modulus Mismatch</span>
                            @endif
                        </div>
                        <textarea wire:model.live.debounce.500ms="custom_cert"
                                  rows="5"
                                  placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"
                                  class="textarea textarea-bordered font-mono text-xs w-full" required></textarea>
                        @error('custom_cert') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">Private Key PEM (privkey.pem)</span>
                        </label>
                        <textarea wire:model.live.debounce.500ms="custom_key"
                                  rows="5"
                                  placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"
                                  class="textarea textarea-bordered font-mono text-xs w-full" required></textarea>
                        @error('custom_key') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">Intermediate CA Chain PEM (optional)</span>
                        </label>
                        <textarea wire:model="custom_chain"
                                  rows="3"
                                  placeholder="-----BEGIN CERTIFICATE----- (Optional Intermediate CA)&#10;...&#10;-----END CERTIFICATE-----"
                                  class="textarea textarea-bordered font-mono text-xs w-full"></textarea>
                    </div>

                    <div class="space-y-2 pt-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="custom_auto_deploy_web" class="checkbox checkbox-primary checkbox-sm" />
                            <span class="text-sm">Deploy to Nginx Web upon successful import</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="custom_auto_deploy_telephony" class="checkbox checkbox-primary checkbox-sm" />
                            <span class="text-sm">Deploy to FreeSWITCH Telephony upon successful import</span>
                        </label>
                    </div>

                    <div class="pt-4">
                        <button type="submit" wire:loading.attr="disabled" class="btn btn-primary btn-sm gap-2">
                            <span wire:loading class="loading loading-spinner loading-xs"></span>
                            <x-heroicon-o-arrow-up-tray class="w-4 h-4" />
                            Import & Save Certificate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ════════════════════════════════════════════════════════════════════ --}}
    {{-- TAB 4: SELF-SIGNED CERTIFICATE GENERATOR --}}
    {{-- ════════════════════════════════════════════════════════════════════ --}}
    @if ($activeTab === 'selfsigned')
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-6 space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-base-content">Generate Self-Signed Certificate</h2>
                    <p class="text-xs text-base-content/70">
                        Generate cryptographic RSA self-signed certificates with custom SANs for testing, lab environments, and internal SIP TLS.
                    </p>
                </div>

                <form wire:submit="generateSelfSigned" class="space-y-4 max-w-2xl">
                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">Certificate Display Name</span>
                        </label>
                        <input type="text" wire:model="self_name" placeholder="e.g. Lab PBX Internal Cert" class="input input-sm input-bordered w-full" required />
                        @error('self_name') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">Common Name (Primary FQDN or IP)</span>
                        </label>
                        <input type="text" wire:model="self_common_name" placeholder="e.g. 192.168.1.100 or pbx.local" class="input input-sm input-bordered w-full font-mono" required />
                        @error('self_common_name') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">Subject Alternative Names (SANs)</span>
                            <x-tooltip tip="Comma-separated list of additional domain names or IP addresses" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50" />
                            </x-tooltip>
                        </label>
                        <input type="text" wire:model="self_san_domains" placeholder="e.g. pbx.local, 192.168.1.100, webrtc.local" class="input input-sm input-bordered w-full font-mono" />
                    </div>

                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">Validity Duration</span>
                        </label>
                        <select wire:model="self_days" class="select select-sm select-bordered w-full">
                            <option value="365">1 Year (365 days)</option>
                            <option value="730">2 Years (730 days)</option>
                            <option value="1825">5 Years (1825 days)</option>
                        </select>
                    </div>

                    <div class="space-y-2 pt-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="self_auto_deploy_web" class="checkbox checkbox-primary checkbox-sm" />
                            <span class="text-sm">Deploy to Nginx Web upon generation</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="self_auto_deploy_telephony" class="checkbox checkbox-primary checkbox-sm" />
                            <span class="text-sm">Deploy to FreeSWITCH Telephony upon generation</span>
                        </label>
                    </div>

                    <div class="pt-4">
                        <button type="submit" wire:loading.attr="disabled" class="btn btn-primary btn-sm gap-2">
                            <span wire:loading class="loading loading-spinner loading-xs"></span>
                            <x-heroicon-o-sparkles class="w-4 h-4" />
                            Generate Certificate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ════════════════════════════════════════════════════════════════════ --}}
    {{-- TAB 5: DNS CREDENTIALS VAULT --}}
    {{-- ════════════════════════════════════════════════════════════════════ --}}
    @if ($activeTab === 'dnsvault')
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body p-4 space-y-4">
                    <h2 class="text-base font-bold text-base-content">Stored DNS Provider Credentials</h2>
                    <div class="overflow-x-auto">
                        <table class="table table-zebra table-sm w-full">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Provider</th>
                                    <th>Created</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->dnsCredentials as $cred)
                                    <tr>
                                        <td class="font-medium">{{ $cred->name }}</td>
                                        <td>
                                            <span class="badge badge-outline badge-sm">{{ ucfirst($cred->provider) }}</span>
                                        </td>
                                        <td class="text-xs text-base-content/60">{{ $cred->created_at?->diffForHumans() }}</td>
                                        <td class="text-right">
                                            <button type="button"
                                                    wire:click="deleteDnsCredential({{ $cred->id }})"
                                                    wire:confirm="Delete DNS credential '{{ $cred->name }}'?"
                                                    class="btn btn-ghost btn-xs text-error">
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center py-6 text-base-content/60">
                                            No DNS credentials stored in vault yet.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body p-4 space-y-4">
                    <h2 class="text-base font-bold text-base-content">Add Cloudflare API Token</h2>
                    <form wire:submit="createDnsCredential" class="space-y-3">
                        <div class="form-control">
                            <label class="label justify-start gap-2">
                                <span class="label-text font-medium">Credential Name</span>
                            </label>
                            <input type="text" wire:model="new_dns_name" placeholder="e.g. Cloudflare Production" class="input input-sm input-bordered w-full" required />
                            @error('new_dns_name') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                        </div>

                        <div class="form-control">
                            <label class="label justify-start gap-2">
                                <span class="label-text font-medium">Cloudflare API Token</span>
                                <x-tooltip tip="API Token with Zone:DNS:Edit permissions" position="top">
                                    <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50" />
                                </x-tooltip>
                            </label>
                            <input type="password" wire:model="new_dns_api_token" placeholder="••••••••••••••••" class="input input-sm input-bordered w-full font-mono" required />
                            @error('new_dns_api_token') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                        </div>

                        <div class="pt-2">
                            <button type="submit" class="btn btn-primary btn-sm w-full gap-2">
                                <x-heroicon-o-key class="w-4 h-4" />
                                Save to Vault
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ════════════════════════════════════════════════════════════════════ --}}
    {{-- TAB 6: AUDIT LOGS --}}
    {{-- ════════════════════════════════════════════════════════════════════ --}}
    @if ($activeTab === 'logs')
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4 space-y-4">
                <div class="overflow-x-auto">
                    <table class="table table-zebra table-sm w-full">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Action</th>
                                <th>Status</th>
                                <th>Administrator</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->auditLogs as $log)
                                <tr>
                                    <td class="text-xs font-mono whitespace-nowrap text-base-content/70">
                                        {{ $log->created_at?->format('Y-m-d H:i:s') }}
                                    </td>
                                    <td>
                                        <span class="badge badge-outline badge-sm">{{ $log->action }}</span>
                                    </td>
                                    <td>
                                        @if ($log->status === 'success')
                                            <span class="badge badge-success badge-sm">Success</span>
                                        @elseif ($log->status === 'error')
                                            <span class="badge badge-error badge-sm">Error</span>
                                        @else
                                            <span class="badge badge-warning badge-sm">{{ $log->status }}</span>
                                        @endif
                                    </td>
                                    <td class="text-xs text-base-content/80">
                                        {{ $log->admin?->email ?? ($log->admin_id ? 'Admin #' . $log->admin_id : 'System') }}
                                    </td>
                                    <td class="text-xs text-base-content">{{ $log->message }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-6 text-base-content/60">
                                        No certificate audit log records recorded yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="pt-2">
                    {{ $this->auditLogs->links() }}
                </div>
            </div>
        </div>
    @endif

    {{-- ════════════════════════════════════════════════════════════════════ --}}
    {{-- MODAL: SERVICE ASSIGNMENT --}}
    {{-- ════════════════════════════════════════════════════════════════════ --}}
    @if ($this->assigningCertificate)
        <div class="modal modal-open">
            <div class="modal-box max-w-md">
                <h3 class="font-bold text-lg text-base-content">Deploy Certificate to Services</h3>
                <p class="py-2 text-xs text-base-content/70">
                    Assign <span class="font-semibold text-base-content">{{ $this->assigningCertificate->name }}</span> ({{ $this->assigningCertificate->common_name }}) to active server listeners.
                </p>

                <div class="py-4 space-y-3">
                    <label class="flex items-center gap-3 p-3 rounded-lg border border-base-200 cursor-pointer hover:bg-base-200/50">
                        <input type="checkbox" wire:model="assignWeb" class="checkbox checkbox-primary checkbox-sm" />
                        <div>
                            <div class="text-sm font-semibold">Web Portal (Nginx HTTPS :443)</div>
                            <div class="text-xs text-base-content/60">Governs web panel & Reverb WebSockets (/app)</div>
                        </div>
                    </label>

                    <label class="flex items-center gap-3 p-3 rounded-lg border border-base-200 cursor-pointer hover:bg-base-200/50">
                        <input type="checkbox" wire:model="assignTelephony" class="checkbox checkbox-primary checkbox-sm" />
                        <div>
                            <div class="text-sm font-semibold">FreeSWITCH Telephony (SIP TLS & WebRTC)</div>
                            <div class="text-xs text-base-content/60">SIP TLS (:5061) and WebRTC WSS (:7443)</div>
                        </div>
                    </label>
                </div>

                <div class="modal-action">
                    <button type="button" wire:click="closeModals" class="btn btn-ghost btn-sm">Cancel</button>
                    <button type="button" wire:click="saveServiceAssignment" class="btn btn-primary btn-sm gap-1">
                        <x-heroicon-o-check class="w-4 h-4" />
                        Apply Assignment
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ════════════════════════════════════════════════════════════════════ --}}
    {{-- MODAL: CERTIFICATE DETAILS --}}
    {{-- ════════════════════════════════════════════════════════════════════ --}}
    @if ($this->viewingCertificate)
        <div class="modal modal-open">
            <div class="modal-box max-w-lg space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-lg text-base-content">{{ $this->viewingCertificate->name }}</h3>
                    <button type="button" wire:click="closeModals" class="btn btn-ghost btn-xs btn-circle">✕</button>
                </div>

                <div class="space-y-3 text-xs">
                    <div>
                        <span class="font-semibold text-base-content/70">Common Name:</span>
                        <div class="font-mono text-base-content mt-0.5">{{ $this->viewingCertificate->common_name }}</div>
                    </div>

                    <div>
                        <span class="font-semibold text-base-content/70">Issuer:</span>
                        <div class="text-base-content mt-0.5">{{ $this->viewingCertificate->issuer }}</div>
                    </div>

                    <div>
                        <span class="font-semibold text-base-content/70">Subject Alternative Names (SANs):</span>
                        <div class="font-mono text-base-content mt-0.5">
                            {{ implode(', ', $this->viewingCertificate->san_domains ?? []) ?: 'None' }}
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <span class="font-semibold text-base-content/70">Valid From:</span>
                            <div class="text-base-content mt-0.5">{{ $this->viewingCertificate->valid_from?->format('Y-m-d H:i:s') ?? 'N/A' }}</div>
                        </div>
                        <div>
                            <span class="font-semibold text-base-content/70">Valid To:</span>
                            <div class="text-base-content mt-0.5">{{ $this->viewingCertificate->valid_to?->format('Y-m-d H:i:s') ?? 'N/A' }}</div>
                        </div>
                    </div>

                    @if ($this->viewingCertificate->fingerprint_sha256)
                        <div>
                            <span class="font-semibold text-base-content/70">SHA-256 Fingerprint:</span>
                            <div class="font-mono break-all text-base-content mt-0.5">{{ $this->viewingCertificate->fingerprint_sha256 }}</div>
                        </div>
                    @endif

                    @if ($this->viewingCertificate->serial_number)
                        <div>
                            <span class="font-semibold text-base-content/70">Serial Number:</span>
                            <div class="font-mono text-base-content mt-0.5">{{ $this->viewingCertificate->serial_number }}</div>
                        </div>
                    @endif

                    <div>
                        <span class="font-semibold text-base-content/70">Storage Identifier:</span>
                        <div class="font-mono text-base-content mt-0.5">/etc/tallpbx/certs/{{ $this->viewingCertificate->storage_identifier }}/</div>
                    </div>
                </div>

                <div class="modal-action">
                    <button type="button" wire:click="closeModals" class="btn btn-primary btn-sm">Close</button>
                </div>
            </div>
        </div>
    @endif
</div>
