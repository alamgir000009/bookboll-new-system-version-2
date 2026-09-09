<?php

namespace App\Services;

use App\Jobs\SendMarketingCampaign;
use App\Models\MarketingCampaign;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Http\Request;

/**
 * Customer marketing consent, and the ledger of what was actually sent.
 *
 * Consent is opt-in and lives on users.marketing_email / _sms / _whatsapp.
 * Unsubscribing must work from an email client with no session, so the only
 * credential is the recipient's own unsubscribe_token.
 *
 * Every send is recorded in marketing_campaigns — who sent it, on which channel, to
 * how many people, and the exact HTML that went out. That ledger is what makes
 * "did we contact these customers, and what did we say?" answerable.
 */
class MarketingService
{
    public function __construct(protected MarketingMailService $mail)
    {}

    public const CHANNELS = ['email', 'sms', 'whatsapp'];

    protected const COLUMN = [
        'email'    => 'marketing_email',
        'sms'      => 'marketing_sms',
        'whatsapp' => 'marketing_whatsapp',
    ];

    /**
     * Turn the requested channel — or every channel — off for the holder of
     * `token`. Idempotent: unsubscribing twice succeeds both times, so a
     * re-visited link confirms rather than errors.
     */
    public function unsubscribe(Request $request)
    {
        $data = $request->validate([
            'token'   => 'required|string|max:64',
            'channel' => 'nullable|string|in:email,sms,whatsapp,all',
        ]);

        $channel = $data['channel'] ?? 'email';

        $user = User::where('unsubscribe_token', $data['token'])->first();
        if (! $user) {
            return sendError('This unsubscribe link is invalid or has expired.', [], 404);
        }

        $patch = $channel === 'all'
            ? array_fill_keys(array_values(self::COLUMN), false)
            : [self::COLUMN[$channel] => false];
        $patch['marketing_updated_at'] = now();

        $user->forceFill($patch)->save();

        return sendSuccess('Marketing preferences updated', [
            'customer' => [
                'id'        => (string) $user->id,
                'full_name' => $user->name,
                'email'     => $user->email,
            ],
            'channel'  => $channel,
            'consent'  => $this->consent($user),
        ]);
    }

    /**
     * Record a campaign. The owner is taken from the CALLER, never the request, so a
     * provider cannot file a send under Bookboll's name or another provider's.
     */
    public function recordCampaign(Request $request)
    {
        $data = $request->validate([
            'sender_label'    => 'nullable|string|max:191',
            'channel'         => 'nullable|string|in:email,sms,whatsapp',
            'audience'        => 'nullable|string|max:191',
            'subject'         => 'nullable|string|max:191',
            'body'            => 'nullable|string',
            'html'            => 'nullable|string',
            'recipient_count' => 'nullable|integer|min:0',
        ]);

        $user = $request->user();

        $campaign = MarketingCampaign::create([
            'owner_user_id'   => AccessScope::ownerUserId($user),
            'created_by'      => $user?->id,
            'sender_label'    => $data['sender_label'] ?? 'Bookboll',
            'channel'         => $data['channel'] ?? 'email',
            'audience'        => $data['audience'] ?? null,
            'subject'         => $data['subject'] ?? null,
            'body'            => $data['body'] ?? null,
            'html'            => $data['html'] ?? null,
            'recipient_count' => (int) ($data['recipient_count'] ?? 0),
        ]);

        return sendSuccess('Campaign recorded', ['campaign' => $this->map($campaign)], 201);
    }

