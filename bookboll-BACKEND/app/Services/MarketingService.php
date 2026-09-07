<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Customer marketing consent.
 *
 * Consent is opt-in and lives on users.marketing_email / _sms / _whatsapp.
 * Unsubscribing must work from an email client with no session, so the only
 * credential is the recipient's own unsubscribe_token.
 */
class MarketingService
{
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
