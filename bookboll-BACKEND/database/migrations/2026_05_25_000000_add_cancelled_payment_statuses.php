<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM('paid','pending','unpaid','cancelled','refunded') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_status ENUM('pending','fully_paid','deposit_paid','payment_skipped','cancelled') NULL");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::table('payments')->whereIn('status', ['cancelled', 'refunded'])->update(['status' => 'pending']);
            DB::table('bookings')->where('payment_status', 'cancelled')->update(['payment_status' => 'pending']);

            DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM('paid','pending','unpaid') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_status ENUM('pending','fully_paid','deposit_paid','payment_skipped') NULL");
        }
    }
};
