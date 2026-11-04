/**
 * entryPass.js — entry passes (QR + short text code) and attendance check-in.
 *
 * WHY THIS SHAPE: one pass per booking / membership, generated LAZILY the first
 * time the customer opens "My Pass" (so no write is needed at checkout time and
 * pre-existing bookings get passes too). The QR encodes a provider-side URL
 * (`{origin}/checkin?code=XXXXXX`) — the provider scans it with any phone
 * camera, which opens their auth-gated dashboard check-in page with the code
 * prefilled. No in-app camera/scanner library needed; typing the 6-char code
 * works exactly the same.
 *
 * Scoping: lookups verify the pass belongs to the signed-in provider
 * (owner_user_id) unless the caller is platform staff.
 */
import { adminBaseUrl } from './appBase.js'
import QRCode from 'qrcode'
import { apiLoadMemberPassBundle } from '../customer/apiPrograms.js'
export async function passQrDataUrl(code) {
  const url = `${adminBaseUrl()}/checkin?code=${encodeURIComponent(code)}`
  try {
    return await QRCode.toDataURL(url, { width: 480, margin: 1, errorCorrectionLevel: 'M' })
  } catch { return '' }
}

/** Get-or-create the pass row for one booking/membership. */
export async function loadMemberPassBundle({ customerId, email }) {
  if (!customerId && !email) return { pass: null, memberships: [] }
  const bundle = await apiLoadMemberPassBundle()
  return { pass: bundle.pass || null, memberships: bundle.memberships || [] }
}

/**
 * Customer side: MEMBERSHIP passes only (Laravel).
 */
export async function loadCustomerPasses({ customerId, email }) {
  if (!customerId && !email) return []
  const bundle = await apiLoadMemberPassBundle()
  return bundle.legacyPasses || []
}

/**
 * Provider side: look up a code and return everything needed to verify at the
 * door. `viewer` = { userId, isPlatform } — non-platform staff only see passes
 * for their own business.
 */