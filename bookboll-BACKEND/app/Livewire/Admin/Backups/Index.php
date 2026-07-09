<?php

namespace App\Livewire\Admin\Backups;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
#[Title('Database Backups')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: 10)]
    public int $records_per_page = 10;

    public array $selected = [];

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function updatedRecordsPerPage(): void
    {
        $this->resetPage();
    }

    public function runBackup(): void
    {
        set_time_limit(300);

        try {
            $exitCode = Artisan::call('backup:run', [
                '--only-db' => true,
                '--disable-notifications' => true,
            ]);

            $output = trim(Artisan::output());

            if ($exitCode !== 0) {
                $this->errorMessage = $output !== '' ? $output : 'Backup command failed.';
                $this->statusMessage = null;

                return;
            }

            $this->statusMessage = 'Database backup completed successfully.';
            $this->errorMessage = null;
            $this->resetPage();
        } catch (\Throwable $exception) {
            $this->errorMessage = 'Database backup failed: '.$exception->getMessage();
            $this->statusMessage = null;
        }
    }

    public function deleteBackup(string $filename): void
    {
        $path = $this->resolveBackupPath($filename);

        if ($path === null) {
            $this->errorMessage = 'Backup not found.';
            $this->statusMessage = null;

            return;
        }

        Storage::disk('backups')->delete($path);
        $this->selected = array_values(array_diff($this->selected, [$filename]));
        $this->statusMessage = 'Backup deleted successfully.';
        $this->errorMessage = null;
    }

    public function deleteSelected(): void
    {
        if ($this->selected === []) {
            $this->errorMessage = 'No backups were deleted.';
            $this->statusMessage = null;

            return;
        }

        $deleted = 0;
        $skipped = 0;

        foreach ($this->selected as $filename) {
            $path = $this->resolveBackupPath($filename);

            if ($path === null) {
                $skipped++;
                continue;
            }

            Storage::disk('backups')->delete($path);
            $deleted++;
        }

        if ($deleted === 0) {
            $this->errorMessage = 'No backups were deleted.';
            $this->statusMessage = null;

            return;
        }

        $message = "{$deleted} backup(s) deleted successfully.";

        if ($skipped > 0) {
            $message .= " {$skipped} could not be found.";
        }

        $this->selected = [];
        $this->statusMessage = $message;
        $this->errorMessage = null;
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

    protected function getBackupFiles()
    {
        $disk = Storage::disk('backups');
        $directory = config('backup.backup.name');

        return collect($disk->exists($directory) ? $disk->files($directory) : [])
            ->filter(fn (string $path) => str_ends_with(strtolower($path), '.zip'))
            ->map(fn (string $path) => [
                'filename' => basename($path),
                'path' => $path,
                'size' => $disk->size($path),
                'date' => $disk->lastModified($path),
            ])
            ->sortByDesc('date')
            ->values();
    }

    public function render()
    {
        $recordsPerPage = min(100, max(1, $this->records_per_page));
        $files = $this->getBackupFiles();
        $page = max(1, $this->getPage());
        $directory = config('backup.backup.name');

        $backups = new LengthAwarePaginator(
            $files->slice(($page - 1) * $recordsPerPage, $recordsPerPage)->values(),
            $files->count(),
            $recordsPerPage,
            $page,
            ['path' => route('backups.index')]
        );

        return view('livewire.admin.backups.index', [
            'backups' => $backups,
            'recordsPerPage' => $recordsPerPage,
            'totalSize' => $files->sum('size'),
            'directory' => $directory,
        ]);
    }
}
