<?php
namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel replacement for the Supabase send-whatsapp edge function.
 * Uses platform settings key `whatsapp_config` (same shape the admin WhatsApp page stores).
 */
class WhatsappService
{
    public function send(array $payload): array
    {
        $to = preg_replace('/\D+/', '', (string) ($payload['to'] ?? ''));
        if (str_starts_with($to, '00')) {
            $to = substr($to, 2);
        }
        if (strlen($to) < 8) {
            return ['ok' => false, 'error' => 'Invalid destination number'];
        }

        $cfg = $this->config($payload['ownerId'] ?? null);
        $phoneNumberId = $cfg['phone_number_id'] ?? null;
        $token         = $cfg['access_token'] ?? config('services.whatsapp.access_token');
        if (! $phoneNumberId || ! $token) {
            return ['ok' => false, 'error' => 'WhatsApp is not configured'];
        }

        $action = $payload['action'] ?? 'send_text';
        $body   = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
        ];

        if ($action === 'send_template') {
            $tpl = $payload['template'] ?? [];
            $body['type']     = 'template';
            $body['template'] = [
                'name'     => $tpl['name'] ?? 'bookboll_event_update',
                'language' => ['code' => $tpl['language'] ?? 'en_US'],
            ];
            $vars = array_values(array_filter($payload['variables'] ?? [], fn ($v) => $v !== null && $v !== ''));
            if ($vars) {
                $body['template']['components'] = [[
                    'type'       => 'body',
                    'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $vars),
                ]];
            }
        } else {
            $text = trim((string) ($payload['text'] ?? $payload['bodyText'] ?? ''));
            if ($text === '') {
                return ['ok' => false, 'error' => 'Empty message'];
            }
            $body['type'] = 'text';
            $body['text'] = ['body' => $text];
        }

        try {
            $res = Http::withToken($token)
                ->acceptJson()
                ->post("https://graph.facebook.com/v20.0/{$phoneNumberId}/messages", $body);

            if (! $res->successful()) {
                Log::warning('WhatsApp send failed', ['status' => $res->status(), 'body' => $res->json()]);

                return ['ok' => false, 'error' => $res->json('error.message') ?: 'WhatsApp send failed'];
            }

            return ['ok' => true, 'id' => $res->json('messages.0.id')];
        } catch (\Throwable $e) {
            Log::error('WhatsApp send exception: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function ownerPhone($ownerId): string
    {
        if (! $ownerId) {
            return '';
        }
        $user = User::find($ownerId);

        return $user?->phone ?: '';
    }

    protected function config($ownerId): array
    {
        $row = Setting::where('key', 'whatsapp_config')->first();
        $saved = [];
        if ($row && $row->value) {
            $decoded = json_decode($row->value, true);
            $saved   = is_array($decoded) ? $decoded : [];
        }

        if ($ownerId) {
            $user = User::find($ownerId);
            $userCfg = [];
            if ($user && isset($user->whatsapp_config) && is_array($user->whatsapp_config)) {
                $userCfg = $user->whatsapp_config;
            }
            if ($userCfg) {
                return array_merge($saved, $userCfg);
            }
        }

        return $saved;
    }
}
