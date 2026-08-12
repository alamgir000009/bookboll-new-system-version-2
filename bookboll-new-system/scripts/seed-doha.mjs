// Seed demo content for Doha, Qatar — venues + court listings + membership
// programs + a week of hosted events with joiners, so the mobile storefront can
// be tested against real-looking data. Creates ONLY NEW rows (never touches
// existing records) and writes every created id to scripts/seed-doha-created.json
// so the whole seed can be removed later.
import { createClient } from '@supabase/supabase-js'
import { readFileSync, writeFileSync } from 'fs'

const env = readFileSync('.env', 'utf8')
const get = k => (env.match(new RegExp(`^${k}=(.*)$`, 'm')) || [])[1]?.trim()
const sb = createClient(get('VITE_SUPABASE_URL'), get('VITE_SUPABASE_ANON_KEY'))

const COUNTRY = '79cad528-bd7f-44f9-adbf-6193025843dd' // Qatar
const CITY = '97064dd0-b0a7-4c81-be7e-488bb821a975'    // Doha
const OWNER = 'a1000000-0000-0000-0000-000000000002'   // existing Doha demo provider
const CUR = 'QAR'
const img = (seed, w = 900, h = 600) => `https://picsum.photos/seed/${seed}/${w}/${h}`
// Resume support: reuse ids from a previous partial run instead of duplicating.
let prev = {}
try { prev = JSON.parse(readFileSync('scripts/seed-doha-created.json', 'utf8')) } catch { /* fresh run */ }
const created = { locations: prev.locations || [], listings: prev.listings || [], programs: prev.programs || [], games: prev.games || [], requests: prev.requests || [] }
const die = (step, error) => { console.error('FAILED at', step, error?.message || error); writeFileSync('scripts/seed-doha-created.json', JSON.stringify(created, null, 2)); process.exit(1) }

// Mirror field conventions from existing rows of THIS db (enum-ish columns).
const sampleListing = (await sb.from('listings').select('*').eq('location_id', 'b1000000-0000-0000-0000-000000000002').limit(1)).data?.[0]
  || (await sb.from('listings').select('*').limit(1)).data?.[0]
const sampleProgram = (await sb.from('class_programs').select('*').limit(1)).data?.[0]
console.log('conventions — listing_type:', sampleListing?.listing_type, '| start_time_mode:', sampleListing?.start_time_mode, '| program_type:', sampleProgram?.program_type, '| billing_interval:', sampleProgram?.billing_interval)

// ── 1) Venues ─────────────────────────────────────────────────────────────────
const venues = [
  { name: 'Aspire Padel Club', address: 'Al Waab Street, Aspire Zone, Doha', lat: 25.2632, lng: 51.4448, seed: 'aspirepadel', desc: 'Indoor air-conditioned padel courts in the Aspire Zone — pro shop, showers and free parking.' },
  { name: 'Corniche Sports Complex', address: 'Corniche Promenade, West Bay, Doha', lat: 25.3208, lng: 51.5281, seed: 'cornichesports', desc: 'Multi-sport complex on the Corniche: football pitches, tennis and basketball with skyline views.' },
  { name: 'Pearl Fitness & Courts', address: 'Porto Arabia, The Pearl Island, Doha', lat: 25.3697, lng: 51.5526, seed: 'pearlfitness', desc: 'Boutique fitness club and racket courts at The Pearl — gym floor, studios, squash and badminton.' },
]
const locRows = venues.map(v => ({
  name: v.name, address: v.address, description: v.desc, city_id: CITY, owner_user_id: OWNER,
  status: 'active', approval_status: 'approved', latitude: v.lat, longitude: v.lng,
  photos: [img(v.seed), img(v.seed + '2'), img(v.seed + '3')], photo_url: img(v.seed),
}))
let locData
if (created.locations.length) {
  locData = (await sb.from('locations').select('id,name').in('id', created.locations)).data
  console.log('venues: reusing', locData.length, 'from previous run')
} else {
  const locIns = await sb.from('locations').insert(locRows).select('id,name')
  if (locIns.error) die('locations', locIns.error)
  locData = locIns.data
  created.locations = locData.map(l => l.id)
  console.log('venues:', locData.map(l => l.name).join(' · '))
}
const L = Object.fromEntries(locData.map(l => [l.name, l.id]))

