<?php

namespace Tests\Feature;

use App\Models\OnesignalSubscription;
use App\Models\User;
use App\Services\OneSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Two OneSignal apps: a device is filed under the app that registered it, and
 * a send goes out once per app with that app's credentials.
 */
class OneSignalAppsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.onesignal.apps', [
            'admin'    => ['app_id' => 'admin-app', 'rest_api_key' => 'admin-key'],
            'customer' => ['app_id' => 'customer-app', 'rest_api_key' => 'customer-key'],
        ]);
    }

    public function test_customer_mobile_app_subscription_is_filed_under_the_customer_app(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->withHeader('from', \MobileApps::CUSTOMER)
            ->postJson('/api/subscribe-onesignal', [
                'onesignal_id' => 'sub-customer-1',
                'device_type'  => 'ios',
            ])
            ->assertOk();

        $this->assertDatabaseHas('onesignal_subscriptions', [
            'user_id'      => $user->id,
            'onesignal_id' => 'sub-customer-1',
            'app'          => OnesignalSubscription::APP_CUSTOMER,
        ]);
    }

    public function test_admin_app_and_headerless_subscriptions_default_to_the_admin_app(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->withHeader('from', \MobileApps::ADMIN)
            ->postJson('/api/subscribe-onesignal', ['onesignal_id' => 'sub-admin-1'])
            ->assertOk();

        $this->postJson('/api/subscribe-onesignal', ['onesignal_id' => 'sub-legacy-1'])
            ->assertOk();

        $this->assertDatabaseHas('onesignal_subscriptions', ['onesignal_id' => 'sub-admin-1', 'app' => 'admin']);
        $this->assertDatabaseHas('onesignal_subscriptions', ['onesignal_id' => 'sub-legacy-1', 'app' => 'admin']);
    }

    public function test_the_body_cannot_choose_the_app(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->withHeader('from', \MobileApps::ADMIN)
            ->postJson('/api/subscribe-onesignal', ['onesignal_id' => 'sub-x', 'app' => 'customer'])
            ->assertOk();

        $this->assertDatabaseHas('onesignal_subscriptions', ['onesignal_id' => 'sub-x', 'app' => 'admin']);
    }

    public function test_send_is_split_per_app_with_each_apps_credentials(): void
    {
        $user = User::factory()->create();
        OnesignalSubscription::create(['user_id' => $user->id, 'onesignal_id' => 'sub-a', 'app' => 'admin']);
        OnesignalSubscription::create(['user_id' => $user->id, 'onesignal_id' => 'sub-c', 'app' => 'customer']);

        Http::fake([
            'api.onesignal.com/*' => Http::response(['id' => 'notif-1', 'recipients' => 1], 200),
        ]);

        $ok = app(OneSignalService::class)->sendToUsers([$user->id], 'Hello', 'World', ['type' => 'test']);
        $this->assertTrue($ok);

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $b = $request->data();
            return $b['app_id'] === 'admin-app'
                && $b['include_subscription_ids'] === ['sub-a']
                && $request->header('Authorization')[0] === 'Basic admin-key';
        });
        Http::assertSent(function ($request) {
            $b = $request->data();
            return $b['app_id'] === 'customer-app'
                && $b['include_subscription_ids'] === ['sub-c']
                && $request->header('Authorization')[0] === 'Basic customer-key';
        });
    }

    public function test_an_app_without_credentials_is_skipped_but_the_other_still_sends(): void
    {
        config()->set('services.onesignal.apps.customer', ['app_id' => '', 'rest_api_key' => '']);

        $user = User::factory()->create();
        OnesignalSubscription::create(['user_id' => $user->id, 'onesignal_id' => 'sub-a', 'app' => 'admin']);
        OnesignalSubscription::create(['user_id' => $user->id, 'onesignal_id' => 'sub-c', 'app' => 'customer']);

        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'notif-1'], 200)]);

        $this->assertTrue(app(OneSignalService::class)->sendToUsers([$user->id], 'Hello', 'World'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->data()['app_id'] === 'admin-app');
    }
}
