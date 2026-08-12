// Two more PUBLIC demo tournaments in Doha (hosted by demo customers, so the
// preview user can browse/join them as a visitor): one mid-bracket with decided
// quarters, one fresh with all matches upcoming. New rows only; ids appended to
// scripts/seed-doha-created.json.
import { createClient } from '@supabase/supabase-js'
import { readFileSync, writeFileSync } from 'fs'

const env = readFileSync('.env', 'utf8')
const get = k => (env.match(new RegExp(`^${k}=(.*)$`, 'm')) || [])[1]?.trim()
const sb = createClient(get('VITE_SUPABASE_URL'), get('VITE_SUPABASE_ANON_KEY'))

const CITY = '97064dd0-b0a7-4c81-be7e-488bb821a975'
const COUNTRY = '79cad528-bd7f-44f9-adbf-6193025843dd'
const created = JSON.parse(readFileSync('scripts/seed-doha-created.json', 'utf8'))

const football = (await sb.from('listings').select('id,location_id').eq('name', '5-a-side Football Pitch').in('id', created.listings)).data?.[0]
const squash = (await sb.from('listings').select('id,location_id').eq('name', 'Squash Court A').in('id', created.listings)).data?.[0]
if (!football || !squash) { console.error('seeded listings missing'); process.exit(1) }

const mkT = (host, name, o) => ({
  host_customer_id: host, host_name: name, host_phone: '+97455500002', host_whatsapp: '+97455500002',
  city_id: CITY, country_id: COUNTRY, currency: 'QAR', status: 'open', skill_level: 'all',
  game_type: 'tournament', format: 'single_elim',
  custom_location_name: '', custom_location_address: '', hide_location: false, images: [], description: '', ...o,
})
const M = (tid, l, round, idx, date, st, et, home, away, winner) => ({
  tournament_id: tid, round, match_index: idx, listing_id: l.id, location_id: l.location_id,
  match_date: date, start_time: st, end_time: et, status: winner ? 'done' : 'scheduled',
  home_team: home || '', away_team: away || '', winner: winner || '',
})

// 1) Corniche Football Cup — 8 teams, quarters DONE, semis upcoming (browse a live bracket)
const t1 = await sb.from('hosted_games').insert(mkT('d1000000-0000-0000-0000-000000000001', 'Mohammed Al Amin', {
  title: 'Corniche Football Cup', sport: 'Football', num_teams: 8, max_players: 8,
  game_date: '2026-07-09', start_time: '17:00', end_time: '21:00', price_per_person: 150, prize_pot: 1000,
  listing_id: football.id, location_id: football.location_id,
  description: '5-a-side knockout on the Corniche pitch — quarters are done, semis this Thursday under the lights.',
})).select('id').maybeSingle()
if (t1.error) { console.error('t1:', t1.error.message); process.exit(1) }
const F = ['Al Sadd Stars', 'Marina FC', 'Souq Strikers', 'Katara Kings', 'Oryx United', 'Dune Runners', 'Falcon City', 'Old Port FC']
const t1rows = [
  M(t1.data.id, football, 0, 0, '2026-07-02', '17:00', '18:00', F[0], F[1], 'home'),
  M(t1.data.id, football, 0, 1, '2026-07-02', '18:00', '19:00', F[2], F[3], 'away'),
  M(t1.data.id, football, 0, 2, '2026-07-03', '17:00', '18:00', F[4], F[5], 'home'),
  M(t1.data.id, football, 0, 3, '2026-07-03', '18:00', '19:00', F[6], F[7], 'away'),
  M(t1.data.id, football, 1, 0, '2026-07-09', '17:00', '18:00', F[0], F[3], null),
  M(t1.data.id, football, 1, 1, '2026-07-09', '18:00', '19:00', F[4], F[7], null),
  M(t1.data.id, football, 2, 0, '2026-07-11', '19:00', '20:00', '', '', null),
]

// 2) Pearl Squash Open — 4 teams, everything upcoming (fresh bracket)
const t2 = await sb.from('hosted_games').insert(mkT('d1000000-0000-0000-0000-000000000004', 'Hana El Sayed', {
  title: 'Pearl Squash Open', sport: 'Squash', num_teams: 4, max_players: 4,
  game_date: '2026-07-10', start_time: '16:00', end_time: '19:00', price_per_person: 80, prize_pot: 300,
  listing_id: squash.id, location_id: squash.location_id,
  description: 'Four-player knockout at Pearl Fitness — best of five, winner takes the pot.',
})).select('id').maybeSingle()
if (t2.error) { console.error('t2:', t2.error.message); process.exit(1) }
const S = ['Hana E.', 'Yousef K.', 'Marta P.', 'Ali R.']
const t2rows = [
  M(t2.data.id, squash, 0, 0, '2026-07-10', '16:00', '17:00', S[0], S[1], null),
  M(t2.data.id, squash, 0, 1, '2026-07-10', '17:00', '18:00', S[2], S[3], null),
  M(t2.data.id, squash, 1, 0, '2026-07-10', '18:00', '19:00', '', '', null),
]

const ins = await sb.from('tournament_matches').insert([...t1rows.map(r => ({ ...r, winner: r.winner || '' })), ...t2rows]).select('id')
if (ins.error) { console.error('matches:', ins.error.message); process.exit(1) }

created.games.push(t1.data.id, t2.data.id)
created.tournamentMatches = (created.tournamentMatches || []).concat(ins.data.map(r => r.id))
writeFileSync('scripts/seed-doha-created.json', JSON.stringify(created, null, 2))
console.log('tournaments:', t1.data.id, t2.data.id, '| matches:', ins.data.length)