// ── 2) Court/space listings ───────────────────────────────────────────────────
const mkListing = (loc, name, sport, price, extra = {}) => ({
  location_id: L[loc], name, sport_category: sport, base_price: price, slot_duration: 60,
  status: 'active', approval_status: 'approved', show_on_marketplace: true, show_on_provider_site: true,
  category: extra.category || 'sports', listing_type: sampleListing?.listing_type ?? null,
  start_time_mode: sampleListing?.start_time_mode ?? null, capacity: extra.capacity || 10,
  photos: [img(extra.seed || name.replace(/\W+/g, '').toLowerCase()), img((extra.seed || name.replace(/\W+/g, '').toLowerCase()) + 'b')],
  photo_url: img(extra.seed || name.replace(/\W+/g, '').toLowerCase()),
  description: extra.desc || `${name} at ${loc} — book by the hour.`, ...(extra.rest || {}),
})
const listingRows = [
  mkListing('Aspire Padel Club', 'Padel Court 1 — Indoor', 'Padel', 180, { seed: 'padelcourt1', capacity: 4 }),
  mkListing('Aspire Padel Club', 'Padel Court 2 — Indoor', 'Padel', 180, { seed: 'padelcourt2', capacity: 4 }),
  mkListing('Aspire Padel Club', 'Padel Court 3 — Panoramic', 'Padel', 220, { seed: 'padelcourt3', capacity: 4, desc: 'Glass panoramic show court — the one you want for filming your matches.' }),
  mkListing('Corniche Sports Complex', '5-a-side Football Pitch', 'Football', 250, { seed: 'dohafootball', capacity: 10 }),
  mkListing('Corniche Sports Complex', 'Tennis Court — Hard', 'Tennis', 120, { seed: 'dohatennis', capacity: 4 }),
  mkListing('Corniche Sports Complex', 'Basketball Half Court', 'Basketball', 100, { seed: 'dohabasket', capacity: 10 }),
  mkListing('Pearl Fitness & Courts', 'Squash Court A', 'Squash', 80, { seed: 'dohasquash', capacity: 2 }),
  mkListing('Pearl Fitness & Courts', 'Badminton Court', 'Badminton', 70, { seed: 'dohabadminton', capacity: 4 }),
]
let liData
if (created.listings.length) {
  liData = (await sb.from('listings').select('id,name,location_id').in('id', created.listings)).data
  console.log('listings: reusing', liData.length, 'from previous run')
} else {
  const liIns = await sb.from('listings').insert(listingRows).select('id,name,location_id')
  if (liIns.error) die('listings', liIns.error)
  liData = liIns.data
  created.listings = liData.map(l => l.id)
  console.log('listings:', liData.length)
}
const LI = Object.fromEntries(liData.map(l => [l.name, l]))

// ── 3) Membership programs ────────────────────────────────────────────────────
const mkProgram = (o) => ({
  owner_user_id: OWNER, status: 'active', approval_status: 'approved',
  show_on_marketplace: true, show_on_provider_site: true, currency: CUR,
  billing_interval: sampleProgram?.billing_interval ?? 'monthly',
  program_type: sampleProgram?.program_type ?? 'ongoing',
  session_duration_minutes: 60,
  // not-null columns with no default in this schema
  free_trial_sessions: 0, free_trial_days: 0, discount_months: 0, bonus_months: 0,
  free_trial_enabled: false, ...o,
})
const programRows = [
  mkProgram({ name: 'Pearl Gym Unlimited', category: 'gym', sport_category: 'Gym', location_id: L['Pearl Fitness & Courts'], monthly_price: 299, free_trial_enabled: true, free_trial_days: 7, sessions_per_week: 7, audience: 'both', description: 'Unlimited gym-floor access at The Pearl — cardio deck, free weights and recovery corner. Cancel anytime.', images: [img('pearlgym'), img('pearlgym2')], photo_url: img('pearlgym'), coach_name: 'Coach Karim', session_time: '06:00' }),
  mkProgram({ name: 'Padel Academy — Juniors', category: 'academy', sport_category: 'Padel', location_id: L['Aspire Padel Club'], monthly_price: 450, free_trial_enabled: true, free_trial_sessions: 1, sessions_per_week: 2, audience: 'kids', description: 'Structured junior padel pathway (ages 8–15): technique, match play and monthly internal ladder.', images: [img('padeljuniors')], photo_url: img('padeljuniors'), coach_name: 'Coach Alvaro', session_time: '16:30', capacity: 12 }),
  mkProgram({ name: 'Sunrise Yoga — Corniche', category: 'class', sport_category: 'Yoga', location_id: L['Corniche Sports Complex'], monthly_price: 200, free_trial_enabled: true, free_trial_days: 14, sessions_per_week: 3, audience: 'adults', description: 'Outdoor sunrise flow on the Corniche lawn, Mon/Wed/Sat. Mats provided — bring water and a towel.', images: [img('dohayoga')], photo_url: img('dohayoga'), coach_name: 'Nadia', session_time: '05:45', capacity: 20 }),
  mkProgram({ name: 'Football Academy U16', category: 'academy', sport_category: 'Football', location_id: L['Corniche Sports Complex'], monthly_price: 350, free_trial_enabled: true, free_trial_sessions: 1, sessions_per_week: 3, audience: 'kids', description: 'Development squad for U16s — position-specific drills, strength basics and league fixtures.', images: [img('dohafootacademy')], photo_url: img('dohafootacademy'), coach_name: 'Coach Yousef', session_time: '17:00', capacity: 18 }),
]
if (created.programs.length) {
  console.log('programs: reusing', created.programs.length, 'from previous run')
} else {
  const prIns = await sb.from('class_programs').insert(programRows).select('id,name')
  if (prIns.error) die('programs', prIns.error)
  created.programs = prIns.data.map(p => p.id)
  console.log('programs:', prIns.data.map(p => p.name).join(' · '))
}

