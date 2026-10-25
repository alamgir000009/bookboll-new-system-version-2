<div>
    <header class="border-b border-gray-200 bg-white">
        <div class="px-6 py-5 sm:px-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Settings</h2>
                    <p class="mt-0.5 text-sm text-gray-500">
                        {{ number_format($totalSettings) }} setting{{ $totalSettings === 1 ? '' : 's' }}
                        @if ($totalShown !== $totalSettings)
                            &middot; {{ number_format($totalShown) }} shown
                        @endif
                        @if ($changedCount > 0)
                            &middot; <span class="font-semibold text-amber-600">{{ $changedCount }} unsaved</span>
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($changedCount > 0)
                        <button type="button"
                                wire:click="discardChanges"
                                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                            Discard
                        </button>
                    @endif
                    <button type="button"
                            wire:click="save"
                            wire:loading.attr="disabled"
                            @disabled($changedCount === 0)
                            class="inline-flex items-center gap-2 rounded-lg bg-sidebar px-4 py-2 text-sm font-semibold text-white transition hover:bg-sidebar-dark disabled:cursor-not-allowed disabled:opacity-50">
                        <span wire:loading.remove wire:target="save">
                            Save{{ $changedCount > 0 ? ' '.$changedCount.' change'.($changedCount === 1 ? '' : 's') : '' }}
                        </span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </button>
                </div>
            </div>
        </div>
    </header>

    <main class="px-4 py-6 sm:px-8 sm:py-8">
        <div class="space-y-6">
            @if ($statusMessage)
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ $statusMessage }}
                </div>
            @endif

            @if ($errorMessage)
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ $errorMessage }}
                </div>
            @endif

            <div class="content-card">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label for="q" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Search</label>
                        <input type="text"
                               id="q"
                               wire:model.live.debounce.300ms="q"
                               placeholder="Key or label, e.g. cancellation..."
                               class="input-brand" />
                    </div>
                    <div>
                        <label for="group" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Section</label>
                        <select id="group" wire:model.live="group" class="input-brand">
                            <option value="">All sections</option>
                            @foreach ($groupOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @if ($hasActiveFilters)
                    <div class="mt-4 border-t border-gray-100 pt-4">
                        <button type="button"
                                wire:click="clearFilters"
                                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                            Clear filters
                        </button>
                    </div>
                @endif
            </div>

            @forelse ($groups as $groupName => $group)
                <div class="sessions-table-wrap" wire:key="group-{{ $groupName }}">
                    <div class="border-b border-gray-100 bg-white px-5 py-4">
                        <h3 class="text-sm font-semibold text-gray-900">{{ $group['label'] }}</h3>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $group['description'] }}</p>
                    </div>

                    <div class="divide-y divide-gray-100">
                        @foreach ($group['settings'] as $item)
                            @php
                                $key = $item['key'];
                                $def = $item['definition'];
                                $field = 'values.'.$key;
                                $isDirty = ($values[$key] ?? null) !== ($original[$key] ?? null);
                            @endphp

                            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start sm:justify-between"
                                 wire:key="setting-{{ $key }}">
                                <div class="min-w-0 sm:w-1/2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold text-gray-900">{{ $def['label'] }}</p>
                                        @if ($isDirty)
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-700">Unsaved</span>
                                        @endif
                                        @if ($def['readonly'])
                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold uppercase text-gray-500">Read only</span>
                                        @endif
                                    </div>

                                    <p class="mt-0.5 font-mono text-[11px] text-gray-400">{{ $key }}</p>

                                    @if ($def['help'])
                                        <p class="mt-1 text-xs text-gray-500">{{ $def['help'] }}</p>
                                    @elseif ($item['description'])
                                        <p class="mt-1 text-xs text-gray-500">{{ $item['description'] }}</p>
                                    @endif

                                    @if ($def['readonly'] && $def['readonly_reason'])
                                        <p class="mt-1 text-xs text-gray-400">{{ $def['readonly_reason'] }}</p>
                                    @endif
                                </div>

                                <div class="sm:w-1/2 sm:max-w-sm">
                                    @if ($def['readonly'])
                                        <p class="break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-xs text-gray-600">
                                            {{ $values[$key] === '' || $values[$key] === false ? '—' : $values[$key] }}
                                        </p>

                                    @elseif ($def['input'] === 'boolean')
                                        <label class="inline-flex cursor-pointer items-center gap-2.5">
                                            <input type="checkbox"
                                                   wire:model.live="values.{{ $key }}"
                                                   class="h-5 w-5 rounded border-gray-300 text-sidebar focus:ring-sidebar" />
                                            <span class="text-sm text-gray-700">
                                                {{ ($values[$key] ?? false) ? 'Enabled' : 'Disabled' }}
                                            </span>
                                        </label>

                                    @elseif ($def['input'] === 'textarea')
                                        <textarea wire:model.live.debounce.500ms="values.{{ $key }}"
                                                  rows="4"
                                                  class="input-brand"></textarea>

                                    @elseif ($def['input'] === 'color')
                                        <div class="flex items-center gap-2">
                                            <input type="color"
                                                   wire:model.live="values.{{ $key }}"
                                                   class="h-10 w-14 shrink-0 cursor-pointer rounded-lg border border-gray-300 bg-white p-1" />
                                            <input type="text"
                                                   wire:model.live.debounce.500ms="values.{{ $key }}"
                                                   placeholder="#1A6AFD"
                                                   class="input-brand font-mono" />
                                        </div>

                                    @elseif ($def['input'] === 'number')
                                        <div class="flex items-center gap-2">
                                            <input type="number"
                                                   wire:model.live.debounce.500ms="values.{{ $key }}"
                                                   class="input-brand" />
                                            @if ($def['unit'])
                                                <span class="shrink-0 text-xs text-gray-500">{{ $def['unit'] }}</span>
                                            @endif
                                        </div>

                                    @else
                                        <input type="{{ $def['input'] === 'url' ? 'url' : 'text' }}"
                                               wire:model.live.debounce.500ms="values.{{ $key }}"
                                               class="input-brand" />
                                    @endif

                                    @error($field)
                                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="sessions-table-wrap">
                    <div class="sessions-empty">
                        <span class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-sidebar/10 text-sidebar">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 011.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 01-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 01-.12-1.45l.527-.737c.25-.35.272-.806.108-1.204-.165-.397-.506-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.107-1.204l-.527-.738a1.125 1.125 0 01.12-1.45l.773-.773a1.125 1.125 0 011.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </span>
                        <p class="text-sm font-semibold text-gray-900">No settings match your filters</p>
                        <p class="mt-1 max-w-sm text-sm text-gray-500">Try a different section, or clear the search.</p>
                        <button type="button"
                                wire:click="clearFilters"
                                class="mt-4 inline-flex items-center rounded-lg bg-sidebar px-4 py-2 text-sm font-semibold text-white transition hover:bg-sidebar-dark">
                            Clear filters
                        </button>
                    </div>
                </div>
            @endforelse

            @if ($changedCount > 0)
                {{-- Sticky save bar: the groups are long, and a change made at the
                     bottom should not need a scroll back up to commit. --}}
                <div class="sticky bottom-4 z-20 flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-5 py-3 shadow-lg">
                    <p class="text-sm font-medium text-amber-900">
                        {{ $changedCount }} unsaved change{{ $changedCount === 1 ? '' : 's' }}
                    </p>
                    <div class="flex items-center gap-2">
                        <button type="button"
                                wire:click="discardChanges"
                                class="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-sm font-medium text-amber-800 transition hover:bg-amber-100">
                            Discard
                        </button>
                        <button type="button"
                                wire:click="save"
                                wire:loading.attr="disabled"
                                class="rounded-lg bg-sidebar px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-sidebar-dark disabled:opacity-60">
                            Save
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </main>
</div>
