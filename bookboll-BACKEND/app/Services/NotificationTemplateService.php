<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Marketplace + per-business notification templates (Email / SMS / WhatsApp).
 * Mirrors Supabase platform_settings.notification_templates + admin_users.notification_templates.
 */
class NotificationTemplateService
{
    public const SETTING_KEY = 'notification_templates';

    public function getPlatform()
    {
        return sendSuccess('Notification templates fetched', [
            'templates' => $this->readPlatformTemplates(),
            'scope'     => 'platform',
        ]);
    }

    public function savePlatform(Request $request)
    {
        $data = $request->validate([
            'templates' => 'required|array',
        ]);

        Setting::updateOrCreate(
            ['key' => self::SETTING_KEY],
            [
                'value'       => json_encode($data['templates']),
                'type'        => 'json',
                'description' => 'Marketplace default notification templates (email/sms/whatsapp)',
                'updated_at'  => now(),
            ]
        );

        return sendSuccess('Notification templates saved', [
            'templates' => $data['templates'],
            'scope'     => 'platform',
        ]);
    }

    public function getBusiness(Request $request)
    {
        $business = $this->resolveBusiness($request->user());
        if (! $business) {
            return sendError('No business found for this account.', [], 404);
        }

        return sendSuccess('Business notification templates fetched', [
            'platform'  => $this->readPlatformTemplates(),
            'templates' => $business->notification_templates,
            'scope'     => 'business',
            'business'  => [
                'id'   => $business->id,
                'name' => $business->name,
            ],
        ]);
    }

    public function saveBusiness(Request $request)
    {
        $business = $this->resolveBusiness($request->user());
        if (! $business) {
            return sendError('No business found for this account.', [], 404);
        }

        $data = $request->validate([
            'templates' => 'required|array',
        ]);

        $business->notification_templates = $data['templates'];
        $business->save();

        return sendSuccess('Business notification templates saved', [
            'templates' => $business->notification_templates,
            'scope'     => 'business',
            'business'  => [
                'id'   => $business->id,
                'name' => $business->name,
            ],
        ]);
    }

    protected function readPlatformTemplates(): ?array
    {
        $raw = Setting::query()->where('key', self::SETTING_KEY)->value('value');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Provider owner → their business; team member → owner's business.
     */
    protected function resolveBusiness(?User $user): ?Business
    {
        if (! $user) {
            return null;
        }

        $user->loadMissing('ownedBusiness');
        if ($user->ownedBusiness) {
            return $user->ownedBusiness;
        }

        if ($user->owner_user_id) {
            return Business::query()->where('owner_id', $user->owner_user_id)->first();
        }

        return null;
    }
}
