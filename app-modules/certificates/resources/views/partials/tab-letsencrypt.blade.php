{{-- TAB 2: LET'S ENCRYPT ACME ISSUANCE --}}
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
                    <x-form-error name="le_name" />
                </div>

                <div class="form-control">
                    <label class="label justify-start gap-2">
                        <span class="label-text font-medium">Domain Name (FQDN)</span>
                        <x-tooltip tip="Public domain pointing to this server IP address" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50" />
                        </x-tooltip>
                    </label>
                    <input type="text" wire:model="le_domain" placeholder="e.g. pbx.example.com" class="input input-sm input-bordered w-full font-mono" required />
                    <x-form-error name="le_domain" />
                </div>

                <div class="form-control">
                    <label class="label justify-start gap-2">
                        <span class="label-text font-medium">Administrator Email</span>
                        <x-tooltip tip="Used by Let's Encrypt to send urgent renewal and security notices" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50" />
                        </x-tooltip>
                    </label>
                    <input type="email" wire:model="le_email" placeholder="admin@example.com" class="input input-sm input-bordered w-full" required />
                    <x-form-error name="le_email" />
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
                            <x-form-error name="le_dns_credential_id" />
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

