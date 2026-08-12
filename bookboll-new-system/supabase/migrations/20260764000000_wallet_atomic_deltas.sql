-- Atomic wallet balance updates.
--
-- Until now the app updated provider_wallets balances by reading the row,
-- adding/subtracting in JavaScript and writing the total back. Two of those
-- happening at the same moment (two admin tabs, two customers paying at once)
-- silently lose or double-count one step — that's what the "wallet needs
-- reconciling" banner catches after the fact.
--
-- This function applies balance CHANGES in a single UPDATE statement, so
-- Postgres serialises concurrent calls and no step can ever be lost. The app
-- falls back to the old read-then-write path until this migration is applied.
create or replace function wallet_apply_delta(
  p_wallet_id uuid,
  p_pending   numeric default 0,
  p_available numeric default 0,
  p_earned    numeric default 0,
  p_withdrawn numeric default 0
) returns void
language sql
as $$
  update provider_wallets set
    pending_balance    = greatest(0, coalesce(pending_balance, 0)   + p_pending),
    available_balance  = coalesce(available_balance, 0) + p_available,
    lifetime_earnings  = coalesce(lifetime_earnings, 0) + p_earned,
    lifetime_withdrawn = coalesce(lifetime_withdrawn, 0) + p_withdrawn,
    updated_at         = now()
  where id = p_wallet_id;
$$;

grant execute on function wallet_apply_delta(uuid, numeric, numeric, numeric, numeric) to anon, authenticated;
