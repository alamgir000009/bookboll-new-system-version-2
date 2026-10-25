<?php

/**
 * The admin panel's Settings page, and the Stripe rows removed from `settings`.
 *
 * Two things are pinned here. First, that secrets cannot leave through the
 * settings API: the `encrypted` flag never encrypted anything, so a live Stripe
 * key sat in plaintext and GET /api/admin/settings handed it to every dashboard
 * role. Second, that the page can edit any key — including one the registry has
 * never heard of — and that the keys it must not edit stay untouched.
 */

use App\Livewire\Admin\Settings\Index as SettingsPage;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingService;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'customer'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

function settingsAdmin(): User
{
    $admin = User::factory()->create(['status' => User::ACTIVE]);
    $admin->assignRole('super_admin');

    return $admin->fresh();
}

/**
 * Upsert, not insert: a migration seeds the brand/platform defaults, so these
 * keys already exist in a freshly migrated database.
 */
function putSetting(string $key, ?string $value, string $type = 'string', bool $encrypted = false): Setting
{
    $setting = Setting::firstOrNew(['key' => $key]);

    $setting->forceFill([
        'value' => $value,
        'type' => $type,
        'encrypted' => $encrypted,
    ])->save();

    return $setting->refresh();
}

/* ---------------------------------------------------------------------------
 | Secrets must not leave through the API
 ---------------------------------------------------------------------------*/

it('withholds encrypted-flagged settings from the settings API', function () {
    putSetting('brand_name', 'Bookboll');
    putSetting('some_secret', 'sk_live_do_not_leak', 'string', encrypted: true);

    $payload = app(SettingService::class)->all()->getData(true);

    expect($payload['data']['settings'])->toHaveKey('brand_name')
        ->and($payload['data']['settings'])->not->toHaveKey('some_secret');
});

it('leaves no stripe credential rows behind', function () {
    // The migration removed them; nothing should recreate them.
    expect(Setting::whereIn('key', [
        'stripe_secret_key',
        'stripe_webhook_secret',
        'stripe_publishable_key',
    ])->count())->toBe(0);
});

/* ---------------------------------------------------------------------------
 | Access
 ---------------------------------------------------------------------------*/

it('is reachable only by a super admin', function () {
    $customer = User::factory()->create(['status' => User::ACTIVE]);
    $customer->assignRole('customer');

    $this->actingAs($customer)->get(route('platform-settings.index'))->assertRedirect();
    $this->get(route('platform-settings.index'))->assertRedirect(route('login'));
    $this->actingAs(settingsAdmin())->get(route('platform-settings.index'))->assertOk();
});

it('does not collide with the settings API route name', function () {
    expect(route('platform-settings.index'))->toContain('/admin/settings-000009')
        ->and(route('settings.index'))->toContain('/api/admin/settings');
});

/* ---------------------------------------------------------------------------
 | Editing
 ---------------------------------------------------------------------------*/

it('saves a changed value and leaves the others alone', function () {
    putSetting('brand_name', 'Bookboll');
    putSetting('brand_tagline', 'Book your game');

    Livewire::actingAs(settingsAdmin())
        ->test(SettingsPage::class)
        ->set('values.brand_name', 'Bookboll Sports')
        ->call('save')
        ->assertSet('errorMessage', null);

    expect(Setting::where('key', 'brand_name')->value('value'))->toBe('Bookboll Sports')
        ->and(Setting::where('key', 'brand_tagline')->value('value'))->toBe('Book your game');
});

it('refuses to save when nothing has changed', function () {
    putSetting('brand_name', 'Bookboll');

    Livewire::actingAs(settingsAdmin())
        ->test(SettingsPage::class)
        ->call('save')
        ->assertSet('statusMessage', null)
        ->assertSee('no values have changed');
});

it('validates against the registry instead of writing a bad value', function () {
    putSetting('cancellation_hours', '48', 'integer');

    Livewire::actingAs(settingsAdmin())
        ->test(SettingsPage::class)
        ->set('values.cancellation_hours', 'not-a-number')
        ->call('save')
        ->assertHasErrors(['values.cancellation_hours']);

    expect(Setting::where('key', 'cancellation_hours')->value('value'))->toBe('48');
});

it('keeps the slot lock in step with the checkout hold', function () {
    putSetting('booking_hold_minutes', '10', 'integer');
    putSetting('default_bookings_slot_lock_time', '10', 'integer');

    Livewire::actingAs(settingsAdmin())
        ->test(SettingsPage::class)
        ->set('values.booking_hold_minutes', '25')
        ->call('save');

    expect(Setting::where('key', 'booking_hold_minutes')->value('value'))->toBe('25')
        // Written by SettingService::put(), the shared path, not by the page.
        ->and(Setting::where('key', 'default_bookings_slot_lock_time')->value('value'))->toBe('25');
});