// ── 4) Hosted events next week (Jul 6–12) + joiners ──────────────────────────
const H = { // demo customers as hosts { id, name }
  mohammed: ['d1000000-0000-0000-0000-000000000001', 'Mohammed Al Amin'],
  aisha: ['d1000000-0000-0000-0000-000000000002', 'Aisha Khalil'],
  tariq: ['d1000000-0000-0000-0000-000000000003', 'Tariq Nasser'],
  hana: ['d1000000-0000-0000-0000-000000000004', 'Hana El Sayed'],
  bilal: ['d1000000-0000-0000-0000-000000000005', 'Bilal Mahmoud'],
  reem: ['d1000000-0000-0000-0000-000000000006', 'Reem Al Muhairi'],
  samer: ['d1000000-0000-0000-0000-000000000007', 'Samer Diab'],
}
const JOINERS = Object.values(H)
const phone = i => `+97455500${String(i).padStart(3, '0')}`
const mkGame = (host, o) => ({
  host_customer_id: host[0], host_name: host[1], host_phone: phone(1), host_whatsapp: phone(1),
  city_id: CITY, country_id: COUNTRY, currency: CUR, status: 'open', skill_level: 'all',
  game_type: 'game',
  // explicit defaults — a multi-row PostgREST insert sends null for any key
  // present on one row but missing on another, tripping not-null constraints
  listing_id: null, location_id: null, game_date: null, start_time: null, end_time: null,
  custom_location_name: '', custom_location_address: '', hide_location: false,
  images: [], description: '', price_per_person: 0, max_players: 0,
  ...o,
})
const at = (name) => ({ listing_id: LI[name].id, location_id: LI[name].location_id })
const gameRows = [
  mkGame(H.mohammed, { title: 'Monday Night 5-a-side', sport: 'Football', game_date: '2026-07-06', start_time: '20:00', end_time: '21:00', price_per_person: 35, max_players: 10, ...at('5-a-side Football Pitch'), description: 'Casual but competitive Monday run. Bibs provided, bring both a white and a dark shirt. We split teams on the night.' }),
  mkGame(H.aisha, { title: 'Come Play Padel With Us 🎾', sport: 'Padel', game_date: '2026-07-07', start_time: '17:30', end_time: '19:00', price_per_person: 55, max_players: 8, ...at('Padel Court 1 — Indoor'), description: 'Two courts, rotating americano format. Racquets and balls available — mixed levels welcome!', images: [img('padelsocial', 800, 800)] }),
  mkGame(H.tariq, { title: 'Sunrise Run + Coffee', sport: 'Running', game_type: 'event', game_date: '2026-07-08', start_time: '05:30', end_time: '07:00', price_per_person: 0, max_players: 0, custom_location_name: 'Corniche — Sheraton end', custom_location_address: 'Corniche Promenade, Doha', hide_location: false, description: '6km easy pace along the water, then karak at the kiosk. All paces welcome — nobody runs alone.' }),
  mkGame(H.hana, { title: 'Tennis Doubles Evening', sport: 'Tennis', game_date: '2026-07-08', start_time: '19:00', end_time: '21:00', price_per_person: 40, max_players: 8, ...at('Tennis Court — Hard'), description: 'Rotating doubles — 30-minute sets, winners stay on. Intermediate and up, please.' }),
  mkGame(H.bilal, { title: 'Hoops at the Half Court', sport: 'Basketball', game_date: '2026-07-09', start_time: '18:30', end_time: '20:00', price_per_person: 25, max_players: 12, ...at('Basketball Half Court'), description: '3v3 king of the court. First 12 in the list play — bring your handles.' }),
  mkGame(H.reem, { title: 'Ladies Padel Social', sport: 'Padel', game_date: '2026-07-10', start_time: '10:00', end_time: '11:30', price_per_person: 50, max_players: 8, ...at('Padel Court 2 — Indoor'), description: 'Friendly ladies-only morning session. Beginners very welcome — we warm up together and rotate partners.' }),
  mkGame(H.samer, { title: 'Board Games & Karak Night', sport: 'Social', game_type: 'event', game_date: '2026-07-10', start_time: '20:30', end_time: '23:00', price_per_person: 20, max_players: 16, custom_location_name: "Samer's majlis — Al Sadd", custom_location_address: 'Al Sadd, Doha', hide_location: true, description: 'Catan, Codenames and unlimited karak. Entry covers snacks — exact address shared once you are accepted.' }),
  mkGame(H.mohammed, { title: 'Friday Squash Ladder', sport: 'Squash', game_date: '2026-07-11', start_time: '16:00', end_time: '18:00', price_per_person: 30, max_players: 6, ...at('Squash Court A'), description: 'Round-robin ladder — 15-minute games, everyone plays everyone. Court shoes required.' }),
  mkGame(H.aisha, { title: 'Badminton Rally Morning', sport: 'Badminton', game_date: '2026-07-12', start_time: '09:00', end_time: '10:30', price_per_person: 25, max_players: 8, ...at('Badminton Court'), description: 'Doubles rotation, shuttles provided. Great cardio before the weekend brunch.' }),
  mkGame(H.tariq, { title: 'Padel — Looking for a 4th', sport: 'Padel', game_date: null, start_time: '19:00', end_time: '20:30', price_per_person: 45, max_players: 4, ...at('Padel Court 3 — Panoramic'), description: 'We are 3 regulars (intermediate) looking for a steady 4th — we usually play weekday evenings. Date flexible, tell us what works.' }),
]
const gIns = await sb.from('hosted_games').insert(gameRows).select('id,title,host_customer_id')
if (gIns.error) die('games', gIns.error)
created.games = gIns.data.map(g => g.id)
console.log('events:', gIns.data.length)

