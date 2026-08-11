<?php

namespace App\Livewire\Admin\Activities;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

#[Layout('layouts.admin')]
#[Title('Activity Log')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $log_name = '';

    #[Url(except: '')]
    public string $event = '';

    #[Url(except: 10)]
    public int $records_per_page = 10;

    public function updated($property): void
    {
        if (in_array($property, ['q', 'log_name', 'event', 'records_per_page'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['q', 'log_name', 'event', 'records_per_page']);
        $this->records_per_page = 10;
        $this->resetPage();
    }

    public function render()
    {
        $recordsPerPage = min(100, max(1, $this->records_per_page));

        $query = Activity::latest('created_at');

        if ($this->q !== '') {
            $search = addcslashes($this->q, '%_\\');

            $query->where(function ($builder) use ($search) {
                $builder->where('description', 'like', "%{$search}%")
                    ->orWhere('log_name', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%")
                    ->orWhere('subject_type', 'like', "%{$search}%");
            });
        }

        if ($this->log_name !== '') {
            $query->where('log_name', $this->log_name);
        }

        if ($this->event !== '') {
            $query->where('event', $this->event);
        }

        $logNames = Activity::select('log_name')
            ->whereNotNull('log_name')
            ->distinct()
            ->orderBy('log_name')
            ->pluck('log_name');

        $events = Activity::select('event')
            ->whereNotNull('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event');

        $hasActiveFilters = $this->q !== ''
            || $this->log_name !== ''
            || $this->event !== ''
            || $recordsPerPage !== 10;

        return view('livewire.admin.activities.index', [
            'activities' => $query->with(['causer'])->paginate($recordsPerPage),
            'logNames' => $logNames,
            'events' => $events,
            'recordsPerPage' => $recordsPerPage,
            'hasActiveFilters' => $hasActiveFilters,
        ]);
    }
}
