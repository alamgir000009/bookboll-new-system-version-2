/**
 * ensure-super-admin.mjs
 *
 * Creates or updates the Bookboll super admin Auth user and links it to
 * admin_users so you can log into the dashboard.
 *
 * Defaults:
 *   email    = admin@bookboll.com
 *   password = 12345678
 *
 * Setup (one-time):
 *   1. Supabase Dashboard → Project Settings → API → copy "service_role" key
 *   2. Put it in .env.local (do NOT commit this file):
 *        SUPABASE_SERVICE_ROLE_KEY=eyJhbGciOi...
 *   3. Run:
 *        node scripts/ensure-super-admin.mjs
 *
 * Optional overrides:
 *   SUPER_ADMIN_EMAIL=you@example.com SUPER_ADMIN_PASSWORD=secret node scripts/ensure-super-admin.mjs
 */
import { createClient } from '@supabase/supabase-js'
import { readFileSync, existsSync } from 'fs'
import { fileURLToPath } from 'url'
import path from 'path'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.join(__dirname, '..')

function loadEnvFile(envPath, { override = false } = {}) {
  if (!existsSync(envPath)) return
  let text = readFileSync(envPath, 'utf8')
  // Strip UTF-8 BOM and normalize Windows newlines
  if (text.charCodeAt(0) === 0xfeff) text = text.slice(1)
  text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n')

  for (const raw of text.split('\n')) {
    const line = raw.trim()
    if (!line || line.startsWith('#')) continue
    const eq = line.indexOf('=')
    if (eq <= 0) continue
    const key = line.slice(0, eq).trim()
    let val = line.slice(eq + 1).trim()
    if (
      (val.startsWith('"') && val.endsWith('"')) ||
      (val.startsWith("'") && val.endsWith("'"))
    ) {
      val = val.slice(1, -1)
    }
    if (!key) continue
    if (override || !process.env[key]) process.env[key] = val
  }
}

loadEnvFile(path.join(root, '.env'))
loadEnvFile(path.join(root, '.env.local'), { override: true })

const SUPABASE_URL = process.env.VITE_SUPABASE_URL || process.env.SUPABASE_URL
const SERVICE_ROLE_KEY =
  process.env.SUPABASE_SERVICE_ROLE_KEY ||
  process.env.VITE_SUPABASE_SERVICE_ROLE_KEY
const EMAIL = (process.env.SUPER_ADMIN_EMAIL || 'admin@bookboll.com').toLowerCase()
const PASSWORD = process.env.SUPER_ADMIN_PASSWORD || '12345678'
const FULL_NAME = process.env.SUPER_ADMIN_NAME || 'Bookboll Admin'
const ADMIN_ID = process.env.SUPER_ADMIN_ID || '72fa6328-0c20-45e2-8eda-e40ff6f36c3a'

if (!SUPABASE_URL) {
  console.error('Missing VITE_SUPABASE_URL (check .env)')
  process.exit(1)
}
if (!SERVICE_ROLE_KEY) {
  console.error(`
Missing SUPABASE_SERVICE_ROLE_KEY.

1. Open Supabase Dashboard → Project Settings → API
2. Copy the service_role key (secret)
3. Create .env.local in the project root with:
     SUPABASE_SERVICE_ROLE_KEY=paste_key_here
4. Re-run: node scripts/ensure-super-admin.mjs
`)
  process.exit(1)
}

const supabase = createClient(SUPABASE_URL, SERVICE_ROLE_KEY, {
  auth: { autoRefreshToken: false, persistSession: false },
})

async function findAuthUserByEmail(email) {
  // Paginate — projects can have many users
  let page = 1
  const perPage = 200
  for (;;) {
    const { data, error } = await supabase.auth.admin.listUsers({ page, perPage })
    if (error) throw error
    const users = data?.users || []
    const hit = users.find(u => (u.email || '').toLowerCase() === email)
    if (hit) return hit
    if (users.length < perPage) return null
    page += 1
    if (page > 50) return null
  }
}

async function ensureAuthUser() {
  const existing = await findAuthUserByEmail(EMAIL)
  if (existing) {
    const { data, error } = await supabase.auth.admin.updateUserById(existing.id, {
      password: PASSWORD,
      email_confirm: true,
    })
    if (error) throw error
    console.log(`✓ Auth user updated: ${EMAIL} (${data.user.id})`)
    return data.user
  }

  const { data, error } = await supabase.auth.admin.createUser({
    email: EMAIL,
    password: PASSWORD,
    email_confirm: true,
    user_metadata: { full_name: FULL_NAME, role: 'super_admin' },
  })
  if (error) throw error
  console.log(`✓ Auth user created: ${EMAIL} (${data.user.id})`)
  return data.user
}

async function ensureAdminRow(authUserId) {
  const { data: byEmail, error: findErr } = await supabase
    .from('admin_users')
    .select('*')
    .ilike('email', EMAIL)
    .maybeSingle()
  if (findErr) throw findErr

  const base = {
    role: 'super_admin',
    status: 'active',
    full_name: byEmail?.full_name || FULL_NAME,
    email: EMAIL,
  }

  // Try richest payload first; strip columns the live schema may not have yet.
  const attempts = [
    { ...base, auth_user_id: authUserId, password_set: true, username: 'bookboll' },
    { ...base, auth_user_id: authUserId, password_set: true },
    { ...base, auth_user_id: authUserId },
    { ...base },
  ]

  if (byEmail) {
    let lastError = null
    for (const payload of attempts) {
      const { error } = await supabase.from('admin_users').update(payload).eq('id', byEmail.id)
      if (!error) {
        console.log(`✓ admin_users updated: ${byEmail.id}`)
        if (!payload.auth_user_id) {
          console.log('  (auth_user_id column missing — login still works via email match)')
        }
        return byEmail.id
      }
      lastError = error
    }
    throw lastError
  }

  for (const payload of attempts) {
    const row = { id: ADMIN_ID, business_name: null, ...payload }
    const { error } = await supabase.from('admin_users').upsert(row, { onConflict: 'id' })
    if (!error) {
      console.log(`✓ admin_users created/upserted: ${ADMIN_ID}`)
      if (!payload.auth_user_id) {
        console.log('  (auth_user_id column missing — login still works via email match)')
      }
      return ADMIN_ID
    }
    // keep trying leaner payloads
    if (!String(error.message || '').includes('column')) throw error
  }

  throw new Error('Could not create admin_users row')
}

async function main() {
  console.log(`Project: ${SUPABASE_URL}`)
  console.log(`Ensuring super admin: ${EMAIL}`)
  const user = await ensureAuthUser()
  await ensureAdminRow(user.id)
  console.log(`
Done. Log in to the admin dashboard with:
  Email:    ${EMAIL}
  Password: ${PASSWORD}
`)
}

main().catch((err) => {
  console.error('Failed:', err.message || err)
  process.exit(1)
})
