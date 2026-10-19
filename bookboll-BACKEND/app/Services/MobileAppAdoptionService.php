<?php

namespace App\Services;

use App\Models\MobileAppInstallation;
use App\Models\User;
use App\Support\AccessScope;
use App\Support\Capabilities;
use App\Support\MobileAppAdoption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mobile-app adoption: registration, status, preference and reporting.
 *
 * The websites can only ever keep a browser self-report. This is the part that
 * can actually know something: a call from the native app, on a session the
 * server issued, for the account that session belongs to.
 *
 * The account always comes from the validated session. A client-supplied user
 * id is never read — accepting one would let any caller credit or discredit
 * somebody else's adoption.
 */
class MobileAppAdoptionService
{
    /**
     * Register authenticated native use.
     *
     * Called after login and on foreground use. Idempotent within the refresh
     * window, so an app that calls on every foreground does not rewrite its
     * own timestamp hundreds of times an hour.
     */
    public function register(Request $request, string $app)
    {
        $user = $request->user();

        if (! MobileAppAdoption::mayUse($user, $app)) {
            // The admin app is for business identities. A customer registering
            // there would land in provider adoption figures.
            return sendError('This account cannot use the '.$app.' app.', [], 403);
        }

        $data = $request->validate([
            'app'             => 'required|string|in:'.implode(',', MobileAppAdoption::APPS),
            'platform'        => 'required|string|in:'.implode(',', MobileAppAdoption::PLATFORMS),
            'installation_id' => 'required|string|uuid',
            'app_version'     => 'nullable|string|max:32',
        ]);

        if ($data['app'] !== $app) {
            // The endpoint decides the audience; the body cannot override it.
            return sendError('That app does not match this endpoint.', [
                'app' => ['Use the endpoint for the app you are registering.'],
            ], 422);
        }

        $now = now();

        $result = DB::transaction(function () use ($data, $app, $user, $now) {
            $row = MobileAppInstallation::where('app', $app)
                ->where('installation_id', $data['installation_id'])
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return [MobileAppInstallation::create([
                    'app'             => $app,
                    'installation_id' => $data['installation_id'],
                    'user_id'         => $user->id,
                    'platform'        => $data['platform'],
                    'app_version'     => $data['app_version'] ?? null,
                    'first_authenticated_use_at' => $now,
                    'last_authenticated_use_at'  => $now,
                ]), 'registered'];
            }

            // A shared device: somebody else signed in on this installation.
            // The record moves to them, and first-use restarts — the previous
            // account must not be credited with activity after they left.
            if ((int) $row->user_id !== (int) $user->id) {
                $row->update([
                    'user_id'                    => $user->id,
                    'platform'                   => $data['platform'],
                    'app_version'                => $data['app_version'] ?? $row->app_version,
                    'first_authenticated_use_at' => $now,
                    'last_authenticated_use_at'  => $now,
                    'rebound_at'                 => $now,
                ]);

                return [$row, 'rebound'];
            }

            // Inside the refresh window there is nothing new to record.
            if ($row->last_authenticated_use_at
                && $row->last_authenticated_use_at->greaterThan($now->copy()->subMinutes(MobileAppAdoption::REFRESH_MINUTES))) {
                return [$row, 'throttled'];
            }

            $row->update([
                'platform'                  => $data['platform'],
                'app_version'               => $data['app_version'] ?? $row->app_version,
                'last_authenticated_use_at' => $now,
            ]);

            return [$row, 'refreshed'];
        });

        [, $outcome] = $result;

