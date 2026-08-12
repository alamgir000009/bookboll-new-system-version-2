<script setup>
/**
 * CustomerDetailModal.vue — the ONE shared customer details modal.
 * Mounted once in App.vue; opened anywhere via openCustomer(id).
 *
 * Shows full details + stats (bookings, spend by currency, per-country loyalty
 * for Bookboll-side accounts) and quick actions: Email, WhatsApp, View History,
 * Block/Unblock, Edit. Provider roles get scoped stats, no loyalty, and Edit/
 * granular Block hand off to the Customers page.
 */
import { ref, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { supabase } from '../lib/supabase.js'
import { customerModalId, closeCustomer, requestEditCustomerId, requestBlockCustomerId } from '../lib/customerModal.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useJurisdiction } from '../lib/jurisdiction.js'
import { askConfirm } from '../lib/confirm.js'
import { pushToast } from '../lib/toast.js'
import CustomerHistoryModal from './CustomerHistoryModal.vue'
import { X, Mail, MessageCircle, History, Ban, CheckCircle, Pencil, MapPin, Award, Calendar, User, Tag, Loader, Plus, Trash2, Check } from 'lucide-vue-next'
import WhatsappIcon from './WhatsappIcon.vue'

const router = useRouter()
const { role, isSuperAdmin, isCountryAdmin, currentOwnerUserId } = useCurrentUser()
// Platform roles ONLY — a provider staff row missing its owner link must never
// be promoted to Bookboll-side (that hole leaked every business's customers).
const isBookbollSide = computed(() => isSuperAdmin.value || isCountryAdmin.value || role.value === 'platform_staff')
const canSeeLoyalty  = computed(() => isBookbollSide.value)

const open       = computed(() => !!customerModalId.value)
const loading    = ref(false)
const customer   = ref(null)
const bookings   = ref([])
const locations  = ref([])
const cities     = ref([])
const countries  = ref([])
const loyalty    = ref([])
const programs   = ref([])
const tiers      = ref([])
const historyId  = ref(null)

watch(customerModalId, async (id) => {
  if (!id) return
  loading.value = true
  customer.value = null
  historyId.value = null
  editingCountryId.value = null
  adding.value = false
  const [cRes, bRes, loRes, ciRes, coRes] = await Promise.all([
    supabase.from('customers').select('*').eq('id', id).maybeSingle(),
    supabase.from('bookings').select('id, location_id, booking_date, total, booking_status').eq('customer_id', id).neq('booking_status', 'cancelled'),
    supabase.from('locations').select('id, name, city_id, owner_user_id'),
    supabase.from('cities').select('id, country_id'),
    supabase.from('countries').select('id, name, currency_code'),
  ])
  customer.value  = cRes.data
  bookings.value  = bRes.data || []
  locations.value = loRes.data || []
  cities.value    = ciRes.data || []
  countries.value = coRes.data || []
  if (canSeeLoyalty.value) {
    const [clRes, lpRes, ltRes] = await Promise.all([
      supabase.from('customer_loyalty').select('*').eq('customer_id', id),
      supabase.from('loyalty_programs').select('id, country_id'),
      supabase.from('loyalty_tiers').select('id, program_id, name, threshold'),
    ])
    loyalty.value = clRes.data || []; programs.value = lpRes.data || []; tiers.value = ltRes.data || []
  } else { loyalty.value = []; programs.value = []; tiers.value = [] }
  loading.value = false
})

// ── Stats (scoped to the provider's own venues for non-Bookboll roles) ──
const { allowedLocationIds } = useJurisdiction({ locations, cities })
const ownedLocationIds = computed(() => {
  // Platform: null = super admin (all), Set = country_admin/platform_staff jurisdiction.
  if (isBookbollSide.value) return allowedLocationIds.value
  // Provider: own venues only; no owner link → empty set (sees nothing extra).
  return new Set(locations.value.filter(l => l.owner_user_id === currentOwnerUserId.value).map(l => l.id))
})
const scopedBookings = computed(() =>
  ownedLocationIds.value ? bookings.value.filter(b => ownedLocationIds.value.has(b.location_id)) : bookings.value
)
function currencyForLoc(locId) {
  const loc = locations.value.find(l => l.id === locId)
  const city = cities.value.find(c => c.id === loc?.city_id)
  return countries.value.find(c => c.id === city?.country_id)?.currency_code || 'AED'
}
const spendByCurrency = computed(() => {
  const m = {}
  for (const b of scopedBookings.value) { const amt = Number(b.total || 0); if (!amt) continue; const cur = currencyForLoc(b.location_id); m[cur] = (m[cur] || 0) + amt }
  return Object.entries(m).sort((a, b) => b[1] - a[1])
})
const totalBookings = computed(() => scopedBookings.value.length)
const lastBooking = computed(() => {
  const d = scopedBookings.value.map(b => b.booking_date).filter(Boolean).sort()
  return d.length ? d[d.length - 1] : null
})

// ── Loyalty (per country, derived tier) ──
const PRESET_TIERS = [{ name: 'Rookie', threshold: 0 }, { name: 'Star', threshold: 1000 }, { name: 'Legend', threshold: 5000 }, { name: 'GOAT', threshold: 15000 }]
function tiersForCountry(cid) { const p = programs.value.find(x => x.country_id === cid); let t = p ? tiers.value.filter(x => x.program_id === p.id) : []; if (!t.length) t = PRESET_TIERS; return [...t].sort((a, b) => Number(b.threshold) - Number(a.threshold)) }
function tierForPoints(cid, life) { const pts = Number(life || 0); return tiersForCountry(cid).find(t => pts >= Number(t.threshold || 0))?.name || 'Rookie' }
const loyaltyRows = computed(() => loyalty.value.map(r => ({
  country_id: r.country_id,
  countryName: countries.value.find(c => c.id === r.country_id)?.name || '—',
  points: Number(r.points || 0), lifetime: Number(r.lifetime_points || 0),
  tier: tierForPoints(r.country_id, r.lifetime_points),
})).sort((a, b) => b.points - a.points))
const TIER_BADGE = { Rookie: 'badge-neutral', Star: 'badge-info', Legend: 'badge-warning', GOAT: 'badge-success' }

// ── Loyalty editor (Bookboll-side only) — add / edit / remove per-country points ──
const editingCountryId = ref(null)
const editPoints       = ref(0)
const editLifetime     = ref(0)
const adding           = ref(false)
const newCountryId     = ref(null)
const newPoints        = ref(0)
const newLifetime      = ref(0)
const savingLoyalty    = ref(false)

const availableLoyaltyCountries = computed(() => {
  const used = new Set(loyalty.value.map(r => r.country_id))
  return countries.value.filter(c => !used.has(c.id))
})

async function reloadLoyalty() {
  if (!canSeeLoyalty.value || !customer.value) { loyalty.value = []; return }
  const { data } = await supabase.from('customer_loyalty').select('*').eq('customer_id', customer.value.id)
  loyalty.value = data || []
}

function startEditLoyalty(r) {
  adding.value = false
  editingCountryId.value = r.country_id
  editPoints.value = r.points
  editLifetime.value = r.lifetime
}
function cancelEditLoyalty() { editingCountryId.value = null }

async function saveEditLoyalty() {
  const cid = editingCountryId.value
  if (cid == null || !customer.value) return
  const pts = Math.max(0, Math.round(Number(editPoints.value) || 0))
  let life  = Math.max(0, Math.round(Number(editLifetime.value) || 0))
  if (life < pts) life = pts   // lifetime (earned) can't be below the current balance
  savingLoyalty.value = true
  const { error } = await supabase.from('customer_loyalty')
    .upsert({ customer_id: customer.value.id, country_id: cid, points: pts, lifetime_points: life }, { onConflict: 'customer_id,country_id' })
  savingLoyalty.value = false
  if (error) { pushToast('Could not save loyalty points.', 'error'); return }
  pushToast('Loyalty points updated', 'success')
  editingCountryId.value = null
  await reloadLoyalty()
}

function startAddLoyalty() {
  editingCountryId.value = null
  adding.value = true
  newCountryId.value = availableLoyaltyCountries.value[0]?.id ?? null
  newPoints.value = 0
  newLifetime.value = 0
}
async function addLoyalty() {
  if (newCountryId.value == null) { pushToast('Pick a country first.', 'error'); return }
  if (!customer.value) return
  const pts = Math.max(0, Math.round(Number(newPoints.value) || 0))
  let life  = Math.max(0, Math.round(Number(newLifetime.value) || 0))
  if (life < pts) life = pts
  savingLoyalty.value = true
  const { error } = await supabase.from('customer_loyalty')
    .upsert({ customer_id: customer.value.id, country_id: newCountryId.value, points: pts, lifetime_points: life }, { onConflict: 'customer_id,country_id' })
  savingLoyalty.value = false
  if (error) { pushToast('Could not add loyalty points.', 'error'); return }
  pushToast('Loyalty points added', 'success')
  adding.value = false
  await reloadLoyalty()
}

async function removeLoyalty(r) {
  if (!customer.value) return
  if (!(await askConfirm({ title: 'Remove loyalty points?', message: `Remove all ${r.countryName} loyalty points for ${customer.value.full_name || 'this customer'}? This cannot be undone.`, confirmText: 'Remove' }))) return
  savingLoyalty.value = true
  const { error } = await supabase.from('customer_loyalty').delete().eq('customer_id', customer.value.id).eq('country_id', r.country_id)
  savingLoyalty.value = false
  if (error) { pushToast('Could not remove loyalty.', 'error'); return }
  pushToast(`${r.countryName} loyalty removed`, 'success')
  await reloadLoyalty()
}

// ── Tags + blocks (encoded in the tags field, mirrors Customers.vue) ──
const parsed = computed(() => {
  const raw = (customer.value?.tags || '').split(',').map(t => t.trim()).filter(Boolean)
  const tags = [], blks = []
  for (const e of raw) {
    if (e.startsWith('⛔')) { const [type, targetId, ...rest] = e.slice(1).split('|'); blks.push({ type, targetId: targetId || null, reason: rest.join('|') }) }
    else tags.push(e)
  }
  return { tags, blks }
})
function encodeTags(tags, blks) { return [...tags, ...blks.map(b => `⛔${b.type}|${b.targetId || ''}|${b.reason}`)].join(', ') }
const isPlatformBlocked = computed(() => customer.value?.status === 'blocked' || parsed.value.blks.some(b => b.type === 'platform'))
const blockSummary = computed(() => {
  const b = parsed.value.blks
  if (!b.length && customer.value?.status !== 'blocked') return ''
  if (isPlatformBlocked.value) return 'Banned platform-wide'
  const n = b.length
  return `Blocked from ${n} ${n === 1 ? 'venue/program' : 'venues/programs'}`
})

function fmtDate(d) { return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : '—' }
function countryName(id) { return countries.value.find(c => c.id === id)?.name || '—' }
function initials(n) { return (n || '?').split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase() }
const statusClass = computed(() => ({ active: 'badge-success', inactive: 'badge-neutral', blocked: 'badge-danger' }[customer.value?.status] || 'badge-neutral'))

// ── Actions ──
function emailCustomer() { if (!customer.value?.email) { pushToast('No email on file.', 'error'); return } window.location.href = `mailto:${encodeURIComponent(customer.value.email)}` }
function whatsappCustomer() { if (!customer.value?.phone) { pushToast('No phone number on file.', 'error'); return } window.open(`https://wa.me/${customer.value.phone.replace(/\D/g, '')}`, '_blank') }

function editCustomer() {
  requestEditCustomerId.value = customer.value.id
  closeCustomer()
  router.push('/customers')
}

async function refreshCustomer() {
  const { data } = await supabase.from('customers').select('*').eq('id', customer.value.id).maybeSingle()
  if (data) customer.value = data
}
async function toggleBlock() {
  const c = customer.value; if (!c) return
  // Bookboll staff un-banning a platform-blocked customer → inline (quick reverse).
  if (isBookbollSide.value && isPlatformBlocked.value) {
    const { tags, blks } = parsed.value
    const { error } = await supabase.from('customers').update({ tags: encodeTags(tags, blks.filter(b => b.type !== 'platform')), status: 'active' }).eq('id', c.id)
    if (error) { pushToast('Could not unblock.', 'error'); return }
    pushToast(`${c.full_name || 'Customer'} unblocked`, 'success')
    await refreshCustomer()
    return
  }
  // Blocking: everyone uses the role-scoped Block dialog on the Customers page — providers
  // can only target their own locations/listings/programs; only Bookboll staff see the
  // "Ban from Entire Platform" option there.
  requestBlockCustomerId.value = c.id
  closeCustomer()
  router.push('/customers')
}
</script>

<template>
  <Teleport to="body">
    <Transition name="cdm-fade">
      <div v-if="open" class="cdm-backdrop" @click.self="closeCustomer">
        <div class="cdm-panel">
          <div class="cdm-header">
            <div class="cdm-title">Customer</div>
            <button class="btn-icon" @click="closeCustomer"><X :size="18" /></button>
          </div>

          <div v-if="loading || !customer" class="cdm-loading">
            <Loader :size="22" class="spin" /><span>Loading…</span>
          </div>

          <div v-else class="cdm-body">
            <!-- Identity -->
            <div class="cdm-identity">
              <div class="cdm-avatar">{{ initials(customer.full_name) }}</div>
              <div style="flex:1;min-width:0;">
                <div class="cdm-name">{{ customer.full_name || 'Unnamed customer' }}</div>
                <div class="cdm-status-row">
                  <span class="badge" :class="statusClass">{{ customer.status }}</span>
                  <span v-if="blockSummary" class="cdm-block-note"><Ban :size="12" />{{ blockSummary }}</span>
                </div>
              </div>
            </div>

            <!-- Quick actions -->
            <div class="cdm-actions">
              <button class="cdm-action" @click="emailCustomer"><Mail :size="16" />Email</button>
              <button class="cdm-action" @click="whatsappCustomer" title="Message on WhatsApp"><WhatsappIcon :size="16" />Message</button>
              <button class="cdm-action" @click="historyId = customer.id"><History :size="16" />History</button>
              <button class="cdm-action" @click="toggleBlock">
                <component :is="(isBookbollSide && isPlatformBlocked) ? CheckCircle : Ban" :size="16" />{{ (isBookbollSide && isPlatformBlocked) ? 'Unblock' : 'Block' }}
              </button>
              <button v-if="isBookbollSide" class="cdm-action" @click="editCustomer"><Pencil :size="16" />Edit</button>
            </div>

            <!-- Contact -->
            <div class="cdm-section">
              <div class="cdm-row"><span class="cdm-label"><Mail :size="13" />Email</span><span class="cdm-val">{{ customer.email || '—' }}</span></div>
              <div class="cdm-row"><span class="cdm-label"><MessageCircle :size="13" />Phone</span><span class="cdm-val">{{ customer.phone || '—' }}</span></div>
              <div class="cdm-row"><span class="cdm-label"><User :size="13" />Gender</span><span class="cdm-val">{{ customer.gender || '—' }}</span></div>
              <div class="cdm-row"><span class="cdm-label"><MapPin :size="13" />Home country</span><span class="cdm-val">{{ countryName(customer.country_id) }}</span></div>
              <div class="cdm-row"><span class="cdm-label"><Calendar :size="13" />Joined</span><span class="cdm-val">{{ fmtDate(customer.created_at?.slice(0,10)) }}</span></div>
            </div>

            <!-- Activity -->
            <div class="cdm-section">
              <div class="cdm-section-title">{{ isBookbollSide ? 'Activity' : 'Activity at your business' }}</div>
              <div class="cdm-stats">
                <div class="cdm-stat"><div class="cdm-stat-val">{{ totalBookings }}</div><div class="cdm-stat-lbl">Bookings</div></div>
                <div class="cdm-stat"><div class="cdm-stat-val">{{ fmtDate(lastBooking) }}</div><div class="cdm-stat-lbl">Last booking</div></div>
              </div>
              <div v-if="spendByCurrency.length" class="cdm-spend">
                <span class="cdm-label" style="margin-bottom:4px;">Spend</span>
                <div class="cdm-spend-list">
                  <span v-for="[cur, amt] in spendByCurrency" :key="cur" class="cdm-spend-chip">{{ cur }} {{ amt.toLocaleString() }}</span>
                </div>
              </div>
            </div>

            <!-- Loyalty (Bookboll-side only) — view + add/edit/remove -->
            <div v-if="canSeeLoyalty" class="cdm-section">
              <div class="cdm-section-title"><Award :size="14" style="vertical-align:-2px;" /> Loyalty by country</div>

              <p v-if="!loyaltyRows.length && !adding" class="cdm-loyalty-empty">No loyalty points yet for this customer.</p>

              <div v-for="r in loyaltyRows" :key="r.country_id" class="cdm-loyalty-row">
                <template v-if="editingCountryId !== r.country_id">
                  <span class="cdm-country">{{ r.countryName }}</span>
                  <span class="badge" :class="TIER_BADGE[r.tier]">{{ r.tier }}</span>
                  <span class="cdm-pts">{{ r.points.toLocaleString() }} pts</span>
                  <span class="cdm-life" :title="r.lifetime.toLocaleString() + ' lifetime points (sets tier)'">· {{ r.lifetime.toLocaleString() }} lifetime</span>
                  <button class="cdm-mini" title="Edit points" @click="startEditLoyalty(r)"><Pencil :size="13" /></button>
                  <button class="cdm-mini danger" title="Remove" :disabled="savingLoyalty" @click="removeLoyalty(r)"><Trash2 :size="13" /></button>
                </template>
                <template v-else>
                  <span class="cdm-country">{{ r.countryName }}</span>
                  <label class="cdm-ed-field" title="Current redeemable balance"><span>Balance</span><input type="number" min="0" v-model.number="editPoints" /></label>
                  <label class="cdm-ed-field" title="Lifetime earned points — determines tier"><span>Lifetime</span><input type="number" min="0" v-model.number="editLifetime" /></label>
                  <button class="cdm-mini ok" title="Save" :disabled="savingLoyalty" @click="saveEditLoyalty"><Check :size="13" /></button>
                  <button class="cdm-mini" title="Cancel" @click="cancelEditLoyalty"><X :size="13" /></button>
                </template>
              </div>

              <!-- Add a country -->
              <div v-if="adding" class="cdm-loyalty-row cdm-loyalty-add">
                <select v-model="newCountryId" class="cdm-ed-select">
                  <option :value="null" disabled>Select country…</option>
                  <option v-for="c in availableLoyaltyCountries" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <label class="cdm-ed-field" title="Current redeemable balance"><span>Balance</span><input type="number" min="0" v-model.number="newPoints" /></label>
                <label class="cdm-ed-field" title="Lifetime earned points — determines tier"><span>Lifetime</span><input type="number" min="0" v-model.number="newLifetime" /></label>
                <button class="cdm-mini ok" title="Add" :disabled="savingLoyalty || newCountryId == null" @click="addLoyalty"><Check :size="13" /></button>
                <button class="cdm-mini" title="Cancel" @click="adding = false"><X :size="13" /></button>
              </div>

              <button v-if="!adding && availableLoyaltyCountries.length" class="cdm-add-btn" @click="startAddLoyalty"><Plus :size="14" /> Add country points</button>
            </div>

            <!-- Tags -->
            <div v-if="parsed.tags.length" class="cdm-section">
              <div class="cdm-section-title"><Tag :size="13" style="vertical-align:-2px;" /> Tags</div>
              <div class="cdm-tags"><span v-for="t in parsed.tags" :key="t" class="cdm-tag">{{ t }}</span></div>
            </div>
          </div>
        </div>

        <!-- Nested booking history -->
        <CustomerHistoryModal :customerId="historyId" @close="historyId = null" @viewBooking="historyId = null" />
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.cdm-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.5); display: flex; align-items: flex-start; justify-content: center; z-index: 3600; padding: 40px 20px; overflow-y: auto; }
.cdm-panel { background: var(--surface, #ffffff); border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 24px 60px rgba(15,23,42,.28); }
.cdm-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px; border-bottom: 1px solid var(--border); }
.cdm-title { font-size: 16px; font-weight: 700; color: #0f172a; }
.cdm-loading { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 50px; color: var(--text-muted); }
.cdm-body { padding: 18px 20px 22px; }

.cdm-identity { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
.cdm-avatar { width: 52px; height: 52px; border-radius: 50%; background: linear-gradient(135deg,#0f766e,#0891b2); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 18px; flex-shrink: 0; }
.cdm-name { font-size: 18px; font-weight: 700; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cdm-status-row { display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap; }
.cdm-block-note { display: inline-flex; align-items: center; gap: 3px; font-size: 11.5px; font-weight: 600; color: #dc2626; }

.cdm-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
.cdm-action { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface, #ffffff); font-size: 13px; font-weight: 600; color: #334155; cursor: pointer; transition: all .12s; }
.cdm-action:hover { background: #f8fafc; border-color: #cbd5e1; }

.cdm-section { padding: 14px 0; border-top: 1px solid var(--border); }
.cdm-section:first-of-type { border-top: none; padding-top: 0; }
.cdm-section-title { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 10px; }
.cdm-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 5px 0; font-size: 13.5px; }
.cdm-label { display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted); }
.cdm-val { color: #0f172a; font-weight: 500; text-align: right; word-break: break-word; }

.cdm-stats { display: flex; gap: 10px; margin-bottom: 10px; }
.cdm-stat { flex: 1; background: var(--bg-subtle,#f8fafc); border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; }
.cdm-stat-val { font-size: 16px; font-weight: 700; color: #0f172a; }
.cdm-stat-lbl { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
.cdm-spend { display: flex; flex-direction: column; }
.cdm-spend-list { display: flex; flex-wrap: wrap; gap: 6px; }
.cdm-spend-chip { font-size: 12.5px; font-weight: 600; background: var(--bg-subtle,#f1f5f9); border: 1px solid var(--border); border-radius: 16px; padding: 3px 10px; color: #0f172a; }

.cdm-loyalty-row { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; padding: 5px 0; font-size: 13px; border-bottom: 1px dashed var(--border); }
.cdm-loyalty-row:last-of-type { border-bottom: none; }
.cdm-country { flex: 1; min-width: 90px; color: #0f172a; font-weight: 500; }
.cdm-pts { color: #0f172a; font-weight: 600; }
.cdm-life { color: var(--text-muted); font-size: 12px; }
.cdm-loyalty-empty { font-size: 13px; color: var(--text-muted); margin: 0 0 8px; }

.cdm-mini { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 7px; border: 1px solid var(--border); background: var(--surface, #ffffff); color: var(--text-muted, #64748b); cursor: pointer; transition: all .12s; }
.cdm-mini:hover { background: #f8fafc; color: #0f172a; }
.cdm-mini.ok { background: #ecfdf5; border-color: #a7f3d0; color: #059669; }
.cdm-mini.ok:hover { background: #d1fae5; }
.cdm-mini.danger:hover { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
.cdm-mini:disabled { opacity: .5; cursor: not-allowed; }

.cdm-ed-field { display: inline-flex; flex-direction: column; gap: 2px; }
.cdm-ed-field span { font-size: 10px; text-transform: uppercase; letter-spacing: .03em; color: var(--text-muted); font-weight: 600; }
.cdm-ed-field input { width: 84px; padding: 5px 7px; border: 1px solid var(--border); border-radius: 7px; font-size: 13px; }
.cdm-ed-select { flex: 1; min-width: 120px; padding: 6px 8px; border: 1px solid var(--border); border-radius: 7px; font-size: 13px; background: var(--surface, #ffffff); }
.cdm-loyalty-add { background: var(--bg-subtle,#f8fafc); border: 1px dashed #cbd5e1; border-radius: 10px; padding: 10px; margin-top: 8px; }
.cdm-add-btn { display: inline-flex; align-items: center; gap: 5px; margin-top: 10px; padding: 7px 12px; border: 1px dashed #cbd5e1; border-radius: 8px; background: var(--surface, #ffffff); font-size: 12.5px; font-weight: 600; color: #0f766e; cursor: pointer; transition: all .12s; }
.cdm-add-btn:hover { background: #f0fdfa; border-color: #5eead4; }

.cdm-tags { display: flex; flex-wrap: wrap; gap: 6px; }
.cdm-tag { font-size: 12px; background: var(--bg-subtle,#f1f5f9); border-radius: 6px; padding: 3px 8px; color: #475569; }

.badge-danger { background: #fee2e2; color: #991b1b; }

.cdm-fade-enter-active, .cdm-fade-leave-active { transition: opacity .18s ease; }
.cdm-fade-enter-from, .cdm-fade-leave-to { opacity: 0; }

/* ── Phone: modal → bottom sheet (360px-safe) ── */
@media (max-width: 600px) {
  .cdm-backdrop { padding: 0; align-items: flex-end; }
  .cdm-panel { max-width: none; border-radius: 16px 16px 0 0; max-height: 92dvh; display: flex; flex-direction: column; }
  .cdm-header { flex-shrink: 0; }
  .cdm-body { overflow-y: auto; flex: 1; min-height: 0; padding-bottom: calc(22px + env(safe-area-inset-bottom, 0px)); }

  /* Contact rows: let long values (emails) take a full line, left-aligned */
  .cdm-row { flex-wrap: wrap; }
  .cdm-val { text-align: left; flex: 1 1 100%; }

  /* Quick actions: 2-up grid-like wrap, ≥44px touch targets */
  .cdm-action { padding: 11px 12px; flex: 1 1 calc(50% - 4px); justify-content: center; min-height: 44px; }
  .cdm-mini { width: 38px; height: 38px; }
  .cdm-add-btn { min-height: 44px; }

  /* Loyalty editor: 16px controls (no iOS zoom), country select on its own line,
     number fields share a line with the mini buttons */
  .cdm-loyalty-row { row-gap: 10px; }
  .cdm-ed-select { flex: 1 1 100%; min-width: 0; padding: 9px 10px; font-size: 16px; }
  .cdm-ed-field { flex: 1 1 80px; min-width: 0; }
  .cdm-ed-field input { width: 100%; min-width: 0; padding: 9px 10px; font-size: 16px; }
}
</style>
