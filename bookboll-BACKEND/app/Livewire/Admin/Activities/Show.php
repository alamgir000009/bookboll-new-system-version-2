<?php

namespace App\Livewire\Admin\Activities;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

#[Layout('layouts.admin')]
#[Title('Activity Detail')]
class Show extends Component
{
    public Activity $activity;

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $log_name = '';

    #[Url(except: '')]
    public string $event = '';

    #[Url(except: 10)]
    public int $records_per_page = 10;

    #[Url(except: 1)]
    public int $page = 1;

    public function mount(Activity $activity): void
    {
        $this->activity = $activity->load(['causer', 'subject']);
    }

    public function render()
    {
        return view('livewire.admin.activities.show');
    }
}
