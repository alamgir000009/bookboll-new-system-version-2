<?php

namespace App\Services;

use App\Models\PaymentAccount;
use App\Support\AccessScope;
use Illuminate\Http\Request;

/**
 * A provider's connected bank account, as shown on the Listings page.
 *
 * Scoped by ownership: a provider only ever reads or writes their own account,
 * while platform admins may act for any provider they administer. The owner is
 * resolved server-side rather than trusted from the request, so a provider
 * cannot address someone else's account by passing another owner_user_id.
 */
class PaymentAccountService
{
    public function show(Request $request)
    {
        $ownerId = $this->resolveOwner($request);
        if (! $ownerId) {
            return sendSuccess('Payment account', ['account' => null]);
        }

        $account = PaymentAccount::where('owner_user_id', $ownerId)
            ->orderByDesc('is_primary')->orderBy('id')->first();

        return sendSuccess('Payment account', ['account' => $this->map($account)]);
    }

    public function store(Request $request)
    {
        $ownerId = $this->resolveOwner($request);
        if (! $ownerId) {
            return sendError('No business found for this account.', [], 404);
        }

        $data = $this->validated($request);

        $account = PaymentAccount::create(array_merge($data, [
            'owner_user_id' => $ownerId,
            'is_primary'    => true,
            'verified'      => false,
        ]));

        return sendSuccess('Payment account connected', ['account' => $this->map($account)]);
    }

    public function update(Request $request, $id)
    {
        $account = PaymentAccount::find($id);
        if (! $account || ! $this->mayTouch($request, $account)) {
            return sendError('Payment account not found.', [], 404);
        }

        $account->update($this->validated($request));

        return sendSuccess('Payment account updated', ['account' => $this->map($account->fresh())]);
    }

    public function destroy(Request $request, $id)
    {
        $account = PaymentAccount::find($id);
        if (! $account || ! $this->mayTouch($request, $account)) {
            return sendError('Payment account not found.', [], 404);
        }

        $account->delete();

        return sendSuccess('Payment account disconnected', ['ok' => true]);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'account_holder' => 'nullable|string|max:150',
            'bank_name'      => 'nullable|string|max:150',
            'iban'           => 'nullable|string|max:64',
            'swift'          => 'nullable|string|max:32',
            'currency'       => 'nullable|string|max:10',
        ]);
    }

    /**
     * Whose account this call concerns: a provider's own business, or — for a
     * platform admin — the owner they named.
     */
    protected function resolveOwner(Request $request): ?int
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $own = AccessScope::ownerUserId($user);
        if ($own !== null) {
            return $own;   // providers are pinned to their own business
        }

        $asked = $request->input('owner_user_id') ?? $request->query('owner_user_id');

        return $asked && ctype_digit((string) $asked) ? (int) $asked : null;
    }

    protected function mayTouch(Request $request, PaymentAccount $account): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        if (AccessScope::isPlatform($user)) {
            return true;
        }

        return (int) $account->owner_user_id === AccessScope::ownerUserId($user);
    }

    protected function map(?PaymentAccount $a): ?array
    {
        if (! $a) {
            return null;
        }

        return [
            'id'             => (string) $a->id,
            'uuid'           => $a->uuid,
            'owner_user_id'  => (string) $a->owner_user_id,
            'account_holder' => $a->account_holder,
            'bank_name'      => $a->bank_name,
            'iban'           => $a->iban,
            'swift'          => $a->swift,
            'currency'       => $a->currency,
            'is_primary'     => (bool) $a->is_primary,
            'verified'       => (bool) $a->verified,
            'created_at'     => optional($a->created_at)?->toIso8601String(),
        ];
    }
}
