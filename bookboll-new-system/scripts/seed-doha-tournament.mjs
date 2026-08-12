// Demo tournament in Doha hosted by the preview test customer, with TWO passed
// undecided matches (Jul 3/4) — exercises the host "update your bracket" popup +
// bell notifications + the mobile swipe bracket. New rows only; ids appended to
// scripts/seed-doha-created.json for cleanup.
import { createClient } from '@supabase/supabase-js'
import { readFileSync, writeFileSync } from 'fs'

const env = readFileSync('.env', 'utf8')
const get = k => (env.match(new RegExp(`^${k}=(.*)$`, 'm')) || [])[1]?.trim()
const sb = createClient(get('VITE_SUPABASE_URL'), get('VITE_SUPABASE_ANON_KEY'))

const HOST = '7c2d3b7a-a788-474d-a3bc-2b40c6adc59d'   // osama safdar (preview session)
const CITY = '97064dd0-b0a7-4c81-be7e-488bb821a975'   // Doha
const COUNTRY = '79cad528-bd7f-44f9-adbf-6193025843dd'
const created = JSON.parse(readFileSync('scripts/seed-doha-created.json', 'utf8'))

// Padel court to attach matches to (any seeded listing works for display)
const listing = (await sb.from('listings').select('id,location_id').eq('name', 'Padel Court 1 — Indoor').in('id', created.listings)).data?.[0]
if (!listing) { console.error('seeded listing not found'); process.exit(1) }

const t = await sb.from('hosted_games').insert({
  host_customer_id: HOST, host_name: 'osama safdar', host_phone: '+97455500001', host_whatsapp: '+97455500001',
  city_id: CITY, country_id: COUNTRY, currency: 'QAR', status: 'open', skill_level: 'all',
  game_type: 'tournament', title: 'Doha Padel Cup', sport: 'Padel', num_teams: 8, format: 'single_elim',
  game_date: '2026-07-03', start_time: '18:00', end_time: '21:00',
  price_per_person: 100, max_players: 8, description: 'Eight-team knockout padel cup at Aspire — winners take the pot.',
  listing_id: listing.id, location_id: listing.location_id,
  custom_location_name: '', custom_location_address: '', hide_location: false, images: [], prize_pot: 800,
}).select('id').maybeSingle()
if (t.error) { console.error('tournament:', t.error.message); process.exit(1) }
const tid = t.data.id

const TEAMS = ['Falcons', 'Desert Kings', 'Pearl Smashers', 'Corniche Aces', 'Sand Vipers', 'Bay Breakers', 'Lusail Lions', 'West Bay Wolves']
const M = (round, idx, date, st, et, home, away, winner) => ({
  tournament_id: tid, round, match_index: idx, listing_id: listing.id, location_id: listing.location_id,
  match_date: date, start_time: st, end_time: et, status: winner ? 'done' : 'scheduled',
  home_team: home || '', away_team: away || '', winner: winner || '',
})
const rows = [
  // Quarter-finals: one decided, TWO passed & undecided (→ popup), one upcoming
  M(0, 0, '2026-07-03', '18:00', '19:00', TEAMS[0], TEAMS[1], 'home'),
  M(0, 1, '2026-07-03', '19:00', '20:00', TEAMS[2], TEAMS[3], null),   // passed, due
  M(0, 2, '2026-07-04', '18:00', '19:00', TEAMS[4], TEAMS[5], null),   // passed, due
  M(0, 3, '2026-07-08', '18:00', '19:00', TEAMS[6], TEAMS[7], null),   // upcoming
  // Semi-finals: Falcons advanced from QF1; rest TBD
  M(1, 0, '2026-07-09', '18:00', '19:00', TEAMS[0], '', null),
  M(1, 1, '2026-07-09', '19:00', '20:00', '', '', null),
  // Final
  M(2, 0, '2026-07-10', '19:00', '20:30', '', '', null),
]
const ins = await sb.from('tournament_matches').insert(rows).select('id')
if (ins.error) { console.error('matches:', ins.error.message); await sb.from('hosted_games').delete().eq('id', tid); process.exit(1) }

created.games.push(tid)
created.tournamentMatches = (created.tournamentMatches || []).concat(ins.data.map(r => r.id))
writeFileSync('scripts/seed-doha-created.json', JSON.stringify(created, null, 2))
console.log('tournament:', tid, '| matches:', ins.data.length)
