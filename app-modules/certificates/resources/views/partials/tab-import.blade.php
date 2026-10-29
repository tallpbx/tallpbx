{{-- TAB 3: CUSTOM PEM IMPORT --}}
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
                    <x-form-error name="custom_name" />
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
                    <x-form-error name="custom_cert" />
                </div>

                <div class="form-control">
                    <label class="label justify-start gap-2">
                        <span class="label-text font-medium">Private Key PEM (privkey.pem)</span>
                    </label>
                    <textarea wire:model.live.debounce.500ms="custom_key"
                              rows="5"
                              placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"
                              class="textarea textarea-bordered font-mono text-xs w-full" required></textarea>
                    <x-form-error name="custom_key" />
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

