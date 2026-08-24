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
    ) {}

    public static function fromConfig(): self
    {
        $url = rtrim((string) env('SUPABASE_URL', ''), '/');
        $key = (string) (env('SUPABASE_SERVICE_ROLE_KEY') ?: env('SUPABASE_ANON_KEY') ?: '');

        if ($url === '' || $key === '') {
            throw new RuntimeException(
                'Set SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY (or SUPABASE_ANON_KEY) in .env'
            );
        }

        return new self($url, $key);
    }

    /**
     * Fetch all rows from a table with Range pagination.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $table, string $order = 'created_at.asc', int $pageSize = 500): array
    {
        $all = [];
        $from = 0;

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

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Supabase {$table} fetch failed ({$response->status()}): " . $response->body()
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

        return $all;
    }
}
