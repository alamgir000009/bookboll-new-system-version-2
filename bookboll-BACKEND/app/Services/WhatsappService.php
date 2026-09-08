<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsappOutbound;
use App\Support\AccessScope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The single server-side sender for Bookboll's WhatsApp channel — the Laravel
 * replacement for the Supabase `send-whatsapp` edge function.
 *
 * PER-PROVIDER, mirroring the email sender: a provider connects THEIR OWN WhatsApp
 * Business number + token (businesses.whatsapp_config) and markets their own
 * customers from it. Bookboll uses its own number (settings key `whatsapp_config`)
 * for marketplace notifications and its own marketing.
 *
 * The browser NEVER talks to Meta directly — the access token is server-side only.
 *
 * One deliberate difference from the edge function: it took `ownerId` from the
 * request body, so anyone who could call it could send as anyone. Here the sender is
 * resolved from the CALLER (App\Support\AccessScope), exactly like SiteSettingsService.
 * Automated booking notifications are the one exception and always ride Bookboll's
 * number — they go through platformSender(), never the caller's.
 *
 * WhatsApp rules that shape all of this:
 *  - Marketing/bulk messages MUST use Meta-approved templates (no free-form blasts).
 *  - Free-form text only inside the 24-hour window after the customer last messaged.
 */
class WhatsappService
{
    /** Settings key holding Bookboll's own number (Postgres: platform_settings). */
    public const CONFIG_KEY = 'whatsapp_config';

    /** Meta caps a single campaign run; the dashboard shows the rest as "not sent". */
    public const CAMPAIGN_CAP = 200;

    /** Flush the outbound log every N sends so a mid-run failure loses little history. */
    protected const LOG_FLUSH_EVERY = 20;

    /* ────────────────────────── Sender resolution ─────────────────────────── */

    /**
     * Whose number is this call sending from? A provider sends from their own; a
     * platform admin sends from Bookboll's. Never taken from the request.
     *
     * @return array{owner_user_id:?int, is_provider:bool, phone_number_id:string, waba_id:string, token:string, token_source:string, enabled:bool}
     */
    public function senderForCaller(?User $user): array
    {
        return $this->resolveSender($user ? AccessScope::ownerUserId($user) : null);
    }

    /** Bookboll's own number — automated notifications always use this one. */
    public function platformSender(): array
    {
        return $this->resolveSender(null);
    }

    /** @return array<string, mixed> */
    protected function resolveSender(?int $ownerId): array
    {
        if ($ownerId !== null) {
            $cfg = $this->providerConfig($ownerId);

            return [
                'owner_user_id'   => $ownerId,
                'is_provider'     => true,
                'phone_number_id' => (string) ($cfg['phone_number_id'] ?? ''),
                'waba_id'         => (string) ($cfg['waba_id'] ?? ''),
                'token'           => (string) ($cfg['access_token'] ?? ''),
                'token_source'    => ($cfg['access_token'] ?? '') ? 'dashboard' : 'none',
                'enabled'         => (bool) ($cfg['enabled'] ?? false),
            ];
        }

        $cfg = $this->platformConfig();
        // A token typed into the dashboard wins (self-serve setup); the configured
        // secret is the fallback, as it was for the edge function.
        $dashboardToken = (string) ($cfg['access_token'] ?? '');
        $secretToken    = (string) config('services.whatsapp.access_token', '');

        return [
            'owner_user_id'   => null,
            'is_provider'     => false,
            'phone_number_id' => (string) ($cfg['phone_number_id'] ?? ''),
            'waba_id'         => (string) ($cfg['waba_id'] ?? ''),
            'token'           => $dashboardToken ?: $secretToken,
            'token_source'    => $dashboardToken ? 'dashboard' : ($secretToken ? 'secret' : 'none'),
            'enabled'         => (bool) ($cfg['phone_number_id'] ?? ''),
        ];
    }

    /* ─────────────────────────── Config storage ───────────────────────────── */

    /** The shape the WhatsApp settings page edits. */
    public static function blankConfig(): array
    {
        return [
            'enabled'         => false,
            'app_id'          => '',
            'app_secret'      => '',
            'business_id'     => '',
            'phone_number_id' => '',
            'waba_id'         => '',
            'display_number'  => '',
            'access_token'    => '',
            'verify_token'    => '',
            'verified_at'     => null,
        ];
    }

    public function providerConfig(int $ownerId): array
    {
        $business = Business::where('owner_id', $ownerId)->first();
        $saved    = is_array($business?->whatsapp_config) ? $business->whatsapp_config : [];

        return array_merge(self::blankConfig(), $saved);
    }

