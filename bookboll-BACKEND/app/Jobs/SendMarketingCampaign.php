<?php

namespace App\Jobs;

use App\Models\MarketingCampaign;
use App\Models\User;
use App\Services\MarketingMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Deliver one marketing campaign, in chunks.
 *
 * A campaign is queued rather than sent inline because a few hundred SMTP round-trips
 * would hold the request open for minutes and time out. Each run takes the next chunk
 * and re-queues itself, so one campaign never monopolises a worker and progress
 * survives a restart — the campaign's own counters are the bookmark.
 *
 * Recipient ids are re-validated on every run (consent can be withdrawn mid-campaign,
 * and someone who unsubscribes from message #1 must not receive #200).
 */
class SendMarketingCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @param array<int, int> $recipientIds */
    public function __construct(
        public int $campaignId,
        public array $recipientIds,
    ) {}

    public function handle(MarketingMailService $mail): void
    {
        $campaign = MarketingCampaign::find($this->campaignId);
        if (! $campaign || $campaign->delivery_status === 'sent') {
            return;
        }

        $sender = $mail->senderFor($campaign->owner_user_id);
        if (isset($sender['error'])) {
            $campaign->update(['delivery_status' => 'failed', 'completed_at' => now()]);
            Log::error('Campaign sender unavailable', ['campaign' => $campaign->id, 'error' => $sender['error']]);

            return;
        }

        $campaign->update(['delivery_status' => 'sending']);
        $brandColor = $mail->brandColor($campaign->owner_user_id);

        $chunk     = array_slice($this->recipientIds, 0, MarketingMailService::CHUNK);
        $remaining = array_slice($this->recipientIds, MarketingMailService::CHUNK);

        // Re-read consent every chunk — someone who unsubscribes partway through the
        // campaign must not receive the rest of it.
        $recipients = User::whereIn('id', $chunk)
            ->where('marketing_email', true)
            ->whereNotNull('email')
            ->get(['id', 'name', 'email', 'unsubscribe_token']);

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $customer) {
            $html = $mail->renderHtml([
                'subject'      => $campaign->subject,
                'body'         => $campaign->body,
                'sender_label' => $campaign->sender_label,
                'is_provider'  => $campaign->owner_user_id !== null,
                'brand_color'  => $brandColor,
                // Each recipient's OWN link — see MarketingMailService.
                'unsub_url'    => $mail->unsubscribeUrl($customer->unsubscribe_token, 'email'),
            ]);

            $ok = $mail->deliver($sender, $customer->email, (string) $campaign->subject, $html, [
                'customer_id' => $customer->id,
                'campaign_id' => $campaign->id,
                'kind'        => 'campaign',
            ]);
            $ok ? $sent++ : $failed++;
        }

        // Recipients dropped between queueing and now (consent withdrawn) are neither
        // sent nor failed — they were simply no longer eligible.
        $campaign->increment('sent_count', $sent);
        $campaign->increment('failed_count', $failed);

        if ($remaining) {
            self::dispatch($campaign->id, $remaining)->delay(now()->addSeconds(2));

            return;
        }

        $campaign->refresh()->update([
            'delivery_status' => $campaign->sent_count > 0 ? 'sent' : 'failed',
            'completed_at'    => now(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        MarketingCampaign::where('id', $this->campaignId)
            ->update(['delivery_status' => 'failed', 'completed_at' => now()]);
        Log::error('Marketing campaign job failed', ['campaign' => $this->campaignId, 'error' => $e->getMessage()]);
    }
}
