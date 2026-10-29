{{-- TAB 4: SELF-SIGNED CERTIFICATE GENERATOR --}}
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
                    <x-form-error name="self_name" />
                </div>

                <div class="form-control">
                    <label class="label justify-start gap-2">
                        <span class="label-text font-medium">Common Name (Primary FQDN or IP)</span>
                    </label>
                    <input type="text" wire:model="self_common_name" placeholder="e.g. 192.168.1.100 or pbx.local" class="input input-sm input-bordered w-full font-mono" required />
                    <x-form-error name="self_common_name" />
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

