<div class="max-w-3xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ $fileStoreId === null ? 'Add file store' : 'Edit file store' }}</h1>
            <p class="text-sm text-base-content/60">Credentials remain write-only after they are saved.</p>
        </div>

        <a href="{{ route('panel.file-stores.index') }}" class="btn btn-ghost" wire:navigate>Cancel</a>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="name">
                    <span class="label-text font-medium">Name</span>
                </label>
                <input id="name" type="text" class="input input-bordered w-full" wire:model="name" autocomplete="off" required />
                @error('name')<span class="mt-1 text-sm text-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="provider">
                    <span class="label-text font-medium">Provider</span>
                </label>
                <select id="provider" class="select select-bordered w-full" wire:model.live="provider">
                    @foreach ($this->providers() as $providerOption)
                        <option value="{{ $providerOption }}">{{ str($providerOption)->headline() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2" x-data="{ provider: $wire.provider }">
            <div class="form-control w-full md:col-span-2" x-show="provider === 'local' || provider === 'ftp' || provider === 'sftp' || provider === 'ssh'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_root">
                    <span class="label-text font-medium">Path</span>
                </label>
                <input id="settings_root" type="text" class="input input-bordered w-full" wire:model="settings.root" autocomplete="off" />
            </div>

            <div class="form-control w-full" x-show="provider === 's3'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_bucket">
                    <span class="label-text font-medium">Bucket</span>
                </label>
                <input id="settings_bucket" type="text" class="input input-bordered w-full" wire:model="settings.bucket" autocomplete="off" />
            </div>
            <div class="form-control w-full" x-show="provider === 's3'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_region">
                    <span class="label-text font-medium">Region</span>
                </label>
                <input id="settings_region" type="text" class="input input-bordered w-full" wire:model="settings.region" autocomplete="off" />
            </div>
            <div class="form-control w-full" x-show="provider === 's3'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_access_key">
                    <span class="label-text font-medium">Access Key</span>
                </label>
                <input id="settings_access_key" type="password" class="input input-bordered w-full" wire:model="settings.access_key" autocomplete="new-password" />
            </div>
            <div class="form-control w-full" x-show="provider === 's3'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_secret">
                    <span class="label-text font-medium">Secret Key</span>
                </label>
                <input id="settings_secret" type="password" class="input input-bordered w-full" wire:model="settings.secret" autocomplete="new-password" />
            </div>
            <div class="form-control w-full md:col-span-2" x-show="provider === 's3'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_endpoint">
                    <span class="label-text font-medium">S3-Compatible Endpoint</span>
                </label>
                <input id="settings_endpoint" type="url" class="input input-bordered w-full" wire:model="settings.endpoint" autocomplete="off" />
            </div>

            <div class="form-control w-full" x-show="provider === 'ftp' || provider === 'sftp' || provider === 'ssh'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_host">
                    <span class="label-text font-medium">Host</span>
                </label>
                <input id="settings_host" type="text" class="input input-bordered w-full" wire:model="settings.host" autocomplete="off" />
            </div>
            <div class="form-control w-full" x-show="provider === 'ftp' || provider === 'sftp' || provider === 'ssh'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_username">
                    <span class="label-text font-medium">Username</span>
                </label>
                <input id="settings_username" type="text" class="input input-bordered w-full" wire:model="settings.username" autocomplete="username" />
            </div>
            <div class="form-control w-full" x-show="provider === 'ftp' || provider === 'sftp' || provider === 'ssh'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_password">
                    <span class="label-text font-medium">Password</span>
                </label>
                <input id="settings_password" type="password" class="input input-bordered w-full" wire:model="settings.password" autocomplete="new-password" />
            </div>
            <div class="form-control w-full" x-show="provider === 'ftp' || provider === 'sftp' || provider === 'ssh'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_port">
                    <span class="label-text font-medium">Port</span>
                </label>
                <input id="settings_port" type="number" class="input input-bordered w-full" wire:model="settings.port" min="1" max="65535" />
            </div>
            <div class="form-control w-full md:col-span-2" x-show="provider === 'sftp' || provider === 'ssh'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_private_key">
                    <span class="label-text font-medium">Private Key</span>
                </label>
                <textarea id="settings_private_key" class="textarea textarea-bordered min-h-28 w-full" wire:model="settings.private_key"></textarea>
            </div>
            <div class="form-control w-full" x-show="provider === 'sftp' || provider === 'ssh'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_passphrase">
                    <span class="label-text font-medium">Private Key Passphrase</span>
                </label>
                <input id="settings_passphrase" type="password" class="input input-bordered w-full" wire:model="settings.passphrase" autocomplete="new-password" />
            </div>
            <div class="form-control w-full" x-show="provider === 'sftp' || provider === 'ssh'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_host_fingerprint">
                    <span class="label-text font-medium">Host Fingerprint</span>
                </label>
                <input id="settings_host_fingerprint" type="text" class="input input-bordered w-full" wire:model="settings.host_fingerprint" autocomplete="off" />
            </div>

            <div class="form-control w-full md:col-span-2" x-show="provider === 'dropbox'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_access_token">
                    <span class="label-text font-medium">Access Token</span>
                </label>
                <input id="settings_access_token" type="password" class="input input-bordered w-full" wire:model="settings.access_token" autocomplete="new-password" />
            </div>

            <div class="form-control w-full" x-show="provider === 'email'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_recipient">
                    <span class="label-text font-medium">Recipient</span>
                </label>
                <input id="settings_recipient" type="email" class="input input-bordered w-full" wire:model="settings.recipient" autocomplete="email" />
            </div>
            <div class="form-control w-full" x-show="provider === 'email'" x-cloak>
                <label class="label justify-start gap-2 pb-1" for="settings_sender">
                    <span class="label-text font-medium">Sender Override</span>
                </label>
                <input id="settings_sender" type="email" class="input input-bordered w-full" wire:model="settings.sender" autocomplete="email" />
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('panel.file-stores.index') }}" class="btn btn-ghost" wire:navigate>Cancel</a>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Save file store</button>
        </div>
    </form>
</div>
