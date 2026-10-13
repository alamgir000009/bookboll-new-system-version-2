/**
 * walletLedger.js — atomic wallet balance changes via Laravel.
 */
import { applyWalletDeltaApi, firstError } from './walletPayouts.js'

/**
 * @param {string} walletId
 * @param {{ pending?: number, available?: number, earned?: number, withdrawn?: number }} delta
 */
export async function applyWalletDelta(walletId, { pending = 0, available = 0, earned = 0, withdrawn = 0 } = {}) {
  if (!walletId) return { error: 'No wallet' }
  if (!pending && !available && !earned && !withdrawn) return { ok: true }
  const res = await applyWalletDeltaApi(walletId, { pending, available, earned, withdrawn })
  if (!res?.success) return { error: firstError(res, 'Wallet delta failed') }
  return { ok: true, wallet: res.data?.wallet }
}
