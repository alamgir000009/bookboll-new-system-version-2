<?php

namespace App\Services;

use App\Models\WhatsappInbound;
use App\Models\WhatsappOutbound;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Meta's side of the conversation — the Laravel replacement for the
 * `whatsapp-webhook` edge function's transport layer.
 *
 * Meta talks to this endpoint two ways:
 *   GET  — the one-time verification handshake performed when you save the webhook.
 *   POST — every inbound message and every delivery/read receipt.
 *
 * The endpoint is necessarily public, so the X-Hub-Signature-256 header is the only
 * thing that proves a POST really came from Meta. It is enforced whenever the app
 * secret is configured; without one we accept (so the webhook works before the secret
 * is added) — configure META_APP_SECRET in production.
 *
 * NOT ported: the menu-driven booking bot that the edge function also ran from here.
 * It is a state machine over whatsapp_sessions plus live slot availability, and it is
 * its own piece of work.
 */
class WhatsappWebhookService
{
    /** Bound the write so one oversized payload cannot flood the table. */
    protected const MAX_ROWS = 200;

    /** Receipts only ever move forward: sent < delivered < read. */
    protected const STATUS_RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3];

    public function __construct(protected WhatsappService $whatsapp)
    {}

    /**
     * The handshake. Meta echoes back hub.challenge only when our verify token
     * matches, which is how it proves we own the endpoint.
     */
    public function verify(Request $request)
    {
        $mode      = $request->query('hub_mode', $request->query('hub.mode'));
        $token     = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        $expected = $this->verifyToken();

        if ($mode === 'subscribe' && $token && $expected && hash_equals($expected, (string) $token)) {
            return response((string) ($challenge ?? ''), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /** Every inbound message and receipt Meta pushes at us. */
    public function handle(Request $request)
    {
        $raw = $request->getContent();

        if (! $this->validSignature($raw, $request->header('X-Hub-Signature-256'))) {
            return response('Forbidden', 403);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response('EVENT_RECEIVED', 200);   // malformed body — never make Meta retry
        }

        $rows = $this->extractRows($payload);

        if ($rows) {
            try {
                $now = now();
                WhatsappInbound::insert(array_map(fn ($r) => array_merge([
                    'wa_message_id' => null, 'from_number' => null, 'from_name' => null,
                    'phone_number_id' => null, 'message_type' => null, 'body' => null, 'status' => null,
                ], $r, [
                    'raw'         => json_encode($r['raw']),
                    'received_at' => $now,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]), array_slice($rows, 0, self::MAX_ROWS)));
            } catch (\Throwable $e) {
                Log::error('whatsapp_inbound insert failed: '.$e->getMessage());
            }
        }

        $this->syncReceipts($rows);

        // Meta retries anything that is not a 200, so acknowledge even when a part of
        // the processing above failed — the payload is already stored either way.
        return response('EVENT_RECEIVED', 200);
    }

    /**
     * Flatten Meta's entry → changes → value shape into rows.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractRows(array $payload): array
    {
        $rows = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value         = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                $nameByWaId = [];
                foreach ($value['contacts'] ?? [] as $contact) {
                    if (! empty($contact['wa_id'])) {
                        $nameByWaId[$contact['wa_id']] = $contact['profile']['name'] ?? '';
                    }
                }

                foreach ($value['messages'] ?? [] as $m) {
                    $from = $m['from'] ?? null;
                    $rows[] = [
                        'kind'            => 'message',
                        'wa_message_id'   => $m['id'] ?? null,
                        'from_number'     => $from,
                        'from_name'       => $from ? ($nameByWaId[$from] ?? null) : null,
                        'phone_number_id' => $phoneNumberId,
                        'message_type'    => $m['type'] ?? null,
                        'body'            => self::messageBody($m),
                        'raw'             => $change,
                    ];
                }

                foreach ($value['statuses'] ?? [] as $s) {
                    $rows[] = [
                        'kind'            => 'status',
                        'wa_message_id'   => $s['id'] ?? null,
                        'from_number'     => $s['recipient_id'] ?? null,
                        'phone_number_id' => $phoneNumberId,
                        'status'          => $s['status'] ?? null,
                        'raw'             => $change,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Move our outbound log forward with Meta's receipts. Monotonic: a late-arriving
     * "delivered" can never pull a message back from "read", because webhooks are not
     * ordered.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    protected function syncReceipts(array $rows): void
    {
        foreach ($rows as $r) {
            if (($r['kind'] ?? '') !== 'status' || empty($r['wa_message_id']) || empty($r['status'])) {
                continue;
            }

            try {
                $query = WhatsappOutbound::where('wa_message_id', $r['wa_message_id']);

                $rank = self::STATUS_RANK[$r['status']] ?? null;
                if ($rank !== null) {
                    $atOrAbove = array_keys(array_filter(self::STATUS_RANK, fn ($v) => $v >= $rank));
                    $query->whereNotIn('status', $atOrAbove);
                }

                $query->update(['status' => $r['status'], 'updated_at' => now()]);
            } catch (\Throwable $e) {
                Log::error('outbound status sync failed: '.$e->getMessage());
            }
        }
    }

    /** Best-effort plain text for the inbound log. */
    public static function messageBody(array $m): ?string
    {
        return match ($m['type'] ?? null) {
            'text'        => $m['text']['body'] ?? null,
            'button'      => $m['button']['text'] ?? null,
            'interactive' => $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? null,
            'image'       => $m['image']['caption'] ?? '[image]',
            'video'       => $m['video']['caption'] ?? '[video]',
            'audio'       => '[audio]',
            'document'    => $m['document']['filename'] ?? '[document]',
            'location'    => '[location]',
            'reaction'    => $m['reaction']['emoji'] ?? '[reaction]',
            default       => ! empty($m['type']) ? '['.$m['type'].']' : null,
        };
    }

    /** The token Meta must echo — the dashboard's value first, then the env fallback. */
    protected function verifyToken(): string
    {
        $cfg = $this->whatsapp->platformConfig();

        return (string) ($cfg['verify_token'] ?: config('services.whatsapp.verify_token', ''));
    }

    /** "sha256=" + HMAC-SHA256(rawBody, app secret), compared in constant time. */
    protected function validSignature(string $raw, ?string $header): bool
    {
        $cfg    = $this->whatsapp->platformConfig();
        $secret = (string) ($cfg['app_secret'] ?: config('services.whatsapp.app_secret', ''));

        if ($secret === '') {
            return true;   // not configured yet — see the class docblock
        }
        if (! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $header);
    }
}
