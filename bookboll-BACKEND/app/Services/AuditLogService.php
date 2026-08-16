<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AuditLogService
{
    public function __construct(protected AuditLog $auditLogModel) {}

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
        $records_per_page = $request->records_per_page ?? 25;
        $query            = $this->auditLogModel::with(['actor.roles'])->latest('timestamp');

        if ($request->filled('email')) {
            $email = $request->email;
            $query->where(function ($q) use ($email) {
                $q->where('actor_email', 'LIKE', "%{$email}%")
                    ->orWhereHas('actor', function ($aq) use ($email) {
                        $aq->where('email', 'LIKE', "%{$email}%")
                            ->orWhere('name', 'LIKE', "%{$email}%");
                    });
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('details', 'LIKE', "%{$search}%")
                    ->orWhere('action', 'LIKE', "%{$search}%")
                    ->orWhere('resource', 'LIKE', "%{$search}%")
                    ->orWhere('actor_email', 'LIKE', "%{$search}%")
                    ->orWhereHas('actor', function ($aq) use ($search) {
                        $aq->where('email', 'LIKE', "%{$search}%")
                            ->orWhere('name', 'LIKE', "%{$search}%");
                    });
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

        $filterOptions = [
            'actions'   => $this->auditLogModel::query()->distinct()->orderBy('action')->pluck('action')->filter()->values(),
            'resources' => $this->auditLogModel::query()->distinct()->orderBy('resource')->pluck('resource')->filter()->values(),
        ];

        return sendSuccess('Audit logs retrieved successfully', compact('logs', 'stats', 'filterOptions'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'action'      => 'required|string|max:100',
            'resource'    => 'nullable|string|max:100',
            'resource_id' => 'nullable|string|max:100',
            'details'     => 'nullable|string|max:2000',
        ]);

        $user = $request->user();
        $log  = $this->logActivity(
            $data['action'],
            $data['resource'] ?? 'general',
            (string) ($data['resource_id'] ?? ''),
            (string) ($data['details'] ?? ''),
            $user
        );

        return sendSuccess('Audit log recorded', ['log' => $log], 201);
    }

    public function exportCsv(Request $request)
    {
        $logs = AuditLog::latest()
            ->when($request->filled('date_from'), function ($query) use ($request) {
                $query->whereDate('timestamp', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($query) use ($request) {
                $query->whereDate('timestamp', '<=', $request->date_to);
            })
            ->when($request->filled('action'), function ($query) use ($request) {
                $query->where('action', 'like', '%' . $request->action . '%');
            })
            ->get();

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
