/**
 * hostPayouts.js — Stripe Connect for event HOSTS (customers), Luma-style.
 *
 * SIMULATED (like every payment rail in this app): "connecting" writes a demo
 * account object to customers.stripe_connect; production swaps connectStripeDemo
 * for Stripe's OAuth/Account-Links flow with the same stored shape.
 *
 * Why hosts connect: with Stripe tickets on an event, joiners pay ONLINE and
 * Bookboll's service fee is split from each ticket automatically — so the host
 * skips the upfront per-seat fee, and proceeds land on their Stripe payout
 * schedule instead of being collected in cash.
 */
import { supabase } from '../lib/supabase.js'

export async function loadStripeConnect(customerId) {
  if (!customerId) return null
  const { data, error } = await supabase.from('customers').select('stripe_connect').eq('id', customerId).maybeSingle()
  if (error) return null           // column missing (migration not run) → treated as not connected
  return data?.stripe_connect || null
}

export async function connectStripeDemo(customerId, { country } = {}) {
  if (!customerId) return { error: 'Sign in first.' }
  const acct = {
    connected: true,
    account_id: 'acct_demo_' + Math.random().toString(36).slice(2, 10),
    country: country || '',
    connected_at: new Date().toISOString(),
  }
  const { error } = await supabase.from('customers').update({ stripe_connect: acct }).eq('id', customerId)
  if (error) {
    return { error: /stripe_connect|schema cache/i.test(error.message || '')
      ? 'Run migration 20260736000000 (host Stripe Connect) first.'
      : error.message }
  }
  return { account: acct }
}

export async function disconnectStripe(customerId) {
  if (!customerId) return
  await supabase.from('customers').update({ stripe_connect: null }).eq('id', customerId)
}

export const isStripeConnected = (sc) => !!(sc && sc.connected && sc.account_id)
