/**
 * Provider wallet payouts — Laravel admin API
 * (/admin/wallet-payouts/*) for Approvals money tab + Wallet.vue
 */
import { apiFetch } from './api.js'

export function firstError(res, fallback = 'Request failed') {
  if (!res) return fallback
  if (res.errors && typeof res.errors === 'object') {
    const first = Object.values(res.errors).flat()[0]
    if (first) return String(first)
  }
  return res.message || fallback
}

export async function fetchWalletPayoutsDashboard(query = {}) {
  return apiFetch('/admin/wallet-payouts/dashboard', { query })
}

export async function fetchOwnerWalletReview(ownerUserId) {
  return apiFetch(`/admin/wallet-payouts/owners/${ownerUserId}/review`)
}

export async function fetchWallets(query = {}) {
  return apiFetch('/admin/wallet-payouts/wallets', { query })
}

export async function createWallet(body) {
  return apiFetch('/admin/wallet-payouts/wallets', { method: 'POST', body })
}

export async function updateWallet(id, body) {
  return apiFetch(`/admin/wallet-payouts/wallets/${id}`, { method: 'PUT', body })
}

export async function deleteWallet(id) {
  return apiFetch(`/admin/wallet-payouts/wallets/${id}`, { method: 'DELETE' })
}

export async function applyMethodToWallets(body) {
  return apiFetch('/admin/wallet-payouts/wallets/apply-method', { method: 'POST', body })
}

export async function approvePayoutKyc(ownerUserId, body = {}) {
  return apiFetch(`/admin/wallet-payouts/kyc/${ownerUserId}/approve`, { method: 'POST', body })
}

export async function rejectPayoutKyc(ownerUserId, body = {}) {
  return apiFetch(`/admin/wallet-payouts/kyc/${ownerUserId}/reject`, { method: 'POST', body })
}

export async function fetchWithdrawals(query = {}) {
  return apiFetch('/admin/wallet-payouts/withdrawals', { query })
}

export async function createWithdrawal(body) {
  return apiFetch('/admin/wallet-payouts/withdrawals', { method: 'POST', body })
}

/** Insert a already-paid withdrawal row without moving balances (Stripe auto-payout log). */
export async function createPaidWithdrawal(body) {
  return apiFetch('/admin/wallet-payouts/withdrawals/paid-record', { method: 'POST', body })
}

export async function approveWithdrawal(id, body = {}) {
  return apiFetch(`/admin/wallet-payouts/withdrawals/${id}/approve`, { method: 'POST', body })
}

export async function markWithdrawalPaid(id, body = {}) {
  return apiFetch(`/admin/wallet-payouts/withdrawals/${id}/mark-paid`, { method: 'POST', body })
}

export async function rejectWithdrawal(id, body = {}) {
  return apiFetch(`/admin/wallet-payouts/withdrawals/${id}/reject`, { method: 'POST', body })
}

export async function cancelWithdrawal(id, body = {}) {
  return apiFetch(`/admin/wallet-payouts/withdrawals/${id}/cancel`, { method: 'POST', body })
}

export async function applyWalletDeltaApi(walletId, delta = {}) {
  return apiFetch('/admin/wallet-payouts/delta', {
    method: 'POST',
    body: { wallet_id: walletId, ...delta },
  })
}

export async function fetchTransactions(query = {}) {
  return apiFetch('/admin/wallet-payouts/transactions', { query })
}

export async function createWalletTransaction(body) {
  return apiFetch('/admin/wallet-payouts/transactions', { method: 'POST', body })
}

export async function updateWalletTransaction(id, body) {
  return apiFetch(`/admin/wallet-payouts/transactions/${id}`, { method: 'PUT', body })
}
