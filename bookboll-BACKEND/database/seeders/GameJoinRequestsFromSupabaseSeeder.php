<?php

namespace Database\Seeders;

use App\Models\GameJoinRequest;
use App\Models\HostedGame;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull game_join_requests from Supabase into MySQL.
 *
 *   php artisan db:seed --class=GameJoinRequestsFromSupabaseSeeder
 */
class GameJoinRequestsFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->error($e->getMessage());
            $this->command?->warn('Skipping game_join_requests import.');

            return;
        }

        if (! $client->usingServiceRole()) {
            $this->command?->warn(
                'SUPABASE_SERVICE_ROLE_KEY empty — game_join_requests may return 0 under RLS.'
            );
        }

        try {
            $this->command?->info('Fetching game_join_requests…');
            $rows = $client->fetchAll('game_join_requests', 'created_at.desc', 500, ['created_at.asc', 'id.asc']);
            $knownGames = HostedGame::query()->pluck('id')->flip();
            $ok = 0;
            $skipped = 0;
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $gameId = (string) ($row['game_id'] ?? '');
                if ($id === '' || $gameId === '' || ! $knownGames->has($gameId)) {
                    $skipped++;
                    continue;
                }
                GameJoinRequest::query()->updateOrCreate(
                    ['id' => $id],
                    [
                        'game_id'             => $gameId,
                        'joiner_customer_id'  => $this->uuidOrNull($row['joiner_customer_id'] ?? null),
                        'joiner_name'         => (string) ($row['joiner_name'] ?? ''),
                        'joiner_phone'        => (string) ($row['joiner_phone'] ?? ''),
                        'joiner_whatsapp'     => (string) ($row['joiner_whatsapp'] ?? ''),
                        'joiner_message'      => (string) ($row['joiner_message'] ?? ''),
                        'status'              => (string) ($row['status'] ?? 'pending'),
                        'form_answers'        => $this->jsonVal($row['form_answers'] ?? null, []),
                        'attended'            => array_key_exists('attended', $row) ? $row['attended'] : null,
                        'refund_state'        => (string) ($row['refund_state'] ?? ''),
                        'created_at'          => $row['created_at'] ?? now(),
                    ]
                );
                $ok++;
            }
            $this->command?->info("game_join_requests upserted: {$ok} (skipped {$skipped})");
        } catch (Throwable $e) {
            Log::error('GameJoinRequestsFromSupabaseSeeder failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->error($e->getMessage());
        }
    }

    protected function uuidOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (string) $v;
    }

    protected function jsonVal(mixed $v, mixed $default)
    {
        if ($v === null) {
            return $default;
        }
        if (is_string($v)) {
            $decoded = json_decode($v, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
        }

        return $v;
    }
}