// Joiners: 2-6 accepted per event (skip the host), a couple of pendings for realism.
const reqRows = []
gIns.data.forEach((g, gi) => {
  const pool = JOINERS.filter(j => j[0] !== g.host_customer_id)
  const nAcc = 2 + (gi % 5) // 2..6
  pool.slice(0, nAcc).forEach((j, i) => reqRows.push({
    game_id: g.id, joiner_customer_id: j[0], joiner_name: j[1],
    joiner_phone: phone(10 + i), joiner_whatsapp: phone(10 + i),
    joiner_message: ['Count me in!', 'Sounds fun — see you there.', 'First time, be gentle 😄', 'I will bring a friend next time.', ''][i % 5],
    status: 'accepted',
  }))
  if (gi % 3 === 0 && pool[nAcc]) reqRows.push({
    game_id: g.id, joiner_customer_id: pool[nAcc][0], joiner_name: pool[nAcc][1],
    joiner_phone: phone(30 + gi), joiner_whatsapp: phone(30 + gi), joiner_message: 'Can I join?', status: 'pending',
  })
})
const rIns = await sb.from('game_join_requests').insert(reqRows).select('id')
if (rIns.error) die('requests', rIns.error)
created.requests = rIns.data.map(r => r.id)
console.log('join requests:', rIns.data.length)

writeFileSync('scripts/seed-doha-created.json', JSON.stringify(created, null, 2))
console.log('DONE — ids saved to scripts/seed-doha-created.json')
