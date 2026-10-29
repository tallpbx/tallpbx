<div class="max-w-3xl space-y-6" @if ($updating) wire:poll.1s="pollProgress" @endif>
    <x-page-header :title="__('admin.git_update')" :subtitle="__('admin.git_channel')">
        <x-slot:actions>
            <button wire:click="fetch"
                    @disabled($currentBranch === '' || $updating)
                    class="btn btn-outline btn-sm gap-2">
                <x-heroicon-o-arrow-path class="w-4 h-4" wire:loading.class="animate-spin" wire:target="fetch" />
                <span>{{ __('admin.git_fetch') }}</span>
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- Shared Session / Cache Connection Warning --}}
    @if ($this->usesSharedSessionCache)
        <x-inline-alert type="warning">
            {{ __('admin.git_session_cache_warning') }}
        </x-inline-alert>
    @endif

    {{-- Repo Inaccessible Notice --}}
    @if ($currentBranch === '')
        <div class="alert alert-warning">
            <x-heroicon-o-exclamation-triangle class="w-5 h-5 shrink-0" />
            <span>{{ __('admin.git_repo_inaccessible') }}</span>
        </div>
    @endif

    {{-- Repo Info Summary Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="card bg-base-100 border border-base-300 p-4">
            <div class="text-xs text-base-content/60">{{ __('admin.git_current_version') }}</div>
            <div class="text-base font-mono font-medium mt-1 truncate" title="{{ $currentVersion }}">
                {{ $currentVersion ?: ($currentBranch ? substr($currentBranch, 0, 10) : __('admin.git_unavailable')) }}
            </div>
        </div>
        <div class="card bg-base-100 border border-base-300 p-4">
            <div class="text-xs text-base-content/60">Current Branch</div>
            <div class="text-base font-mono font-medium mt-1 flex items-center gap-1.5">
                <span class="badge badge-sm badge-primary">{{ $currentBranch ?: __('admin.git_unavailable') }}</span>
            </div>
        </div>
        <div class="card bg-base-100 border border-base-300 p-4">
            <div class="text-xs text-base-content/60">Working Tree</div>
            <div class="text-base font-medium mt-1">
                @if ($currentBranch === '')
                    <span class="text-base-content/60">{{ __('admin.git_unavailable') }}</span>
                @elseif ($isClean)
                    <span class="text-success flex items-center gap-1">
                        <x-heroicon-o-check-circle class="w-4 h-4" /> Clean
                    </span>
                @else
                    <span class="text-error flex items-center gap-1">
                        <x-heroicon-o-x-circle class="w-4 h-4" /> Uncommitted changes
                    </span>
                @endif
            </div>
        </div>
    </div>

    {{-- Channels & Target Selection Partial --}}
    @include('admin::partials.git-channels')

    {{-- Success Message --}}
    @if ($updateSuccess)
        <div class="alert alert-success">
            <x-heroicon-o-check-circle class="w-5 h-5 shrink-0" />
            <span>{{ __('admin.git_update_success') }}</span>
        </div>
    @endif

    {{-- Error Message --}}
    @if ($updateError)
        <div class="alert alert-error">
            <x-heroicon-o-x-circle class="w-5 h-5 shrink-0" />
            <span>{{ $updateError }}</span>
        </div>
    @endif

    {{-- Live Terminal Console & Update Steps Partial --}}
    @include('admin::partials.git-terminal')

    {{-- Rollback Report --}}
    @if ($rollbackReport)
        <div class="alert alert-warning">
            <x-heroicon-o-arrow-uturn-left class="w-5 h-5 shrink-0" />
            <span>{{ $rollbackReport }}</span>
        </div>
    @endif

    @if ($confirmingUpdate)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_git_update_title')"
            :message="__('admin.modal_git_update_message')"
            :confirm-label="__('admin.modal_git_update_confirm')"
            confirm-action="updateApp()"
            cancel-action="cancelUpdate"
            :error="$updateConfirmationError"
            :error-title="__('admin.modal_git_update_error_title')"
            required-text="UPDATE"
        />
    @endif
</div>
