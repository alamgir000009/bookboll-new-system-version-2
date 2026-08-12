// Probe which migrations have actually been run (missing column/table → 400 error;
// RLS-hidden rows still return 200, so this is reliable via the anon key).
import { createClient } from '@supabase/supabase-js'
import { readFileSync } from 'fs'
const env = readFileSync('.env', 'utf8')
const get = k => (env.match(new RegExp(`^${k}=(.*)$`, 'm')) || [])[1]?.trim()
const sb = createClient(get('VITE_SUPABASE_URL'), get('VITE_SUPABASE_ANON_KEY'))

const probes = [
  ['20260716 tracking_analytics', 'admin_users', 'tracking_meta_pixel'],
  ['20260727 notification_templates', 'admin_users', 'notification_templates'],
  ['20260728 provider_smtp', 'admin_users', 'smtp_config'],
  ['20260730 whatsapp_channel', 'whatsapp_outbound', 'id'],
  ['20260731 provider_whatsapp', 'admin_users', 'whatsapp_config'],
  ['20260732 whatsapp_booking_bot', 'whatsapp_sessions', 'id'],
  ['20260733 customer_auth_otp', 'otp_codes', 'id'],
  ['20260733b customers.first_name', 'customers', 'first_name'],
  ['20260734 entry_passes', 'entry_passes', 'id'],
  ['20260735 wa_queued_campaigns', 'whatsapp_queued_campaigns', 'id'],
  ['20260736 host_stripe_connect', 'customers', 'stripe_connect'],
  ['20260737 game_approval_mode', 'hosted_games', 'approval_mode'],
  ['20260738 program_gender_policy', 'class_programs', 'gender_policy'],
]
for (const [label, table, col] of probes) {
  const r = await sb.from(table).select(col, { head: true, count: 'exact' }).limit(1)
  console.log((r.error ? 'PENDING ' : 'RAN     ') + label + (r.error ? '  (' + r.error.message.slice(0, 60) + ')' : ''))
}
