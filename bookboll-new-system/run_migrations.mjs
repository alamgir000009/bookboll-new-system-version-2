/**
 * run_migrations.mjs  —  Bookboll complete DB setup
 *
 * Two connection modes (tries in order):
 *
 *   MODE 1 — Supabase Management API (recommended, no direct DB access needed)
 *   ──────────────────────────────────────────────────────────────────────────
 *   Requires a Personal Access Token from:
 *     https://supabase.com/dashboard/account/tokens  →  "New token"
 *
 *   Set it as an environment variable before running:
 *     SUPABASE_TOKEN=sbp_xxxx node run_migrations.mjs
 *
 *   Or add to .env.local:
 *     SUPABASE_TOKEN=sbp_xxxx
 *
 *
 *   MODE 2 — Direct PostgreSQL connection (fallback)
 *   ──────────────────────────────────────────────────────────────────────────
 *   Get the connection string from:
 *     Supabase Dashboard → Project Settings → Database → Connection string (URI tab)
 *
 *   Set it as:
 *     SUPABASE_DB_URL="postgresql://postgres:[password]@db.[ref].supabase.co:5432/postgres"
 *
 *   Or add to .env.local:
 *     SUPABASE_DB_URL=postgresql://postgres:Pewpewpew@12@db.juoljpumvcfptzbeeewom.supabase.co:5432/postgres
 */

import fs   from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

