<?php
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
uses(RefreshDatabase::class);

it('saves show_slot_capacity both ways', function () {
    Role::findOrCreate('provider_admin', 'web');
    $u = User::factory()->create(['status'=>User::ACTIVE,'uuid'=>(string)Str::uuid()]);
    $u->assignRole('provider_admin');
    $v = Venue::create(['name'=>'V','status'=>Venue::ACTIVE,'owner_user_id'=>$u->id,
        'maps_url'=>'https://maps.google.com/?q=0,0','min_advance_booking'=>0,'max_bookings_per_slot'=>1]);
    $l = Listing::create(['name'=>'C','venue_id'=>$v->id,'status'=>'active','base_price'=>100,
        'capacity'=>10,'slot_duration'=>60,'max_bookings_per_slot'=>3,'payment_options'=>[]]);

    expect($l->fresh()->show_slot_capacity)->toBeTrue();   // DB default

    Sanctum::actingAs($u);
    test()->putJson("/api/admin/listings/dashboard/{$l->id}", [
        'location_id'=>$v->id,'name'=>'C','base_price'=>100,'slot_duration'=>60,
        'capacity'=>10,'max_bookings_per_slot'=>3,'show_slot_capacity'=>false,
    ])->assertOk();
    expect($l->fresh()->show_slot_capacity)->toBeFalse();

    // And back on.
    test()->putJson("/api/admin/listings/dashboard/{$l->id}", [
        'location_id'=>$v->id,'name'=>'C','base_price'=>100,'slot_duration'=>60,
        'capacity'=>10,'max_bookings_per_slot'=>3,'show_slot_capacity'=>true,
    ])->assertOk();
    expect($l->fresh()->show_slot_capacity)->toBeTrue();

    // A save that does not mention it leaves it alone.
    $l->fresh()->update(['show_slot_capacity'=>false]);
    test()->putJson("/api/admin/listings/dashboard/{$l->id}", [
        'location_id'=>$v->id,'name'=>'C','base_price'=>100,'slot_duration'=>60,
        'capacity'=>10,'max_bookings_per_slot'=>3,
    ])->assertOk();
    expect($l->fresh()->show_slot_capacity)->toBeFalse();

    // And the editor reads it back.
    expect(test()->getJson("/api/admin/listings/dashboard/{$l->id}")
        ->json('data.listing.show_slot_capacity'))->toBeFalse();
});