it('never writes a read-only setting, even if the form value is tampered with', function () {
    putSetting('stripe_catalog_prices', '{"product:premium":"prod_ABC"}');

    Livewire::actingAs(settingsAdmin())
        ->test(SettingsPage::class)
        ->set('values.stripe_catalog_prices', '{"hacked":true}')
        ->call('save')
        ->assertSee('no values have changed');

    expect(Setting::where('key', 'stripe_catalog_prices')->value('value'))->toBe('{"product:premium":"prod_ABC"}');
});

it('stores booleans as 1 and 0', function () {
    putSetting('loyalty', '1', 'boolean');

    Livewire::actingAs(settingsAdmin())
        ->test(SettingsPage::class)
        ->set('values.loyalty', false)
        ->call('save');

    expect(Setting::where('key', 'loyalty')->value('value'))->toBe('0');
});

it('uppercases the currency code', function () {
    putSetting('platform_default_currency', 'AED');

    Livewire::actingAs(settingsAdmin())
        ->test(SettingsPage::class)
        ->set('values.platform_default_currency', 'qar')
        ->call('save');

    expect(Setting::where('key', 'platform_default_currency')->value('value'))->toBe('QAR');
});

it('discards unsaved edits on request', function () {
    putSetting('brand_name', 'Bookboll');

    Livewire::actingAs(settingsAdmin())
        ->test(SettingsPage::class)
        ->set('values.brand_name', 'Something else')
        ->call('discardChanges')
        ->assertSet('values.brand_name', 'Bookboll');

    expect(Setting::where('key', 'brand_name')->value('value'))->toBe('Bookboll');
});

it('records what changed in the activity log', function () {
    $admin = settingsAdmin();
    putSetting('brand_name', 'Bookboll');

    Livewire::actingAs($admin)
        ->test(SettingsPage::class)
        ->set('values.brand_name', 'Bookboll Sports')
        ->call('save');

    $entry = Activity::query()
        ->where('log_name', 'setting')
        ->where('description', 'like', 'Updated%admin panel')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($admin->id)
        ->and($entry->properties['changed'][0]['from'])->toBe('Bookboll')
        ->and($entry->properties['changed'][0]['to'])->toBe('Bookboll Sports');
});

/* ---------------------------------------------------------------------------
 | The catch-all: a key the registry has never heard of
 ---------------------------------------------------------------------------*/

it('shows and edits a key the registry does not describe', function () {
    putSetting('a_brand_new_key', 'hello', 'string');

    expect(SettingsRegistry::isKnown('a_brand_new_key'))->toBeFalse();

    Livewire::actingAs(settingsAdmin())
        ->test(SettingsPage::class)
        // Rendered in "Other" with a humanised label rather than hidden.
        ->assertSee('A brand new key')
        ->set('values.a_brand_new_key', 'goodbye')
        ->call('save');

    expect(Setting::where('key', 'a_brand_new_key')->value('value'))->toBe('goodbye');
});

it('picks the control for an undescribed key from its stored type', function () {
    expect(SettingsRegistry::describe('unknown_a', 'boolean')['input'])->toBe('boolean')
        ->and(SettingsRegistry::describe('unknown_b', 'integer')['input'])->toBe('number')
        ->and(SettingsRegistry::describe('unknown_c', 'text')['input'])->toBe('textarea')
        ->and(SettingsRegistry::describe('unknown_d', null)['input'])->toBe('text')
        ->and(SettingsRegistry::describe('unknown_e', 'boolean')['group'])->toBe('other');
});

it('filters by section and by search', function () {
    putSetting('brand_name', 'Bookboll');
    putSetting('cancellation_hours', '48', 'integer');

    $page = Livewire::actingAs(settingsAdmin())->test(SettingsPage::class);

    // Branding shows brand_name but not a booking-rule key.
    $page->set('group', 'branding')
        ->assertSee('brand_name')
        ->assertDontSee('cancellation_hours');

    $page->set('group', 'booking')
        ->assertSee('cancellation_hours')
        ->assertDontSee('brand_name');

    $page->set('group', '')->set('q', 'cancellation_hours')
        ->assertSee('cancellation_hours')
        ->assertDontSee('brand_name');

    $page->set('q', 'nothing-matches-this')->assertViewHas('totalShown', 0);
});
