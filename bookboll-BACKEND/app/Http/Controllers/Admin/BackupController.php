<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $recordsPerPage = min(100, max(1, (int) $request->input('records_per_page', 10)));
        $disk = Storage::disk('backups');
        $directory = config('backup.backup.name');

        $files = collect($disk->exists($directory) ? $disk->files($directory) : [])
            ->filter(fn (string $path) => str_ends_with(strtolower($path), '.zip'))
            ->map(fn (string $path) => [
                'filename' => basename($path),
                'path' => $path,
                'size' => $disk->size($path),
                'date' => $disk->lastModified($path),
            ])
            ->sortByDesc('date')
            ->values();

        $page = max(1, (int) $request->input('page', 1));

        $backups = new LengthAwarePaginator(
            $files->slice(($page - 1) * $recordsPerPage, $recordsPerPage)->values(),
            $files->count(),
            $recordsPerPage,
            $page,
            [
                'path' => route('backups.index'),
                'query' => $request->query(),
            ]
        );

        if ($backups->isEmpty() && $backups->currentPage() > 1) {
            return redirect()->route('backups.index', array_merge(
                $request->except('page'),
                ['page' => 1]
            ));
        }

        $totalSize = $files->sum('size');

        return view('admin.backups.index', compact(
            'backups',
            'recordsPerPage',
            'totalSize',
            'directory',
        ));
    }

    public function run(): RedirectResponse
    {
        set_time_limit(300);

        try {
            $exitCode = Artisan::call('backup:run', [
                '--only-db' => true,
                '--disable-notifications' => true,
            ]);

            $output = trim(Artisan::output());

            if ($exitCode !== 0) {
                return redirect()
                    ->route('backups.index')
                    ->with('error', $output !== '' ? $output : 'Backup command failed.');
            }

            return redirect()
                ->route('backups.index')
                ->with('status', 'Database backup completed successfully.');
        } catch (\Throwable $exception) {
            return redirect()
                ->route('backups.index')
                ->with('error', 'Database backup failed: '.$exception->getMessage());
        }
    }

    public function download(string $filename): StreamedResponse|RedirectResponse
    {
        $path = $this->resolveBackupPath($filename);

        if ($path === null) {
            return redirect()->route('backups.index')->with('error', 'Backup not found.');
        }

        return Storage::disk('backups')->download($path, basename($path));
    }

    public function destroy(string $filename): RedirectResponse
    {
        $path = $this->resolveBackupPath($filename);

        if ($path === null) {
            return redirect()->route('backups.index')->with('error', 'Backup not found.');
        }

        Storage::disk('backups')->delete($path);

        return redirect()
            ->route('backups.index')
            ->with('status', 'Backup deleted successfully.');
    }

    public function destroyMany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'filenames' => ['required', 'array', 'min:1'],
            'filenames.*' => ['required', 'string', 'regex:/^[\w\-\.]+\.zip$/i'],
        ]);

        $deleted = 0;
        $skipped = 0;

        foreach ($validated['filenames'] as $filename) {
            $path = $this->resolveBackupPath($filename);

            if ($path === null) {
                $skipped++;
                continue;
            }

            Storage::disk('backups')->delete($path);
            $deleted++;
        }

        if ($deleted === 0) {
            return redirect()
                ->back()
                ->with('error', 'No backups were deleted.');
        }

        $message = "{$deleted} backup(s) deleted successfully.";

        if ($skipped > 0) {
            $message .= " {$skipped} could not be found.";
        }

        return redirect()
            ->back()
            ->with('status', $message);
    }

    protected function resolveBackupPath(string $filename): ?string
    {
        $filename = basename($filename);

        if (! preg_match('/\.zip$/i', $filename)) {
            return null;
        }

        $path = config('backup.backup.name').'/'.$filename;

        if (! Storage::disk('backups')->exists($path)) {
            return null;
        }

        return $path;
    }
}
