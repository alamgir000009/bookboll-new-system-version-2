<?php

namespace App\Services;

use App\Models\LoyaltyPoint;
use App\Models\LoyaltyTier;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use PaymentStatus;

class LoyaltyPointService
{
    public function __construct(protected LoyaltyPoint $loyaltyPointModel) {}

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

        $totalEarned   = $this->loyaltyPointModel::where('type', 'credit')->sum('points');
        $totalRedeemed = $this->loyaltyPointModel::where('type', 'debit')->sum('points');

        $stats = [
            'active_members'  => User::active()->whereRelation('roles', 'name', 'customer')->count(),
            'redemption_rate' => $totalEarned > 0 ? round(($totalRedeemed / $totalEarned) * 100, 2) : 0,
            'points_issued'   => $totalEarned ?? 0,
            'points_redeemed' => $totalRedeemed ?? 0,
        ];

        return sendSuccess('Loyalty data fetched successfully', compact('customers', 'recentRedeemptions', 'stats'));
    }

    public function customerLoyalty(Request $request)
    {
        $user = auth()->user();

        $balance = (int) ($this->loyaltyPointModel::balance($user->id) ?? 0);
        $lifetimeEarned = (int) $user->loyaltyPoints()->where('type', 'credit')->sum('points');

        $tiers = LoyaltyTier::active()
            ->ordered()
            ->get(['id', 'name', 'slug', 'min_points', 'max_points', 'description', 'color', 'order']);

        $ledger = $user->loyaltyPoints()
            ->where('type', 'credit')
            ->orderByDesc('id')
            ->limit(40)
            ->get(['id', 'points', 'reason', 'booking_id', 'created_at']);

        return sendSuccess('Loyalty fetched successfully', [
            'balance'         => $balance,
            'lifetime_earned' => $lifetimeEarned,
            'tiers'           => $tiers,
            'ledger'          => $ledger,
        ]);
    }
}