// ── Load .env.local / .env ────────────────────────────────────────────────────
for (const envFile of ['.env.local', '.env']) {
  const envPath = path.join(__dirname, envFile)
  if (fs.existsSync(envPath)) {
    fs.readFileSync(envPath, 'utf8').split('\n').forEach(line => {
      const m = line.match(/^([A-Z_a-z][A-Z_a-z0-9]*)=(.*)$/)
      if (m && !process.env[m[1]]) process.env[m[1]] = m[2].trim().replace(/^["']|["']$/g, '')
    })
  }
}

// ── Config ────────────────────────────────────────────────────────────────────
const PROJECT_REF    = 'juoljpumvcfptzbeeewom'
const DB_PASSWORD    = 'Pewpewpew@12'
const SUPABASE_TOKEN = process.env.SUPABASE_TOKEN   // sbp_... personal access token
const SUPABASE_DB_URL = process.env.SUPABASE_DB_URL  // postgresql://... full URI

// ── SQL steps (in order) ──────────────────────────────────────────────────────
const KEY_CITY_FIX = `
DO $$
DECLARE
  c_ae uuid := (SELECT id FROM countries WHERE iso_code = 'AE' LIMIT 1);
  c_qa uuid := (SELECT id FROM countries WHERE iso_code = 'QA' LIMIT 1);
  c_eg uuid := (SELECT id FROM countries WHERE iso_code = 'EG' LIMIT 1);
  c_gb uuid := (SELECT id FROM countries WHERE iso_code = 'GB' LIMIT 1);
  c_sg uuid := (SELECT id FROM countries WHERE iso_code = 'SG' LIMIT 1);
BEGIN
  DELETE FROM cities WHERE
    (name = 'Dubai'     AND country_id = c_ae) OR
    (name = 'Abu Dhabi' AND country_id = c_ae) OR
    (name = 'Doha'      AND country_id = c_qa) OR
    (name = 'Cairo'     AND country_id = c_eg) OR
    (name = 'London'    AND country_id = c_gb) OR
    (name = 'Singapore' AND country_id = c_sg);

  DELETE FROM cities WHERE id IN (
    'cba3f62e-4be0-483a-b8dc-42760c3cc153',
    '9aefa213-8cc9-4d7d-a2e7-2d16d05a0501',
    '97064dd0-b0a7-4c81-be7e-488bb821a975',
    'd128a349-5b01-4b48-8bad-b4c8d5bc475a',
    '76724e31-fba9-474f-be04-7daed7365de8',
    'b2f048da-1eac-4409-9393-d410d1dde861'
  );

  INSERT INTO cities (id, country_id, name, status) VALUES
    ('cba3f62e-4be0-483a-b8dc-42760c3cc153', c_ae, 'Dubai',     'active'),
    ('9aefa213-8cc9-4d7d-a2e7-2d16d05a0501', c_ae, 'Abu Dhabi', 'active'),
    ('97064dd0-b0a7-4c81-be7e-488bb821a975', c_qa, 'Doha',      'active'),
    ('d128a349-5b01-4b48-8bad-b4c8d5bc475a', c_eg, 'Cairo',     'active'),
    ('76724e31-fba9-474f-be04-7daed7365de8', c_gb, 'London',    'active'),
    ('b2f048da-1eac-4409-9393-d410d1dde861', c_sg, 'Singapore', 'active');
END $$;
`

const STORAGE_BUCKET = `
INSERT INTO storage.buckets (id, name, public)
VALUES ('listing-images', 'listing-images', true)
ON CONFLICT (id) DO UPDATE SET public = true;
`

const STEPS = [
  { label: 'Schema (tables, indexes, RLS, new columns)', file: 'SETUP_1_SCHEMA.sql' },
  { label: 'Countries seed',                             file: 'SETUP_2A_COUNTRIES.sql' },
  { label: 'Key city UUID fix',                          sql:  KEY_CITY_FIX },
  { label: 'All cities (world seed)',                    file: 'SETUP_2C_ALL_CITIES.sql' },
  { label: 'Dummy data (providers, listings, bookings)', file: 'SETUP_2D_DATA.sql' },
  { label: 'listing-images storage bucket',              sql:  STORAGE_BUCKET },
]

function loadSQL(step) {
  if (step.sql)  return step.sql
  return fs.readFileSync(path.join(__dirname, step.file), 'utf8')
}

// ════════════════════════════════════════════════════════════════════════════
// MODE 1: Supabase Management API
// ════════════════════════════════════════════════════════════════════════════
async function runViaManagementAPI() {
  const API = `https://api.supabase.com/v1/projects/${PROJECT_REF}/database/query`

  async function exec(label, sql) {
    process.stdout.write(`  ▶  ${label}... `)
    const res = await fetch(API, {
      method:  'POST',
      headers: { 'Authorization': `Bearer ${SUPABASE_TOKEN}`, 'Content-Type': 'application/json' },
      body:    JSON.stringify({ query: sql }),
    })
    if (!res.ok) {
      const body = await res.text()
      console.log('❌')
      throw new Error(`HTTP ${res.status} — ${body}`)
    }
    console.log('✅')
  }

  // Quick connectivity test
  process.stdout.write('  Connecting to Management API... ')
  const test = await fetch(`https://api.supabase.com/v1/projects/${PROJECT_REF}`, {
    headers: { 'Authorization': `Bearer ${SUPABASE_TOKEN}` },
  })
  if (!test.ok) {
    console.log('❌')
    const body = await test.text()
    throw new Error(`Project not found or token invalid (HTTP ${test.status}): ${body}`)
  }
  const proj = await test.json()
  console.log(`✅  (project: ${proj.name || PROJECT_REF}, status: ${proj.status || 'unknown'})`)

  for (const step of STEPS) {
    await exec(step.label, loadSQL(step))
  }
}

// ════════════════════════════════════════════════════════════════════════════
// MODE 2: Direct PostgreSQL via pg
// ════════════════════════════════════════════════════════════════════════════
async function runViaPg(connectionString) {
  const pg = (await import('pg')).default
  const client = new pg.Client({
    connectionString,
    ssl: { rejectUnauthorized: false },
    connectionTimeoutMillis: 30000,
  })

  process.stdout.write('  Connecting to PostgreSQL... ')
  await client.connect()
  console.log('✅')

  async function exec(label, sql) {
    process.stdout.write(`  ▶  ${label}... `)
    await client.query(sql)
    console.log('✅')
  }

  try {
    for (const step of STEPS) {
      await exec(step.label, loadSQL(step))
    }
  } finally {
    await client.end().catch(() => {})
  }
}

// ════════════════════════════════════════════════════════════════════════════
// Entry point
// ════════════════════════════════════════════════════════════════════════════
;(async () => {
  console.log(`\n🚀  Bookboll DB Setup — project ref: ${PROJECT_REF}\n`)

  // ── Try Management API first ─────────────────────────────────────────────
  if (SUPABASE_TOKEN) {
    console.log('📡  Mode: Supabase Management API')
    try {
      await runViaManagementAPI()
      console.log('\n🎉  All done! Restart your dev server: npm run dev\n')
      return
    } catch (err) {
      console.error(`\n⚠️  Management API failed: ${err.message}`)
      if (SUPABASE_DB_URL) {
        console.log('    Falling back to direct PostgreSQL...\n')
      } else {
        printHelp()
        process.exit(1)
      }
    }
  }

  // ── Try direct pg connection ──────────────────────────────────────────────
  if (SUPABASE_DB_URL) {
    console.log('🔌  Mode: Direct PostgreSQL')
    try {
      await runViaPg(SUPABASE_DB_URL)
      console.log('\n🎉  All done! Restart your dev server: npm run dev\n')
      return
    } catch (err) {
      console.error(`\n💥  Direct connection failed: ${err.message}`)
      printHelp()
      process.exit(1)
    }
  }

  // ── Neither token provided ────────────────────────────────────────────────
  printHelp()
  process.exit(1)
})()

function printHelp() {
  console.error(`
╔══════════════════════════════════════════════════════════════════════════╗
║  HOW TO RUN MIGRATIONS                                                   ║
╠══════════════════════════════════════════════════════════════════════════╣
║                                                                          ║
║  OPTION A — Supabase Management API (easiest)                            ║
║  ─────────────────────────────────────────────                           ║
║  1. Go to: https://supabase.com/dashboard/account/tokens                ║
║  2. Click "New token", name it "migrations", copy the token (sbp_...)   ║
║  3. Run:                                                                 ║
║       SUPABASE_TOKEN=sbp_xxxx node run_migrations.mjs                   ║
║                                                                          ║
║  OPTION B — Direct DB (if you have the DB connection string)             ║
║  ────────────────────────────────────────────────────────────            ║
║  1. Supabase Dashboard → Project Settings → Database → Connection string ║
║  2. Copy the URI (postgresql://postgres:...@db.xxx.supabase.co/postgres) ║
║  3. Run:                                                                 ║
║       SUPABASE_DB_URL="postgresql://..." node run_migrations.mjs        ║
║                                                                          ║
║  OPTION C — Paste into SQL Editor (no token needed)                      ║
║  ──────────────────────────────────────────────────                      ║
║  1. Supabase Dashboard → SQL Editor                                      ║
║  2. Open FULL_SETUP.sql from this folder                                 ║
║  3. Select all, paste, run                                               ║
║                                                                          ║
╚══════════════════════════════════════════════════════════════════════════╝
`)
}
