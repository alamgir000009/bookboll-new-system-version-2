<?php

namespace App\Http\Controllers;

use App\Services\WalletPayoutService;
use Illuminate\Http\Request;

class WalletPayoutController extends Controller
{
    public function __construct(protected WalletPayoutService $service)
    {}

    public function dashboard(Request $request)
    {
        return $this->service->dashboard($request);
    }

    public function ownerReview(string $ownerUserId)
    {
        return $this->service->ownerReview($ownerUserId);
    }

    public function listWallets(Request $request)
    {
        return $this->service->listWallets($request);
    }

    public function createWallet(Request $request)
    {
        return $this->service->createWallet($request);
    }

    public function updateWallet(Request $request, string $id)
    {
        return $this->service->updateWallet($request, $id);
    }

    public function deleteWallet(string $id)
    {
        return $this->service->deleteWallet($id);
    }

    public function applyMethod(Request $request)
    {
        return $this->service->applyMethod($request);
    }

    public function approveKyc(Request $request, string $ownerUserId)
    {
        return $this->service->approveKyc($request, $ownerUserId);
    }

    public function rejectKyc(Request $request, string $ownerUserId)
    {
        return $this->service->rejectKyc($request, $ownerUserId);
    }

    public function approveWithdrawal(Request $request, string $id)
    {
        return $this->service->approveWithdrawal($request, $id);
    }

    public function markWithdrawalPaid(Request $request, string $id)
    {
        return $this->service->markWithdrawalPaid($request, $id);
    }

    public function rejectWithdrawal(Request $request, string $id)
    {
        return $this->service->rejectWithdrawal($request, $id);
    }

    public function cancelWithdrawal(Request $request, string $id)
    {
        return $this->service->cancelWithdrawal($request, $id);
    }

    public function createWithdrawal(Request $request)
    {
        return $this->service->createWithdrawal($request);
    }

    public function createPaidWithdrawal(Request $request)
    {
        return $this->service->createPaidWithdrawal($request);
    }

    public function applyDelta(Request $request)
    {
        return $this->service->applyWalletDelta($request);
    }

    public function createTransaction(Request $request)
    {
        return $this->service->createTransaction($request);
    }

    public function updateTransaction(Request $request, string $id)
    {
        return $this->service->updateTransaction($request, $id);
    }

    public function listTransactions(Request $request)
    {
        return $this->service->listTransactions($request);
    }

    public function listWithdrawals(Request $request)
    {
        return $this->service->listWithdrawals($request);
    }
}