        return sendSuccess('Mobile app session recorded', [
            'outcome' => $outcome,
            'status'  => MobileAppAdoption::status($user->fresh(), $app),
        ]);
    }

    /** This account's state for this app. */
    public function status(Request $request, string $app)
    {
        $user = $request->user();

        if (! MobileAppAdoption::mayUse($user, $app)) {
            return sendError('This account cannot use the '.$app.' app.', [], 403);
        }

        // Read from the row: the self-report may have been written by the
        // person's other device since this instance was resolved.
        return sendSuccess('Mobile app status fetched', [
            'status' => MobileAppAdoption::status($user->fresh() ?: $user, $app),
        ]);
    }

    /**
     * The "I already have the app" preference.
     *
     * Account-wide, so it survives a new device, and undoable by sending
     * false. It silences the prompt; it never becomes evidence of use.
     */
    public function setPreference(Request $request, string $app)
    {
        $user = $request->user();

        if (! MobileAppAdoption::mayUse($user, $app)) {
            return sendError('This account cannot use the '.$app.' app.', [], 403);
        }

        $data = $request->validate([
            'self_reported' => 'required|boolean',
        ]);

        // Read from the row rather than the in-memory instance: a request
        // handles one device, and the other device may have written since this
        // model was loaded. Relying on the loaded copy also makes save() a
        // no-op whenever the stale value happens to match, which silently
        // leaves whatever is actually stored in place.
        $stored = DB::table('users')->where('id', $user->id)->value('mobile_app_self_reported');
        $saved = is_string($stored) ? (json_decode($stored, true) ?: []) : [];
        $saved = is_array($saved) ? $saved : [];
        $saved[$app] = (bool) $data['self_reported'];

        // Only the two known audiences are stored, so an unexpected key cannot
        // accumulate in the column. Written as an explicit update so the value
        // always lands, dirty-checking notwithstanding.
        $clean = array_intersect_key($saved, array_flip(MobileAppAdoption::APPS));
        DB::table('users')->where('id', $user->id)->update([
            'mobile_app_self_reported' => json_encode($clean),
        ]);

        return sendSuccess('Mobile app preference saved', [
            'status' => MobileAppAdoption::status($user->fresh(), $app),
        ]);
    }

    /**
     * Adoption report, behind a capability and scoped.
     *
     * Provider staff see their own business's accounts; platform-wide figures
     * need the platform capability. Nothing here returns an email, a device
     * identifier or an installation id beyond the authorized report, and the
     * states are named as evidence, never as "downloaded".
     */
    public function report(Request $request)
    {
        $user = $request->user();
        $app = (string) $request->query('app', 'customer');

        if (! MobileAppAdoption::isApp($app)) {
            return sendError('Choose the customer or admin app.', [
                'app' => ['Unknown app audience.'],
            ], 422);
        }

        $scope = $this->reportScope($user);
        if ($scope['denied']) {
            return sendError('You do not have permission to read the adoption report.', [], 403);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        // The scope is applied as a predicate, not as a list of ids, so a large
        // business does not have to be materialised in PHP to be counted.
        $accounts = fn () => User::query()
            ->when($scope['kind'] === 'business', fn ($q) => AccessScope::applyCustomerScope($q, $user))
            ->when($app === 'customer', fn ($q) => $q->whereRelation('roles', 'name', 'customer'))
            ->when($app === 'admin', fn ($q) => $q->whereRelation(
                'roles',
                fn ($r) => $r->whereIn('name', Capabilities::providerRoles())
            ));

        // Aggregates over the SAME filters, computed before paging — a total
        // that only covers the current page is not a total.
        $totals = $this->totals($accounts, $app);

        $page = $accounts()
            ->orderBy('users.id')
            ->paginate($perPage, ['users.id', 'users.name', 'users.mobile_app_self_reported'])
            ->withQueryString();

        $rows = collect($page->items())->map(function (User $account) use ($app) {
            $status = MobileAppAdoption::status($account, $app);

            return [
                // The account id and nothing identifying beyond it: no email,
                // no phone, no device identifier.
                'account_id'     => $account->id,
                'account_name'   => $account->name ?: '—',
                'app'            => $app,
                'evidence'       => $status['usage_status'],
                'evidence_label' => MobileAppAdoption::evidenceLabel($status['usage_status']),
                'first_authenticated_use_at' => $status['first_authenticated_use_at'],
                'last_authenticated_use_at'  => $status['last_authenticated_use_at'],
                // Labelled separately, and never folded into the use count.
                'self_reported'  => $status['self_reported'],
            ];
        })->all();

        return sendSuccess('Adoption report fetched', [
            'rows'   => $rows,
            'totals' => $totals,
            'scope'  => ['kind' => $scope['kind'], 'app' => $app],
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
            // Said in the response, so a dashboard cannot relabel it.
            'legend' => [
                MobileAppAdoption::STATUS_USED    => MobileAppAdoption::evidenceLabel(MobileAppAdoption::STATUS_USED),
                MobileAppAdoption::STATUS_UNKNOWN => MobileAppAdoption::evidenceLabel(MobileAppAdoption::STATUS_UNKNOWN),
            ],
        ]);
    }

    /**
     * @param  callable():\Illuminate\Database\Eloquent\Builder  $accounts
     * @return array{used:int,unknown:int,self_reported:int,accounts:int}
     */
    protected function totals(callable $accounts, string $app): array
    {
        $total = $accounts()->count('users.id');

        $used = $accounts()
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('mobile_app_installations')
                ->whereColumn('mobile_app_installations.user_id', 'users.id')
                ->where('mobile_app_installations.app', $app))
            ->count('users.id');

        // Counted in bounded chunks: the flag lives in JSON, and a JSON
        // predicate that behaves the same on every engine is not worth the
        // risk of a wrong total.
        $selfReported = 0;
        $accounts()
            ->select(['users.id', 'users.mobile_app_self_reported'])
            ->orderBy('users.id')
            ->chunk(1000, function ($chunk) use (&$selfReported, $app) {
                $selfReported += $chunk->filter(fn (User $u) => MobileAppAdoption::selfReported($u, $app))->count();
            });

        return [
            'accounts'      => $total,
            'used'          => $used,
            'unknown'       => max(0, $total - $used),
            // Counted apart from `used` on purpose: a self-report is not use.
            'self_reported' => $selfReported,
        ];
    }

    /**
     * Who may read what.
     *
     * @return array{denied:bool,kind:string,userIds:?array}
     */
    protected function reportScope(?User $user): array
    {
        if ($user?->hasCapability(MobileAppAdoption::REPORT_CAPABILITY)) {
            return ['denied' => false, 'kind' => 'platform'];
        }

        // Provider staff are scoped to the customers of their own business.
        if (AccessScope::isProviderScoped($user)) {
            return ['denied' => false, 'kind' => 'business'];
        }

        return ['denied' => true, 'kind' => 'none'];
    }
}
