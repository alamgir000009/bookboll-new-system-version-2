<?php
namespace App\Http\Controllers;

use App\Models\LoyaltyPoint;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use PaymentStatus;

class LoyaltyPointController extends Controller
{
    public function extraDataForLoyalty(Request $request)
    {

        $customers = User::whereRelation('roles', 'name', 'customer')
            ->withCount(['bookings'])
            ->addSelect([
                'loyalty_balance' => function ($q) {
                    $q->selectRaw('
                COALESCE(SUM(CASE WHEN type = "credit" THEN points ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN type = "debit" THEN points ELSE 0 END), 0)
            ')
                        ->from('loyalty_points')
                        ->whereColumn('loyalty_points.user_id', 'users.id');
                },
                'total_spent'     => function ($q) {
                    $q->selectRaw('COALESCE(SUM(amount), 0)')
                        ->from('payments')
                        ->whereColumn('payments.user_id', 'users.id')
                        ->where('payments.status', PaymentStatus::PAID);
                },
            ])
            ->orderByDesc('loyalty_balance')->take(10)->get();

        $recentRedeemptions = Transaction::with(['user:id,name,email', 'giftCard:id,title,points_required'])->latest('id')
            ->whereNotNull('gift_card_id')->credit()->take(5)->get();

        $total_earned   = LoyaltyPoint::where('type', 'credit')->sum('points');
        $total_redeemed = LoyaltyPoint::where('type', 'debit')->sum('points');

        $stats = [
            "active_members"  => User::active()->whereRelation('roles', 'name', 'customer')->count(),
            "redemption_rate" => $total_earned > 0 ? round(($total_redeemed / $total_earned) * 100, 2) : 0,
            "points_issued"   => $total_earned ?? 0,
            "points_redeemed" => $total_redeemed ?? 0,
        ];

        return sendSuccess('Loyalty data fetched successfully', compact('customers', 'recentRedeemptions', 'stats'));

    }
}
