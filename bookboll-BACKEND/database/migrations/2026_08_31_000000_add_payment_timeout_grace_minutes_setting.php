<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Grace period added on top of `deposit_payment_timeout_minutes` before the
     * auto-cancel command actually cancels an unpaid booking.
     *
     * The customer is shown `deposit_payment_timeout_minutes` (10) as their
     * countdown, but banking apps and 3-D Secure approvals routinely land a
     * little after it. Cancelling on the exact second the timer hits zero takes
     * money from customers whose payment was seconds away, so enforcement runs
     * a couple of minutes behind what we advertise.
     */
    public function up(): void
    {
        if (Setting::where('key', 'payment_timeout_grace_minutes')->exists()) {
            return;
        }

        Setting::create([
            'key'         => 'payment_timeout_grace_minutes',
            'value'       => 2,
            'type'        => 'integer',
            'description' => 'Extra minutes allowed past the displayed payment timeout before an unpaid booking is auto-cancelled',
        ]);
    }

    public function down(): void
    {
        Setting::where('key', 'payment_timeout_grace_minutes')->delete();
    }
};