    public function platformConfig(): array
    {
        $row   = Setting::where('key', self::CONFIG_KEY)->first();
        $saved = [];
        if ($row && $row->value) {
            $decoded = json_decode($row->value, true);
            $saved   = is_array($decoded) ? $decoded : [];
        }

        // Platform config reads as connected as soon as a number is set — its token
        // may be the server secret rather than a stored value.
        return array_merge(self::blankConfig(), $saved, [
            'enabled' => (bool) ($saved['phone_number_id'] ?? ''),
        ]);
    }

    /** Load the config the CALLER may edit. */
    public function configForCaller(?User $user): array
    {
        $ownerId = $user ? AccessScope::ownerUserId($user) : null;

        return $ownerId !== null ? $this->providerConfig($ownerId) : $this->platformConfig();
    }

    /** Save the config the CALLER may edit — destination decided here, not by the request. */
    public function saveConfigForCaller(?User $user, array $cfg): array
    {
        $ownerId = $user ? AccessScope::ownerUserId($user) : null;
        $clean   = array_intersect_key($cfg, self::blankConfig());

        if ($ownerId !== null) {
            $business = Business::where('owner_id', $ownerId)->first();
            if (! $business) {
                return ['ok' => false, 'error' => 'No business found for this account.'];
            }
            // A provider counts as connected once both required fields are present.
            $clean['enabled'] = (bool) (trim((string) ($clean['phone_number_id'] ?? ''))
                && trim((string) ($clean['access_token'] ?? '')));
            $business->update(['whatsapp_config' => array_merge($this->providerConfig($ownerId), $clean)]);

            return ['ok' => true, 'config' => $this->providerConfig($ownerId)];
        }

        $merged = array_merge($this->platformConfig(), $clean);
        Setting::updateOrCreate(['key' => self::CONFIG_KEY], ['value' => json_encode($merged)]);

        return ['ok' => true, 'config' => $this->platformConfig()];
    }

    /**
     * Connected =
     *  - provider: enabled + a phone number id + an access token, or
     *  - platform: a phone number id is set (the token may be the server secret).
     */
    public static function isConfigured(array $cfg, bool $isProvider): bool
    {
        $hasNumber = trim((string) ($cfg['phone_number_id'] ?? '')) !== '';

        return $isProvider
            ? (bool) (($cfg['enabled'] ?? false) && $hasNumber && trim((string) ($cfg['access_token'] ?? '')) !== '')
            : $hasNumber;
    }

    /* ───────────────────────────── The actions ────────────────────────────── */

    /** Is this business connected, and what does Meta say about the number? */
    public function status(array $sender): array
    {
        $out = [
            'ok'           => true,
            'deployed'     => true,   // there is no function to deploy any more
            'isProvider'   => $sender['is_provider'],
            'hasToken'     => $sender['token'] !== '',
            'tokenSource'  => $sender['token_source'],
            'configured'   => $sender['phone_number_id'] !== '',
        ];

        if ($sender['token'] && $sender['phone_number_id']) {
            try {
                $info = $this->graph($sender['token'], 'GET', $sender['phone_number_id'], [
                    'fields' => 'verified_name,display_phone_number,quality_rating',
                ]);
                $out['phone'] = [
                    'name'    => $info['verified_name'] ?? null,
                    'number'  => $info['display_phone_number'] ?? null,
                    'quality' => $info['quality_rating'] ?? null,
                ];
            } catch (\Throwable $e) {
                $out['phoneError'] = $e->getMessage();
            }
        }

        return $out;
    }

