{{-- MODAL: SERVICE ASSIGNMENT --}}
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

{{-- MODAL: CERTIFICATE DETAILS --}}
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

{{-- Delete Certificate Confirmation Modal --}}
<x-confirmation-modal
    :open="$pendingDeleteCertificateId !== null"
    :title="__('admin.certificates_delete_confirm_title')"
    :message="__('admin.certificates_delete_confirm_message', ['name' => $pendingDeleteCertificateName])"
    :confirm-label="__('admin.certificates_delete_action')"
    confirm-action="deleteCertificate({{ $pendingDeleteCertificateId }})"
    cancel-action="cancelDeleteCertificate"
/>

{{-- Delete DNS Credential Confirmation Modal --}}
<x-confirmation-modal
    :open="$pendingDeleteCredentialId !== null"
    :title="__('admin.certificates_delete_credential_confirm_title')"
    :message="__('admin.certificates_delete_credential_confirm_message', ['name' => $pendingDeleteCredentialName])"
    :confirm-label="__('admin.certificates_delete_action')"
    confirm-action="deleteDnsCredential({{ $pendingDeleteCredentialId }})"
    cancel-action="cancelDeleteDnsCredential"
/>

