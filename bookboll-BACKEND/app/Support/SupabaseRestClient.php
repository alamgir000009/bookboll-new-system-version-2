<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin Supabase REST client for one-off seeders / imports.
 */
class SupabaseRestClient
{
    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected bool $usingServiceRole = false,
    ) {}

    public function usingServiceRole(): bool
    {
        return $this->usingServiceRole;
    }

    public static function fromConfig(): self
    {
        $url = rtrim((string) env('SUPABASE_URL', ''), '/');
        $service = trim((string) env('SUPABASE_SERVICE_ROLE_KEY', ''));
        $anon = trim((string) env('SUPABASE_ANON_KEY', ''));
        $usingService = $service !== '';
        $key = $usingService ? $service : $anon;

        if ($url === '' || $key === '') {
            throw new RuntimeException(
                'Set SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY in .env (service role required to seed RLS-protected tables).'
            );
        }

        return new self($url, $key, $usingService);
    }

    /**
     * Fetch all rows from a table with Range pagination.
     * Tries primary order, then fallbacks when the column is missing or returns empty with RLS.
     *
     * @param  list<string>  $orderFallbacks
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $table, string $order = 'created_at.asc', int $pageSize = 500, array $orderFallbacks = []): array
    {
        $orders = array_values(array_unique(array_filter(array_merge([$order], $orderFallbacks, ['id.asc']))));

        $lastError = null;
        foreach ($orders as $ord) {
            try {
                $rows = $this->fetchAllWithOrder($table, $ord, $pageSize);
                if ($rows !== [] || $ord === end($orders)) {
                    return $rows;
                }
            } catch (RuntimeException $e) {
                $lastError = $e;
                // try next order (e.g. created_at missing)
            }
        }

        if ($lastError) {
            throw $lastError;
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchAllWithOrder(string $table, string $order, int $pageSize): array
    {
        $all = [];
        $from = 0;
        $totalHint = null;

        while (true) {
            $to = $from + $pageSize - 1;
            $response = Http::withHeaders([
                'apikey'        => $this->apiKey,
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept'        => 'application/json',
                'Prefer'        => 'count=exact',
                'Range'         => "{$from}-{$to}",
            ])
                ->timeout(60)
                ->get("{$this->baseUrl}/rest/v1/{$table}", [
                    'select' => '*',
                    'order'  => $order,
                ]);

            $contentRange = $response->header('Content-Range');
            if ($contentRange && preg_match('/\/(\d+|\*)/', $contentRange, $m)) {
                $totalHint = $m[1] === '*' ? null : (int) $m[1];
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Supabase {$table} fetch failed ({$response->status()}) order={$order}"
                    . ($contentRange ? " range={$contentRange}" : '')
                    . ': ' . $response->body()
                );
            }

            $chunk = $response->json();
            if (! is_array($chunk) || $chunk === []) {
                break;
            }

            foreach ($chunk as $row) {
                if (is_array($row)) {
                    $all[] = $row;
                }
            }

            if (count($chunk) < $pageSize) {
                break;
            }

            $from += $pageSize;
        }

        if ($totalHint !== null && $all === [] && $totalHint > 0 && ! $this->usingServiceRole) {
            throw new RuntimeException(
                "Supabase {$table}: Content-Range reports {$totalHint} rows but anon key returned 0 — set SUPABASE_SERVICE_ROLE_KEY to bypass RLS."
            );
        }

        return $all;
    }
}
