{{-- Channels & Target Selection --}}
@if ($currentBranch !== '')
    <div class="card bg-base-100 border border-base-300 p-5 space-y-5">
        <div>
            <h3 class="text-base font-semibold">{{ __('admin.git_channel') }}</h3>
            <p class="text-xs text-base-content/60 mt-0.5">Select a release channel to update or switch branches.</p>
        </div>

        {{-- Channel Selector Cards --}}
        <div class="grid grid-cols-1 {{ ! empty($developmentBranches) ? 'md:grid-cols-3' : 'md:grid-cols-2' }} gap-3">
            {{-- Stable Channel --}}
            <div wire:click="selectChannel('stable')"
                 class="cursor-pointer border rounded-xl p-4 transition-all {{ $selectedChannel === 'stable' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-base-300 hover:border-base-content/20' }}">
                <div class="flex items-center justify-between">
                    <span class="font-medium text-sm flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-success"></span>
                        {{ __('admin.git_stable_channel') }}
                    </span>
                    @if ($selectedChannel === 'stable')
                        <x-heroicon-s-check-circle class="w-4 h-4 text-primary" />
                    @endif
                </div>
                <p class="text-xs text-base-content/60 mt-2">{{ __('admin.git_stable_desc') }}</p>
            </div>

            {{-- Development Channel --}}
            @if (! empty($developmentBranches))
            <div wire:click="selectChannel('development')"
                 class="cursor-pointer border rounded-xl p-4 transition-all {{ $selectedChannel === 'development' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-base-300 hover:border-base-content/20' }}">
                <div class="flex items-center justify-between">
                    <span class="font-medium text-sm flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-warning"></span>
                        {{ __('admin.git_dev_channel') }}
                    </span>
                    @if ($selectedChannel === 'development')
                        <x-heroicon-s-check-circle class="w-4 h-4 text-primary" />
                    @endif
                </div>
                <p class="text-xs text-base-content/60 mt-2">{{ __('admin.git_dev_desc') }}</p>
            </div>
            @endif

            {{-- Custom Channel --}}
            <div wire:click="selectChannel('custom')"
                 class="cursor-pointer border rounded-xl p-4 transition-all {{ $selectedChannel === 'custom' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-base-300 hover:border-base-content/20' }}">
                <div class="flex items-center justify-between">
                    <span class="font-medium text-sm flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-base-content/40"></span>
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
                                    <span class="text-base-content/40 text-xs shrink-0">{{ $commit['time'] }}</span>
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
