<div class="max-w-3xl space-y-6" @if ($updating) wire:poll.1s="pollProgress" @endif>
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-semibold">{{ __('admin.git_update') }}</h2>
            <p class="text-sm text-base-content/60 mt-1">{{ __('admin.git_channel') }}</p>
        </div>
        <button wire:click="fetch"
                @disabled($currentBranch === '' || $updating)
                class="btn btn-outline btn-sm gap-2">
            <x-heroicon-o-arrow-path class="w-4 h-4" wire:loading.class="animate-spin" wire:target="fetch" />
            <span>{{ __('admin.git_fetch') }}</span>
        </button>
    </div>

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

    {{-- Channels & Target Selection --}}
    @if ($currentBranch !== '')
        <div class="card bg-base-100 border border-base-300 p-5 space-y-5">
            <div>
                <h3 class="text-base font-semibold">{{ __('admin.git_channel') }}</h3>
                <p class="text-xs text-base-content/60 mt-0.5">Select a release channel to update or switch branches.</p>
            </div>

            {{-- Channel Selector Cards --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                {{-- Stable Channel --}}
                <div wire:click="selectChannel('stable')"
                     class="cursor-pointer border rounded-xl p-4 transition-all {{ $selectedChannel === 'stable' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-base-300 hover:border-base-content/20' }}">
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-sm flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-success"></span>
                            {{ __('admin.git_stable_channel') }}
                        </span>
                        @if ($selectedChannel === 'stable')
                            <x-heroicon-s-check-circle class="w-4 h-4 text-primary" />
                        @endif
                    </div>
                    <p class="text-xs text-base-content/60 mt-2">{{ __('admin.git_stable_desc') }}</p>
                </div>

                {{-- Development Channel --}}
                <div wire:click="selectChannel('development')"
                     class="cursor-pointer border rounded-xl p-4 transition-all {{ $selectedChannel === 'development' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-base-300 hover:border-base-content/20' }}">
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-sm flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-warning"></span>
                            {{ __('admin.git_dev_channel') }}
                        </span>
                        @if ($selectedChannel === 'development')
                            <x-heroicon-s-check-circle class="w-4 h-4 text-primary" />
                        @endif
                    </div>
                    <p class="text-xs text-base-content/60 mt-2">{{ __('admin.git_dev_desc') }}</p>
                </div>

                {{-- Custom Channel --}}
                <div wire:click="selectChannel('custom')"
                     class="cursor-pointer border rounded-xl p-4 transition-all {{ $selectedChannel === 'custom' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-base-300 hover:border-base-content/20' }}">
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-sm flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-base-content/40"></span>
                            {{ __('admin.git_custom_channel') }}
                        </span>
                        @if ($selectedChannel === 'custom')
                            <x-heroicon-s-check-circle class="w-4 h-4 text-primary" />
                        @endif
                    </div>
                    <p class="text-xs text-base-content/60 mt-2">{{ __('admin.git_custom_desc') }}</p>
                </div>
            </div>

            {{-- Target Branch Selector within Channel --}}
            <div class="pt-2 border-t border-base-200">
                @if ($selectedChannel === 'stable')
                    @if (! empty($stableBranches))
                        <div class="form-control">
                            <label class="label py-1"><span class="label-text font-medium text-xs">Stable Version Branch</span></label>
                            <select wire:model.live="selectedTarget" class="select select-bordered select-sm w-full max-w-xs font-mono">
                                @foreach ($stableBranches as $branch)
                                    <option value="{{ $branch }}">{{ $branch }} {{ $branch === $currentBranch ? '(Current)' : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <div class="alert alert-info text-xs py-2">
                            <x-heroicon-o-information-circle class="w-4 h-4 shrink-0" />
                            <span>{{ __('admin.git_no_stable_branches') }}</span>
                        </div>
                    @endif
                @elseif ($selectedChannel === 'development')
                    <div class="form-control">
                        <label class="label py-1"><span class="label-text font-medium text-xs">Development Branch</span></label>
                        <select wire:model.live="selectedTarget" class="select select-bordered select-sm w-full max-w-xs font-mono">
                            @foreach ($developmentBranches as $branch)
                                <option value="{{ $branch }}">{{ $branch }} {{ $branch === $currentBranch ? '(Current)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <div class="space-y-3">
                        @if (! empty($otherBranches))
                            <div class="form-control">
                                <label class="label py-1"><span class="label-text font-medium text-xs">Custom / Feature Branches</span></label>
                                <select wire:model.live="selectedTarget" class="select select-bordered select-sm w-full max-w-xs font-mono">
                                    @foreach ($otherBranches as $branch)
                                        <option value="{{ $branch }}">{{ $branch }} {{ $branch === $currentBranch ? '(Current)' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        @if (! empty($tags))
                            <div>
                                <label class="label py-1"><span class="label-text font-medium text-xs">Tags</span></label>
                                <div class="flex flex-wrap gap-1.5 max-h-32 overflow-y-auto p-1">
                                    @foreach ($tags as $tag)
                                        <label class="flex items-center gap-1 cursor-pointer bg-base-200/50 hover:bg-base-200 px-2.5 py-1 rounded text-xs font-mono border border-base-300">
                                            <input type="radio" wire:model.live="selectedTarget" value="{{ $tag }}" class="radio radio-xs">
                                            <span>{{ $tag }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Target Status Card --}}
            @if ($selectedTarget !== '')
                <div class="rounded-lg border border-base-300 bg-base-200/30 p-4 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-base-content/60">Target:</span>
                            <span class="font-mono text-sm font-semibold">{{ $selectedTarget }}</span>
                            @if ($selectedTarget === $currentBranch)
                                <span class="badge badge-ghost badge-xs">Current Branch</span>
                            @else
                                <span class="badge badge-warning badge-xs">Branch Switch</span>
                            @endif
                        </div>
                        <div>
                            @if ($selectedTarget === $currentBranch)
                                @if ($commitsBehind > 0)
                                    <span class="badge badge-primary badge-sm">{{ __('admin.git_commits_behind', ['count' => $commitsBehind, 'target' => $selectedTarget]) }}</span>
                                @else
                                    <span class="badge badge-success badge-sm flex items-center gap-1">
                                        <x-heroicon-s-check class="w-3 h-3" /> {{ __('admin.git_up_to_date') }}
                                    </span>
                                @endif
                            @else
                                <span class="badge badge-info badge-sm">Ready to switch</span>
                            @endif
                        </div>
                    </div>

                    @if ($selectedTarget !== $currentBranch)
                        <div class="alert alert-warning text-xs py-2">
                            <x-heroicon-o-exclamation-triangle class="w-4 h-4 shrink-0" />
                            <span>{{ __('admin.git_switch_branch_notice', ['current' => $currentBranch, 'target' => $selectedTarget]) }}</span>
                        </div>
                    @endif

                    {{-- Incoming Commits Preview --}}
                    @if (! empty($incomingCommits))
                        <div class="space-y-1.5 pt-1">
                            <div class="text-xs font-medium text-base-content/70">{{ __('admin.git_incoming_commits') }}:</div>
                            <div class="space-y-1 bg-base-100 rounded-lg p-2.5 border border-base-200">
                                @foreach ($incomingCommits as $commit)
                                    <div class="flex items-start gap-2 text-xs">
                                        <code class="text-primary font-mono shrink-0">{{ $commit['hash'] }}</code>
                                        <span class="text-base-content/80 flex-1 truncate" title="{{ $commit['message'] }}">{{ $commit['message'] }}</span>
                                        <span class="text-base-content/40 text-[11px] shrink-0">{{ $commit['time'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Action Button --}}
            <div class="pt-2">
                <button wire:click="confirmUpdate"
                    @disabled(! $isClean || $updating || $selectedTarget === '')
                    class="btn btn-primary {{ ! $isClean || $updating || $selectedTarget === '' ? 'btn-disabled' : '' }}">
                    @if ($updating)
                        <span class="loading loading-spinner loading-xs"></span>
                    @endif
                    <span>
                        {{ $selectedTarget !== $currentBranch ? __('admin.git_switch_and_update') : __('admin.git_update_now') }}
                    </span>
                </button>
                @if (! $isClean)
                    <p class="text-error text-xs mt-2">{{ __('admin.git_unclean_warning') }}</p>
                @endif
            </div>
        </div>
    @endif

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

    {{-- Live Terminal Console & Update Steps --}}
    @if ($updating || ! empty($terminalOutput) || ! empty($updateSteps))
        <div class="card bg-base-100 border border-base-300 overflow-hidden shadow-sm">
            {{-- Terminal Window Header --}}
            <div class="bg-base-300/80 px-4 py-3 border-b border-base-300 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-[#ff5f56] inline-block"></span>
                        <span class="w-3 h-3 rounded-full bg-[#ffbd2e] inline-block"></span>
                        <span class="w-3 h-3 rounded-full bg-[#27c93f] inline-block"></span>
                    </div>
                    <span class="font-mono text-xs text-base-content/70 font-semibold ml-2">bash — update pipeline</span>
                </div>
                <div class="flex items-center gap-2">
                    @if ($updating)
                        <span class="badge badge-sm badge-warning gap-1.5 animate-pulse">
                            <span class="w-1.5 h-1.5 rounded-full bg-warning-content animate-ping"></span>
                            {{ $currentStep ? $currentStep.'...' : 'Updating...' }}
                        </span>
                    @elseif ($updateSuccess)
                        <span class="badge badge-sm badge-success gap-1">
                            <x-heroicon-s-check class="w-3.5 h-3.5" />
                            Completed
                        </span>
                    @elseif ($updateError)
                        <span class="badge badge-sm badge-error gap-1">
                            <x-heroicon-s-x-mark class="w-3.5 h-3.5" />
                            Failed
                        </span>
                    @endif
                </div>
            </div>

            {{-- Terminal Console Body --}}
            <div class="bg-[#0c1017] p-4 text-emerald-400 font-mono text-xs overflow-y-auto max-h-96 leading-relaxed select-text"
                 x-data="{ autoScroll() { this.$el.scrollTop = this.$el.scrollHeight } }"
                 x-init="autoScroll()"
                 x-effect="autoScroll()">
                @if ($terminalOutput !== '')
                    <pre class="font-mono whitespace-pre-wrap">{{ $terminalOutput }}</pre>
                @elseif ($updating)
                    <div class="flex items-center gap-2 text-emerald-400/70">
                        <span class="loading loading-dots loading-xs"></span>
                        <span>Initializing update environment...</span>
                    </div>
                @else
                    <span class="text-base-content/40">No terminal output recorded.</span>
                @endif
            </div>

            {{-- Step Checklist Footer --}}
            @if (! empty($updateSteps))
                <div class="p-4 bg-base-100 border-t border-base-300">
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-base-content/60 mb-2.5">{{ __('admin.git_update_steps') }}</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                        @foreach ($updateSteps as $step)
                            <div class="flex items-center gap-2 px-2.5 py-1.5 rounded-lg border text-xs {{ $step['status'] === 'ok' ? 'border-success/30 bg-success/5 text-success' : ($step['status'] === 'failed' ? 'border-error/30 bg-error/5 text-error' : 'border-base-300 bg-base-200/50 text-base-content/70') }}">
                                <span class="shrink-0 font-bold">
                                    {{ $step['status'] === 'ok' ? '✓' : ($step['status'] === 'failed' ? '✗' : '·') }}
                                </span>
                                <span class="font-medium truncate">{{ $step['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

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
