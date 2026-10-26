<?php

namespace App\Livewire\Admin;

use App\Services\AdminConsoleMetricsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The admin console's landing page.
 *
 * Three questions, in the order an operator asks them: is anything broken, is
 * anything waiting on me, and how is trade. The business analytics live in the
 * Next.js dashboard; what this adds is the health of the installation itself —
 * the kind of thing that degrades silently for months, as a Telescope table
 * growing to three million rows did.
 */
#[Layout('layouts.admin')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public ?string $statusMessage = null;

    /** The system panel is cached for ten minutes; this is the way to bypass it. */
    public function refreshSystem(AdminConsoleMetricsService $metrics): void
    {
        $metrics->forgetSystemCache();
        $this->statusMessage = 'System health refreshed.';
    }

    public function render(AdminConsoleMetricsService $metrics)
    {
        $system = $metrics->system();
        $attention = $metrics->attention();

        return view('livewire.admin.dashboard', [
            'pulse' => $metrics->pulse(),
            'trend' => $metrics->trend(),
            'attention' => $attention,
            'system' => $system,
            'warnings' => $metrics->warnings($system, $attention),
        ]);
    }
}
