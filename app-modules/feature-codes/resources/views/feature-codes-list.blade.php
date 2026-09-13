<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.feature_codes_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.feature-codes.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_feature_code') }}
        </a>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto min-h-[16rem]">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th class="w-52">{{ __('admin.name') }}</th>
                        <th>{{ __('admin.description') }}</th>
                        <th>{{ __('admin.feature_code') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($codes as $code)
                        <tr>
                            <td class="font-medium w-52 whitespace-nowrap">{{ $code->name }}</td>
                            <td class="w-64 max-w-xs">
                                <div class="relative w-64"
                                     x-data="{
                                         hovered: false,
                                         isLong: false,
                                         openUpward: false,
                                         checkOverflow() {
                                             if (this.$refs.inputBox) {
                                                 this.isLong = this.$refs.inputBox.scrollWidth > this.$refs.inputBox.clientWidth || {{ mb_strlen($code->description ?? '') > 32 ? 'true' : 'false' }};
                                             }
                                         },
                                         onHover() {
                                             this.checkOverflow();
                                             if (this.isLong) {
                                                 const rect = this.$el.getBoundingClientRect();
                                                 this.openUpward = (window.innerHeight - rect.bottom) < 140 && rect.top > 140;
                                                 this.hovered = true;
                                             }
                                         }
                                     }"
                                     x-init="checkOverflow()"
                                     @mouseenter="onHover()"
                                     @mouseleave="hovered = false">
                                    @if ($code->description)
                                        {{-- Single line text box --}}
                                        <input type="text"
                                               x-ref="inputBox"
                                               readonly
                                               tabindex="-1"
                                               value="{{ $code->description }}"
                                               class="input input-bordered input-sm w-full text-xs text-base-content/80 bg-base-200/30 border-base-300 cursor-default focus:outline-none truncate"
                                               x-show="!hovered" />

                                        {{-- Expanded text area when hovered and content exceeds single line --}}
                                        <textarea readonly
                                                  tabindex="-1"
                                                  x-cloak
                                                  x-show="hovered && isLong"
                                                  :class="openUpward ? 'bottom-0' : 'top-0'"
                                                  class="textarea textarea-bordered textarea-sm absolute left-0 z-30 w-80 max-w-md text-xs leading-relaxed text-base-content bg-base-100 shadow-2xl border-base-300 focus:outline-none resize-none"
                                                  rows="{{ max(2, min(6, (int) ceil(mb_strlen($code->description) / 34))) }}">{{ $code->description }}</textarea>
                                    @else
                                        <span class="text-base-content/30 text-xs italic">—</span>
                                    @endif
                                </div>
                            </td>
                            <td><code class="badge badge-primary">{{ $code->code }}</code></td>
                            <td>
                                @if($code->enabled)
                                    <span class="badge badge-success">{{ __('client.active') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('client.inactive') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.feature-codes.edit', $code->id) }}"
                                       class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button wire:click="confirmCodeDeletion('{{ $code->id }}')"
                                            class="btn btn-ghost btn-xs text-error">
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_feature_codes_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($pendingDeletionId !== null)<x-confirmation-modal :open="true" :title="__('admin.modal_delete_feature_code_title')" :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])" :confirm-label="__('admin.modal_delete_feature_code_confirm')" confirm-action="deleteCode('{{ $pendingDeletionId }}')" cancel-action="cancelCodeDeletion" />@endif
</div>
