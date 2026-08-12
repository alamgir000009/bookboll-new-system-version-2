/**
 * emailSender.js — the provider's own email-sender (SMTP) identity.
 *
 * Where it's used:
 *  - AUTOMATED emails (Notifications page): OPTIONAL. When connected + "use for automated"
 *    is on, booking/membership notification emails go out from the provider's address;
 *    otherwise they fall back to Bookboll's shared sender.
 *  - MARKETING emails (Customers page bulk composer): MANDATORY for providers. A business
 *    can only email its own customer list from its own verified sender.
 *
 * Stored on admin_users.smtp_config (jsonb) — migration 20260728000000_provider_smtp.sql.
 * Actual delivery is a production concern; here the connection + test send are SIMULATED,
 * consistent with the rest of the demo (the password would be vault-stored server-side in prod).
 */
import { ref, computed } from 'vue'
import { supabase } from './supabase.js'
import { useCurrentUser } from './currentUser.js'

export const SMTP_ENCRYPTION = [
  { value: 'tls',  label: 'TLS (recommended)' },
  { value: 'ssl',  label: 'SSL' },
  { value: 'none', label: 'None' },
]

// One-click fill for the common providers (host / port / encryption + a username hint).
export const SMTP_PRESETS = [
  { key: 'gmail',    label: 'Gmail / Google Workspace', host: 'smtp.gmail.com',        port: 587, encryption: 'tls', hint: 'Use an App Password, not your normal login password.' },
  { key: 'outlook',  label: 'Outlook / Microsoft 365',  host: 'smtp.office365.com',    port: 587, encryption: 'tls', hint: 'Username is your full email address.' },
  { key: 'mailjet',  label: 'Mailjet',                  host: 'in-v3.mailjet.com',     port: 587, encryption: 'tls', hint: 'Username = API Key, Password = Secret Key.' },
  { key: 'sendgrid', label: 'SendGrid',                 host: 'smtp.sendgrid.net',     port: 587, encryption: 'tls', hint: 'Username is literally "apikey"; password is your API key.' },
  { key: 'ses',      label: 'Amazon SES',               host: 'email-smtp.us-east-1.amazonaws.com', port: 587, encryption: 'tls', hint: 'Use your SES SMTP credentials (not your AWS keys).' },
  { key: 'zoho',     label: 'Zoho Mail',                host: 'smtp.zoho.com',         port: 587, encryption: 'tls', hint: 'Enable IMAP/SMTP access in Zoho settings first.' },
  { key: 'custom',   label: 'Other / custom',           host: '',                      port: 587, encryption: 'tls', hint: 'Enter your mail host details manually.' },
]

export function blankSmtpConfig() {
  return {
    enabled: false,
    from_email: '',
    from_name: '',
    host: '',
    port: 587,
    encryption: 'tls',
    username: '',
    password: '',
    use_for_automated: true,   // once connected, also send automated notifications from here
    verified_at: null,         // set when a test send succeeds (simulated)
  }
}

const EMAIL_RE = /^[^@\s]+@[^@\s]+\.[^@\s]+$/

/** Field-level validation for the setup form. Returns { ok, errors:{field:msg} }. */
export function validateSmtpConfig(c) {
  const errors = {}
  if (!EMAIL_RE.test(c.from_email || '')) errors.from_email = 'Enter a valid “from” email address.'
  if (!(c.from_name || '').trim())        errors.from_name = 'Add the name recipients will see.'
  if (!(c.host || '').trim())             errors.host = 'Enter your SMTP host.'
  if (!(Number(c.port) > 0))              errors.port = 'Enter a valid port (e.g. 587).'
  if (!(c.username || '').trim())         errors.username = 'Enter your SMTP username.'
  if (!(c.password || '').trim())         errors.password = 'Enter your SMTP password / API key.'
  return { ok: Object.keys(errors).length === 0, errors }
}

/** Connected = enabled and every required field is filled + valid. */
export function isSmtpConfigured(c) {
  return !!(c && c.enabled && validateSmtpConfig(c).ok)
}

/** Should automated notification emails use the provider's sender? */
export function useSmtpForAutomated(c) {
  return isSmtpConfigured(c) && c.use_for_automated !== false
}

export async function loadSmtpConfig(ownerId) {
  if (!ownerId) return blankSmtpConfig()
  const { data } = await supabase.from('admin_users').select('smtp_config').eq('id', ownerId).maybeSingle()
  return { ...blankSmtpConfig(), ...(data?.smtp_config || {}) }
}

export async function saveSmtpConfig(ownerId, config) {
  if (!ownerId) return { error: { message: 'No business selected.' } }
  const { error } = await supabase.from('admin_users').update({ smtp_config: config }).eq('id', ownerId)
  return { error }
}

/**
 * SIMULATED test send: validate the config, then pretend to deliver. In production this
 * hits a server endpoint that actually opens the SMTP connection and sends.
 */
export async function sendTestEmail(config, toEmail) {
  const v = validateSmtpConfig(config)
  if (!v.ok) return { ok: false, error: 'Complete the required fields first.' }
  if (!EMAIL_RE.test(toEmail || '')) return { ok: false, error: 'Enter a valid address to send the test to.' }
  await new Promise(r => setTimeout(r, 700))   // feel of a real round-trip
  return { ok: true }
}

/**
 * Reactive helper for the current business's sender — used by the marketing composer
 * (mandatory gate) and the notifications banner (optional).
 */
export function useProviderSmtp() {
  const { currentOwnerUserId } = useCurrentUser()
  const smtp = ref(blankSmtpConfig())
  const loaded = ref(false)
  async function load() {
    smtp.value = await loadSmtpConfig(currentOwnerUserId.value)
    loaded.value = true
  }
  const configured = computed(() => isSmtpConfigured(smtp.value))
  const automated = computed(() => useSmtpForAutomated(smtp.value))
  return { smtp, loaded, load, configured, automated }
}