    /**
     * Send a marketing campaign for real.
     *
     * The browser passes the customers it SELECTED; this decides who may actually be
     * mailed — narrowed to the provider's own customers and to those who opted in.
     * The count that comes back is the real one, which is often smaller than what was
     * selected, and the dashboard says so rather than pretending otherwise.
     */
    public function sendCampaign(Request $request)
    {
        $data = $request->validate([
            'subject'       => 'required|string|max:191',
            'body'          => 'required|string',
            'customer_ids'  => 'required|array|min:1',
            'customer_ids.*' => 'integer',
            'sender_label'  => 'nullable|string|max:191',
            'audience'      => 'nullable|string|max:191',
        ]);

        $user    = $request->user();
        $ownerId = $this->mail->ownerFor($user);

        // A provider must own the address the mail comes from; Bookboll uses its own.
        $sender = $this->mail->senderFor($ownerId);
        if (isset($sender['error'])) {
            return sendError($sender['error'], [], 422);
        }

        $recipients = $this->mail->eligibleRecipients($user, $data['customer_ids']);
        if ($recipients->isEmpty()) {
            return sendError(
                $ownerId === null
                    ? 'None of the selected customers have opted in to marketing email.'
                    : 'None of the selected customers are yours AND opted in to marketing email.',
                [], 422
            );
        }

        $campaign = MarketingCampaign::create([
            'owner_user_id'   => $ownerId,
            'created_by'      => $user?->id,
            'sender_label'    => $data['sender_label'] ?? $sender['from_name'],
            'channel'         => 'email',
            'audience'        => $data['audience'] ?? ('Opted-in email · '.$recipients->count().' recipient'.($recipients->count() === 1 ? '' : 's')),
            'subject'         => $data['subject'],
            'body'            => $data['body'],
            // Stored for the record as the preview a representative recipient sees;
            // the delivered copy is re-rendered per person for their own unsubscribe link.
            'html'            => $this->mail->renderHtml([
                'subject'      => $data['subject'],
                'body'         => $data['body'],
                'sender_label' => $data['sender_label'] ?? $sender['from_name'],
                'is_provider'  => $ownerId !== null,
                'brand_color'  => $this->mail->brandColor($ownerId),
                'unsub_url'    => $this->mail->unsubscribeUrl($recipients->first()->unsubscribe_token, 'email'),
            ]),
            'recipient_count' => $recipients->count(),
            'delivery_status' => 'queued',
        ]);

        SendMarketingCampaign::dispatch($campaign->id, $recipients->pluck('id')->all());

        return sendSuccess('Campaign queued', [
            'campaign'  => $this->map($campaign->fresh()),
            'queued'    => $recipients->count(),
            'requested' => count($data['customer_ids']),
        ], 201);
    }

    /** Delivery progress for the campaign the composer just sent. */
    public function campaignStatus(Request $request, $id)
    {
        $campaign = MarketingCampaign::find($id);
        if (! $campaign || ! $this->mayTouch($request->user(), $campaign)) {
            return sendError('Campaign not found.', [], 404);
        }

        return sendSuccess('Campaign', ['campaign' => $this->map($campaign)]);
    }

    /**
     * Remove a ledger row for a send that never happened — the composer calls this
     * when dispatch fails, so history does not claim messages nobody received.
     */
    public function deleteCampaign(Request $request, $id)
    {
        $campaign = MarketingCampaign::find($id);
        if (! $campaign || ! $this->mayTouch($request->user(), $campaign)) {
            return sendError('Campaign not found.', [], 404);
        }

        $campaign->delete();

        return sendSuccess('Campaign removed', ['ok' => true]);
    }

    /** The caller's own campaigns, newest first. */
    public function campaigns(Request $request)
    {
        $ownerId = AccessScope::ownerUserId($request->user());

        $rows = MarketingCampaign::query()
            ->when($ownerId !== null,
                fn ($q) => $q->where('owner_user_id', $ownerId),
                fn ($q) => $q->whereNull('owner_user_id'))
            ->orderByDesc('id')
            ->limit((int) $request->query('limit', 50))
            ->get();

        return sendSuccess('Campaigns', ['campaigns' => $rows->map(fn ($c) => $this->map($c))->values()]);
    }

    protected function mayTouch(?User $user, MarketingCampaign $campaign): bool
    {
        if (! $user) {
            return false;
        }
        $ownerId = AccessScope::ownerUserId($user);

        // A platform admin owns the platform's campaigns; a provider owns theirs.
        return $ownerId === null
            ? $campaign->owner_user_id === null
            : (int) $campaign->owner_user_id === $ownerId;
    }

    /** @return array<string, mixed> */
    protected function map(MarketingCampaign $c): array
    {
        return [
            'id'              => $c->id,
            'owner_user_id'   => $c->owner_user_id,
            'created_by'      => $c->created_by,
            'sender_label'    => $c->sender_label,
            'channel'         => $c->channel,
            'audience'        => $c->audience,
            'subject'         => $c->subject,
            'body'            => $c->body,
            'recipient_count' => (int) $c->recipient_count,
            'delivery_status' => $c->delivery_status,
            'sent_count'      => (int) $c->sent_count,
            'failed_count'    => (int) $c->failed_count,
            'completed_at'    => optional($c->completed_at)?->toIso8601String(),
            'created_at'      => optional($c->created_at)?->toIso8601String(),
        ];
    }

    /** @return array{email: bool, sms: bool, whatsapp: bool} */
    public function consent(User $user): array
    {
        return [
            'email'    => (bool) $user->marketing_email,
            'sms'      => (bool) $user->marketing_sms,
            'whatsapp' => (bool) $user->marketing_whatsapp,
        ];
    }
}
