import { supabase } from './supabase.js'

/**
 * walletLedger.js — atomic wallet balance changes.
 *
 * Every place that moves provider money (payment credit, hold release, payout
 * reserve/settle/refund) must change balances as DELTAS through here, never by
 * writing absolute totals computed from a possibly-stale row. The
 * wallet_apply_delta SQL function applies the change in one UPDATE statement,
 * so concurrent steps can't overwrite each other (the root cause of wallet
 * drift). Before that migration is applied we fall back to the legacy
 * read-then-write path — same behaviour as before, just not race-proof.
 */
export async function applyWalletDelta(walletId, { pending = 0, available = 0, earned = 0, withdrawn = 0 } = {}) {
  if (!walletId) return { error: 'No wallet' }
  if (!pending && !available && !earned && !withdrawn) return { ok: true }
  try {
    const { error } = await supabase.rpc('wallet_apply_delta', {
      p_wallet_id: walletId, p_pending: pending, p_available: available, p_earned: earned, p_withdrawn: withdrawn,
    })
    if (!error) return { ok: true }
    // Function not installed yet (pre-migration) → legacy fallback below.
  } catch { /* fall through to fallback */ }
  const { data: w, error: rerr } = await supabase.from('provider_wallets').select('*').eq('id', walletId).maybeSingle()
  if (rerr || !w) return { error: rerr?.message || 'Wallet not found' }
  const { error } = await supabase.from('provider_wallets').update({
    pending_balance:    Math.max(0, +(Number(w.pending_balance || 0) + pending).toFixed(2)),
    available_balance:  +(Number(w.available_balance || 0) + available).toFixed(2),
    lifetime_earnings:  +(Number(w.lifetime_earnings || 0) + earned).toFixed(2),
    lifetime_withdrawn: +(Number(w.lifetime_withdrawn || 0) + withdrawn).toFixed(2),
    updated_at: new Date().toISOString(),
  }).eq('id', walletId)
  return error ? { error: error.message } : { ok: true }
}
