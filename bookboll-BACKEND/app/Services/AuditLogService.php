<?php
namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AuditLogService
{
    public function __construct(protected AuditLog $auditLogModel)
    {}

    public function logActivity(string $action, string $resource, string $resourceId, string $details, ?User $user = null)
    {
        $ipAddress = request()->ip();
        $device    = $this->getDeviceFromUserAgent(request()->userAgent());

        $log = $this->auditLogModel::create([
            'timestamp'   => now(),
            'actor_id'    => $user?->id,
            'actor_email' => $user?->email,
            'action'      => $action,
            'resource'    => $resource,
            'resource_id' => $resourceId,
            'details'     => $details,
            'ip_address'  => $ipAddress,
            'device'      => $device,
        ]);

        return $log;
    }

    public function getLogs(Request $request)
    {
        $records_per_page = $request->records_per_page ?? 10;
        $query            = $this->auditLogModel::with('actor')->latest();

        if ($request->filled('email')) {
            $query->whereHas('actor', function ($query) use ($request) {
                $query->where('email', 'LIKE', "%{$request->email}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('timestamp', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('timestamp', '<=', $request->date_to);
        }

        if ($request->filled('action')) {
            $query->where('action', 'like', '%' . $request->action . '%');
        }

        if ($request->filled('resource')) {
            $query->where('resource', $request->resource);
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->actor_id);
        }

        $logs = $query->paginate($records_per_page);

        $stats = $this->getStats();

        return sendSuccess('Audit logs retrieved successfully', compact('logs', 'stats'));
    }

    public function exportCsv(Request $request)
    {
        $query = AuditLog::query();

        if ($request->filled('date_from')) {
            $query->whereDate('timestamp', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('timestamp', '<=', $request->date_to);
        }

        $logs = $query->latest()->get();

        $filename = 'audit-logs-' . now()->format('Y-m-d-His') . '.csv';
        $filePath = storage_path("app/public/exports/{$filename}");

        $directory = dirname($filePath);
        if (! file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        $file = fopen($filePath, 'w');

        fputcsv($file, ['Timestamp', 'Actor', 'Action', 'Resource', 'Resource ID', 'Details', 'IP Address', 'Device']);

        foreach ($logs as $log) {
            fputcsv($file, [
                $log->formatted_timestamp,
                $log->actor_email ?? 'System',
                $log->action,
                $log->resource,
                $log->resource_id,
                $log->details,
                $log->ip_address,
                $log->device,
            ]);
        }

        fclose($file);

        return sendSuccess('CSV exported successfully', [
            'filename'     => $filename,
            'download_url' => url("/storage/exports/{$filename}"),
        ]);
    }

    public function getStats(): array
    {
        $totalLogs    = $this->auditLogModel::count();
        $uniqueActors = $this->auditLogModel::distinct('actor_id')->count('actor_id');
        $todaysLogs   = $this->auditLogModel::whereDate('timestamp', Carbon::today())->count();
        $actionTypes  = $this->auditLogModel::distinct('action')->count('action');

        return [
            'total_logs'    => $totalLogs,
            'todays_logs'   => $todaysLogs,
            'action_types'  => $actionTypes,
            'unique_actors' => $uniqueActors,
        ];
    }

    private function getDeviceFromUserAgent(?string $userAgent)
    {
        if (! $userAgent) {
            return 'Unknown';
        }

        if (stripos($userAgent, 'Windows') !== false) {
            return 'Windows';
        } elseif (stripos($userAgent, 'Mac') !== false) {
            return 'Mac';
        } elseif (stripos($userAgent, 'iPhone') !== false) {
            return 'iPhone';
        } elseif (stripos($userAgent, 'Android') !== false) {
            return 'Android';
        } elseif (stripos($userAgent, 'iPad') !== false) {
            return 'iPad';
        }
        return 'Unknown';
    }
}