    /** Every template on the WABA, with review status and rejection reason. */
    public function listTemplates(array $sender): array
    {
        if (! $sender['waba_id']) {
            return ['ok' => false, 'reason' => 'no_waba', 'error' => 'Add your WhatsApp Business Account ID on the WhatsApp page to list templates.'];
        }

        try {
            $res = $this->graph($sender['token'], 'GET', $sender['waba_id'].'/message_templates', [
                'fields' => 'name,status,category,language,components,rejected_reason',
                'limit'  => 100,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $templates = [];
        foreach ($res['data'] ?? [] as $t) {
            $components = $t['components'] ?? [];
            $find = fn (string $type) => collect($components)->firstWhere('type', $type);
            $bodyComp = $find('BODY');
            $headComp = $find('HEADER');
            $footComp = $find('FOOTER');
            $bodyText = $bodyComp['text'] ?? '';

            $templates[] = [
                'name'           => $t['name'] ?? '',
                'status'         => $t['status'] ?? '',
                'category'       => $t['category'] ?? '',
                'language'       => $t['language'] ?? '',
                'bodyText'       => $bodyText,
                'varCount'       => self::variableCount($bodyText),
                'headerText'     => ($headComp['format'] ?? '') === 'TEXT' ? ($headComp['text'] ?? '') : '',
                'footerText'     => $footComp['text'] ?? '',
                'rejectedReason' => (! empty($t['rejected_reason']) && $t['rejected_reason'] !== 'NONE') ? $t['rejected_reason'] : '',
            ];
        }

        return ['ok' => true, 'templates' => $templates];
    }

    /**
     * Submit a new template to Meta for review — the whole point of the template
     * manager: author and submit here instead of in Meta Business Manager. The review
     * result comes back through listTemplates (PENDING → APPROVED / REJECTED).
     */
    public function createTemplate(array $sender, array $input): array
    {
        if (! $sender['waba_id']) {
            return ['ok' => false, 'error' => 'Add the WhatsApp Business Account ID on the WhatsApp page first.'];
        }

        $name     = self::slugifyTemplateName($input['name'] ?? '', 512);
        $bodyText = mb_substr(trim((string) ($input['bodyText'] ?? '')), 0, 1024);
        if ($name === '' || $bodyText === '') {
            return ['ok' => false, 'error' => 'Template name and body text are required.'];
        }

        $language   = (string) ($input['language'] ?? 'en_US');
        $category   = in_array($input['category'] ?? '', ['MARKETING', 'UTILITY'], true) ? $input['category'] : 'MARKETING';
        $headerText = mb_substr(trim((string) ($input['headerText'] ?? '')), 0, 60);
        $footerText = mb_substr(trim((string) ($input['footerText'] ?? '')), 0, 60);

        $components = [];
        if ($headerText !== '') {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $headerText];
        }

        $bodyComp = ['type' => 'BODY', 'text' => $bodyText];
        // Meta requires an example value for every {{n}} in the body.
        $varCount = self::variableCount($bodyText);
        if ($varCount > 0) {
            $examples = array_map('strval', (array) ($input['examples'] ?? []));
            $filled   = [];
            for ($i = 0; $i < $varCount; $i++) {
                $filled[] = ($examples[$i] ?? '') !== '' ? $examples[$i] : 'Sample '.($i + 1);
            }
            $bodyComp['example'] = ['body_text' => [$filled]];
        }
        $components[] = $bodyComp;

        if ($footerText !== '') {
            $components[] = ['type' => 'FOOTER', 'text' => $footerText];
        }

        try {
            $res = $this->graph($sender['token'], 'POST', $sender['waba_id'].'/message_templates', [], [
                'name' => $name, 'language' => $language, 'category' => $category, 'components' => $components,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'id' => $res['id'] ?? null, 'status' => $res['status'] ?? 'PENDING', 'name' => $name];
    }

    public function deleteTemplate(array $sender, string $name): array
    {
        if (! $sender['waba_id']) {
            return ['ok' => false, 'error' => 'WABA ID is required.'];
        }
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Template name is required.'];
        }

        try {
            $this->graph($sender['token'], 'DELETE', $sender['waba_id'].'/message_templates', ['name' => $name]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true];
    }

    /** One templated message — test sends and automated notifications. */
    public function sendTemplate(array $sender, array $input): array
    {
        $to = self::normalizeNumber($input['to'] ?? '');
        if ($to === '') {
            return ['ok' => false, 'error' => 'Recipient number is required.'];
        }

        $template  = $input['template'] ?? ['name' => 'hello_world', 'language' => 'en_US'];
        $variables = array_map('strval', (array) ($input['variables'] ?? []));

        try {
            $wamid = $this->dispatch($sender, $to, self::templatePayload($template, $variables));
        } catch (\Throwable $e) {
            $this->logOutbound($sender, [[
                'to_number' => $to, 'customer_id' => $input['customerId'] ?? null,
                'campaign_id' => $input['campaignId'] ?? null, 'kind' => 'template',
                'template_name' => $template['name'] ?? null,
                'body' => self::renderBody($input['bodyText'] ?? '', $variables) ?: '[template: '.($template['name'] ?? '').']',
                'wa_message_id' => null, 'status' => 'failed', 'error' => $e->getMessage(),
            ]]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $this->logOutbound($sender, [[
            'to_number' => $to, 'customer_id' => $input['customerId'] ?? null,
            'campaign_id' => $input['campaignId'] ?? null, 'kind' => 'template',
            'template_name' => $template['name'] ?? null,
            'body' => self::renderBody($input['bodyText'] ?? '', $variables) ?: '[template: '.($template['name'] ?? '').']',
            'wa_message_id' => $wamid, 'status' => 'sent',
        ]]);

        return ['ok' => true, 'id' => $wamid];
    }

    /** One free-form text — only valid inside the 24-hour service window. */
    public function sendText(array $sender, array $input): array
    {
        $to   = self::normalizeNumber($input['to'] ?? '');
        $text = mb_substr((string) ($input['text'] ?? $input['bodyText'] ?? ''), 0, 4096);
        if ($to === '' || trim($text) === '') {
            return ['ok' => false, 'error' => 'Recipient and text are required.'];
        }

        try {
            $wamid = $this->dispatch($sender, $to, ['type' => 'text', 'text' => ['body' => $text, 'preview_url' => true]]);
        } catch (\Throwable $e) {
            $this->logOutbound($sender, [[
                'to_number' => $to, 'customer_id' => $input['customerId'] ?? null,
                'kind' => 'text', 'body' => $text, 'wa_message_id' => null,
                'status' => 'failed', 'error' => $e->getMessage(),
            ]]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $this->logOutbound($sender, [[
            'to_number' => $to, 'customer_id' => $input['customerId'] ?? null,
            'kind' => 'text', 'body' => $text, 'wa_message_id' => $wamid, 'status' => 'sent',
        ]]);

        return ['ok' => true, 'id' => $wamid];
    }

    /**
     * Bulk templated send. Recipients past the cap are reported as `dropped` rather
     * than silently discarded, so the dashboard can tell the provider what happened.
     */
    public function sendCampaign(array $sender, array $input): array
    {
        $template = $input['template'] ?? null;
        if (empty($template['name'])) {
            return ['ok' => false, 'error' => 'Template is required for campaigns.'];
        }

        $all        = array_values((array) ($input['recipients'] ?? []));
        $recipients = array_slice($all, 0, self::CAMPAIGN_CAP);
        if (! $recipients) {
            return ['ok' => false, 'error' => 'No recipients.'];
        }
        $dropped  = count($all) - count($recipients);
        $bodyText = (string) ($input['bodyText'] ?? '');

        $results = [];
        $rows    = [];
        foreach ($recipients as $r) {
            $to        = self::normalizeNumber($r['to'] ?? '');
            $variables = array_map('strval', (array) ($r['variables'] ?? []));
            if ($to === '') {
                $results[] = ['to' => $r['to'] ?? '', 'ok' => false, 'error' => 'Invalid number'];

                continue;
            }

            $row = [
                'to_number'     => $to,
                'customer_id'   => $r['customerId'] ?? null,
                'campaign_id'   => $input['campaignId'] ?? null,
                'kind'          => 'template',
                'template_name' => $template['name'],
                'body'          => self::renderBody($bodyText, $variables) ?: '[template: '.$template['name'].']',
            ];

            try {
                $wamid     = $this->dispatch($sender, $to, self::templatePayload($template, $variables));
                $results[] = ['to' => $to, 'ok' => true, 'id' => $wamid];
                $rows[]    = $row + ['wa_message_id' => $wamid, 'status' => 'sent'];
            } catch (\Throwable $e) {
                $results[] = ['to' => $to, 'ok' => false, 'error' => $e->getMessage()];
                $rows[]    = $row + ['wa_message_id' => null, 'status' => 'failed', 'error' => $e->getMessage()];
            }

            // Flush incrementally so a mid-run failure loses at most a handful of rows
            // of already-sent history instead of the whole campaign's.
            if (count($rows) >= self::LOG_FLUSH_EVERY) {
                $this->logOutbound($sender, $rows);
                $rows = [];
            }
        }
        $this->logOutbound($sender, $rows);

        $sent = count(array_filter($results, fn ($r) => $r['ok']));

        return [
            'ok'      => true,
            'sent'    => $sent,
            'failed'  => count($results) - $sent,
            'dropped' => $dropped,
            'results' => $results,
        ];
    }

    /* ──────────────────── The automated-notification path ─────────────────── */

    /**
     * Booking / payment notifications. These always ride BOOKBOLL's number whoever
     * triggers them, so the sender is the platform one and never the caller's — a
     * provider's marketing number must not carry Bookboll's service messages.
     */
    public function send(array $payload): array
    {
        $sender = $this->platformSender();

        if (! $sender['phone_number_id'] || ! $sender['token']) {
            return ['ok' => false, 'error' => 'WhatsApp is not configured'];
        }

        return ($payload['action'] ?? 'send_text') === 'send_template'
            ? $this->sendTemplate($sender, $payload)
            : $this->sendText($sender, $payload);
    }

    public function ownerPhone($ownerId): string
    {
        if (! $ownerId) {
            return '';
        }

        return User::find($ownerId)?->phone ?: '';
    }

    /* ───────────────────────────── Outbound log ───────────────────────────── */

    /** @param array<int, array<string, mixed>> $rows */
    public function logOutbound(array $sender, array $rows): void
    {
        if (! $rows) {
            return;
        }

        try {
            $now = now();
            WhatsappOutbound::insert(array_map(fn ($r) => array_merge([
                'customer_id' => null, 'campaign_id' => null, 'template_name' => null,
                'body' => null, 'wa_message_id' => null, 'status' => 'sent', 'error' => null,
            ], $r, [
                'owner_user_id' => $sender['owner_user_id'],
                'created_at'    => $now,
                'updated_at'    => $now,
            ]), $rows));
        } catch (\Throwable $e) {
            // The log must never take a send down with it.
            Log::error('whatsapp_outbound insert failed: '.$e->getMessage());
        }
    }

    /** The recent-messages panel on the WhatsApp page, scoped to the caller's sender. */
    public function recentOutbound(array $sender, int $limit = 15): array
    {
        return WhatsappOutbound::query()
            ->when($sender['owner_user_id'] !== null,
                fn ($q) => $q->where('owner_user_id', $sender['owner_user_id']),
                fn ($q) => $q->whereNull('owner_user_id'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['to_number', 'kind', 'template_name', 'body', 'status', 'error', 'created_at'])
            ->map(fn ($r) => [
                'to_number'     => $r->to_number,
                'kind'          => $r->kind,
                'template_name' => $r->template_name,
                'body'          => $r->body,
                'status'        => $r->status,
                'error'         => $r->error,
                'created_at'    => optional($r->created_at)?->toIso8601String(),
            ])->all();
    }

    /* ────────────────────────────── Meta plumbing ─────────────────────────── */

    /** One message via the Cloud API. Returns Meta's wamid. */
    protected function dispatch(array $sender, string $to, array $payload): ?string
    {
        $body = $this->graph($sender['token'], 'POST', $sender['phone_number_id'].'/messages', [], array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
        ], $payload));

        return $body['messages'][0]['id'] ?? null;
    }

    /**
     * A Graph API call. Throws with Meta's own message on failure so the dashboard
     * can show the provider what Meta actually said.
     */
    protected function graph(string $token, string $method, string $path, array $query = [], ?array $json = null): array
    {
        // One hung connection must fail that call, not the whole request.
        $req = Http::withToken($token)->acceptJson()->timeout(15);
        $url = rtrim(config('services.whatsapp.graph_url', 'https://graph.facebook.com/v25.0'), '/').'/'.ltrim($path, '/');
        if ($query) {
            $url .= '?'.http_build_query($query);
        }

        $res = match (strtoupper($method)) {
            'POST'   => $req->post($url, $json ?? []),
            'DELETE' => $req->delete($url),
            default  => $req->get($url),
        };

        $body = $res->json() ?? [];
        if (! $res->successful()) {
            throw new \RuntimeException($body['error']['message'] ?? 'Graph API '.$res->status());
        }

        return is_array($body) ? $body : [];
    }

    protected static function templatePayload(array $template, array $variables): array
    {
        $payload = [
            'type'     => 'template',
            'template' => [
                'name'     => $template['name'] ?? '',
                'language' => ['code' => $template['language'] ?? 'en_US'],
            ],
        ];

        if ($variables) {
            $payload['template']['components'] = [[
                'type'       => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $variables),
            ]];
        }

        return $payload;
    }

    /* ─────────────────────────────── Helpers ──────────────────────────────── */

    /** Digits-only WhatsApp number (country code, no +). '' when clearly unusable. */
    public static function normalizeNumber($phone): string
    {
        $d = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);   // international dialing prefix → bare country code
        }

        return strlen($d) >= 8 ? $d : '';
    }

    /** Substitute {{1}}…{{n}} for the log / previews; missing values keep the token. */
    public static function renderBody($bodyText, array $variables = []): string
    {
        return preg_replace_callback('/\{\{(\d+)\}\}/', function ($m) use ($variables) {
            $v = $variables[(int) $m[1] - 1] ?? null;

            return ($v === null || $v === '') ? $m[0] : (string) $v;
        }, (string) $bodyText) ?? '';
    }

    /** The highest {{n}} in a body — how many example values Meta will demand. */
    public static function variableCount(string $bodyText): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $m);

        return $m[1] ? max(array_map('intval', $m[1])) : 0;
    }

    /** Meta template names: lowercase snake_case only. */
    public static function slugifyTemplateName($s, int $max = 60): string
    {
        $slug = strtolower((string) $s);
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return mb_substr($slug, 0, $max);
    }
}
