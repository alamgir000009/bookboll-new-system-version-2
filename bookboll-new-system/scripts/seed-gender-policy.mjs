// Sets gender_policy on the four seeded Doha membership programs so the new
// Mixed / Men only / Women only tags can be tested on the storefront.
//   Pearl Gym Unlimited      → mixed
//   Padel Academy — Juniors  → mixed
//   Sunrise Yoga — Corniche  → women
//   Football Academy U16     → men
// Safe to re-run. If the gender_policy column doesn't exist yet (migration
// 20260738000000 not applied), it says so and exits cleanly — run it again after.
// Run from the project root: node scripts/seed-gender-policy.mjs
import { createClient } from '@supabase/supabase-js'
import { readFileSync } from 'fs'

const env = readFileSync('.env', 'utf8')
const get = k => (env.match(new RegExp(`^${k}=(.*)$`, 'm')) || [])[1]?.trim()
const sb = createClient(get('VITE_SUPABASE_URL'), get('VITE_SUPABASE_ANON_KEY'))

const created = JSON.parse(readFileSync('scripts/seed-doha-created.json', 'utf8'))
const ids = created.programs || []
if (!ids.length) { console.error('no seeded program ids in scripts/seed-doha-created.json'); process.exit(1) }

// name fragment → policy (fragments so dash/spacing variants still match)
const POLICIES = [
  ['Pearl Gym', 'mixed'],
  ['Padel Academy', 'mixed'],
  ['Sunrise Yoga', 'women'],
  ['Football Academy', 'men'],
]

const missingColumn = (e) => e && (e.code === 'PGRST204' || e.code === '42703' || /gender_policy/i.test(e.message || ''))

const { data: progs, error: selErr } = await sb.from('class_programs').select('id, name').in('id', ids)
if (selErr) { console.error('FAILED reading programs:', selErr.message); process.exit(1) }

let updated = 0
for (const p of progs || []) {
  const match = POLICIES.find(([frag]) => p.name.includes(frag))
  if (!match) { console.log('skip (no policy mapped):', p.name); continue }
  const policy = match[1]
  const { data, error } = await sb.from('class_programs')
    .update({ gender_policy: policy }).eq('id', p.id).select('id, name, gender_policy')
  if (error) {
    if (missingColumn(error)) {
      console.log('gender_policy column not found — run migration 20260738000000 first, then re-run this script.')
      process.exit(0)
    }
    console.error('FAILED updating', p.name, '—', error.message)
    process.exit(1)
  }
  if (!data?.length) { console.error('update returned no rows (RLS?) for', p.name); process.exit(1) }
  console.log('set', JSON.stringify(p.name), '→', policy)
  updated++
}
console.log(updated ? `done — ${updated} program(s) updated` : 'nothing updated')
