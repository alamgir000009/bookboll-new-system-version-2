@php
    $formatBytes = function (int $bytes): string {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-gray-800">Database Backups</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    {{ number_format($backups->total()) }} backup{{ $backups->total() === 1 ? '' : 's' }}
                    @if ($backups->total() > 0)
                        &middot; {{ $formatBytes($totalSize) }} total
                        &middot; showing {{ $backups->firstItem() }}–{{ $backups->lastItem() }}
                    @endif
                </p>
            </div>
            <form method="POST" action="{{ route('backups.run') }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-sidebar px-4 py-2 text-sm font-semibold text-white hover:bg-sidebar-dark transition"
                        onclick="return confirm('Create a new database backup now?')">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                    </svg>
                    Run backup now
                </button>
            </form>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if (session('status'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 whitespace-pre-wrap">
                {{ session('error') }}
            </div>
        @endif

        <div class="content-card">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900">Storage location</p>
                    <p class="mt-1 font-mono text-xs text-gray-500">storage/app/backups/{{ $directory }}/</p>
                </div>
                <form method="GET" action="{{ route('backups.index') }}" class="flex items-end gap-3">
                    <input type="hidden" name="page" value="1">
                    <div>
                        <label for="records_per_page" class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Per page</label>
                        <select id="records_per_page" name="records_per_page" class="input-brand w-28" onchange="this.form.requestSubmit()">
                            @foreach ([10, 25, 50, 100] as $size)
                                <option value="{{ $size }}" @selected((int) $recordsPerPage === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <div class="activities-table-wrap" x-data="{
            toggleAll(event) {
                document.querySelectorAll('.backup-checkbox').forEach(cb => cb.checked = event.target.checked);
                this.updateBulkDeleteButton();
            },
            updateBulkDeleteButton() {
                const checked = document.querySelectorAll('.backup-checkbox:checked').length;
                const btn = document.getElementById('bulk-delete-btn');
                const count = document.getElementById('bulk-delete-count');
                if (btn) btn.disabled = checked === 0;
                if (count) count.textContent = checked > 0 ? checked : '';
            }
        }">
            <div class="flex flex-col gap-3 border-b border-gray-100 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">Backup archives</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Database-only zip files created by spatie/laravel-backup</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    @if ($backups->total() > 0)
                        <form id="bulk-delete-form"
                              method="POST"
                              action="{{ route('backups.bulk-destroy') }}"
                              onsubmit="return confirm('Delete the selected backups permanently?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    id="bulk-delete-btn"
                                    disabled
                                    class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition disabled:cursor-not-allowed disabled:opacity-40">
                                Delete selected
                                <span id="bulk-delete-count" class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-bold"></span>
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="activities-table">
                    <thead>
                        <tr>
                            <th class="w-12 pl-5">
                                @if ($backups->total() > 0)
                                    <input type="checkbox"
                                           id="select-all-backups"
                                           class="h-4 w-4 rounded border-gray-300 text-sidebar focus:ring-sidebar"
                                           @change="toggleAll($event)"
                                           aria-label="Select all backups on this page">
                                @endif
                            </th>
                            <th class="min-w-[240px]">Filename</th>
                            <th>Size</th>
                            <th class="min-w-[180px]">Created</th>
                            <th class="w-44 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($backups as $backup)
                            <tr>
                                <td class="pl-5">
                                    <input type="checkbox"
                                           form="bulk-delete-form"
                                           name="filenames[]"
                                           value="{{ $backup['filename'] }}"
                                           class="backup-checkbox h-4 w-4 rounded border-gray-300 text-sidebar focus:ring-sidebar"
                                           @change="updateBulkDeleteButton()"
                                           aria-label="Select {{ $backup['filename'] }}">
                                </td>
                                <td>
                                    <p class="text-sm font-medium text-gray-900 truncate" title="{{ $backup['filename'] }}">
                                        {{ $backup['filename'] }}
                                    </p>
                                </td>
                                <td>
                                    <span class="activities-log-badge">{{ $formatBytes($backup['size']) }}</span>
                                </td>
                                <td>
                                    <p class="text-sm font-medium text-gray-900">{{ \Carbon\Carbon::createFromTimestamp($backup['date'])->format('M j, Y g:i A') }}</p>
                                    <p class="text-xs text-gray-400 mt-0.5">{{ \Carbon\Carbon::createFromTimestamp($backup['date'])->diffForHumans() }}</p>
                                </td>
                                <td class="text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <a href="{{ route('backups.download', $backup['filename']) }}"
                                           class="inline-flex items-center rounded-lg border border-sidebar/30 bg-white px-3 py-1.5 text-xs font-semibold text-sidebar hover:bg-sidebar/5 transition">
                                            Download
                                        </a>
                                        <form method="POST"
                                              action="{{ route('backups.destroy', $backup['filename']) }}"
                                              onsubmit="return confirm('Delete this backup permanently?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-16 text-center">
                                    <p class="text-sm font-medium text-gray-900">No backups yet</p>
                                    <p class="mt-1 text-sm text-gray-500">Run a backup to create your first database archive.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($backups->hasPages())
                <div class="activities-pagination border-t border-gray-100 px-5 py-4">
                    {{ $backups->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
