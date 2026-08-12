<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { isCustomer, customer, loadCustomerLoyalty, TIERS } from '../../lib/customerSession.js'
import { loadLoyaltyLedger } from '../../lib/loyalty.js'
import { Trophy, Sparkles, Lock, Check, Shield, Star, Flame, Crown, MapPin, Users, Gamepad2 } from 'lucide-vue-next'

const loy = ref(null)
const ledger = ref([])
const showAllLedger = ref(false)
const shownLedger = computed(() => showAllLedger.value ? ledger.value : ledger.value.slice(0, 3))
const loading = ref(true)
const TIER_ICON = { Rookie: Shield, Star: Star, Legend: Flame, GOAT: Crown }
function tierIcon(name) { return TIER_ICON[name] || Shield }

// How each earned entry reads, by its source key (bk: / gm: / host:).
function earnMeta(key) {
  if (String(key).startsWith('bk:')) return { icon: MapPin, label: 'Venue booking', cls: 'bk' }
  if (String(key).startsWith('gm:')) return { icon: Users, label: 'Joined a game', cls: 'gm' }
  if (String(key).startsWith('host:')) return { icon: Trophy, label: 'Hosted an event', cls: 'ho' }
  return { icon: Sparkles, label: 'Points earned', cls: 'ot' }
}
function fmtDate(d) { try { return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) } catch { return '' } }

async function load() {
  loading.value = true
  loy.value = isCustomer.value ? await loadCustomerLoyalty() : null
  ledger.value = loy.value ? await loadLoyaltyLedger(customer.value.id, { countryId: loy.value.countryId }) : []
  loading.value = false
}
onMounted(load)
watch(() => customer.value?.id, load)
</script>

<template>
  <div class="rw">
    <!-- not signed in -->
    <div v-if="!isCustomer" class="rw-signin">
      <Trophy :size="38" />
      <h2>Earn rewards as you play</h2>
      <p>Sign in to see your rank, points and how close you are to the next badge.</p>
      <router-link to="/account" class="rw-signin-btn"><Sparkles :size="16" /> Sign in</router-link>
    </div>

    <div v-else-if="loading" class="cmsg">Loading your rewards…</div>

    <template v-else-if="loy">
      <!-- Hero badge -->
      <div class="rw-hero" :style="{ '--tbg': loy.tier.bg }">
        <div class="rw-badge" :style="{ color: loy.tier.color }"><component :is="tierIcon(loy.tier.name)" :size="48" /></div>
        <div class="rw-hi">{{ (customer.full_name||'').split(' ')[0] || 'Player' }}, you're a</div>
        <div class="rw-rank">{{ loy.tier.name }}</div>
        <div class="rw-pts">{{ loy.points.toLocaleString() }} points to spend · {{ loy.lifetime.toLocaleString() }} earned all-time</div>
        <div v-if="loy.next" class="rw-prog">
          <div class="rw-bar"><div class="rw-fill" :style="{ width: loy.progress + '%' }"></div></div>
          <div class="rw-tonext">{{ (loy.next.threshold - loy.lifetime).toLocaleString() }} more points to reach {{ loy.next.name }}</div>
        </div>
        <div v-else class="rw-tonext">You've reached the highest rank. Legend!</div>
      </div>

      <!-- Earning breakdown -->
      <template v-if="ledger.length">
        <div class="rw-h2row">
          <h2 class="rw-h2">How you earned</h2>
          <button v-if="ledger.length > 3" type="button" class="rw-seeall" @click="showAllLedger = !showAllLedger">
            {{ showAllLedger ? 'Show less' : `See all (${ledger.length})` }}
          </button>
        </div>
        <div class="rw-ledger">
          <div v-for="e in shownLedger" :key="e.id" class="rw-le">
            <div class="rw-le-ico" :class="earnMeta(e.source_key).cls"><component :is="earnMeta(e.source_key).icon" :size="17" /></div>
            <div class="rw-le-mid">
              <div class="rw-le-t">{{ earnMeta(e.source_key).label }}</div>
              <div class="rw-le-d">{{ fmtDate(e.created_at) }}</div>
            </div>
            <div class="rw-le-pts">+{{ Number(e.points).toLocaleString() }}</div>
          </div>
        </div>
      </template>

      <!-- Tier ladder -->
      <h2 class="rw-h2">All ranks</h2>
      <div class="rw-ladder">
        <div v-for="t in TIERS" :key="t.name" class="rung" :class="{ cur: t.name === loy.tier.name, done: loy.lifetime >= t.threshold }" :style="{ '--tbg': t.bg }">
          <div class="rung-emoji" :style="{ color: t.color }"><component :is="tierIcon(t.name)" :size="26" /></div>
          <div class="rung-info">
            <div class="rung-name">{{ t.name }} <span v-if="t.name === loy.tier.name" class="rung-you">YOU</span></div>
            <div class="rung-th">{{ t.threshold === 0 ? 'Starting rank' : t.threshold.toLocaleString() + ' points' }}</div>
          </div>
          <Check v-if="loy.lifetime >= t.threshold" :size="20" class="rung-check" />
          <Lock v-else :size="18" class="rung-lock" />
        </div>
      </div>

      <div class="rw-how">
        <Sparkles :size="18" />
        <div>Earn points every time you book venues and join games on Bookboll. The more you play, the higher you climb!</div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.rw { max-width: 560px; margin: 0 auto; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.rw-hero { background: linear-gradient(135deg,#0f172a,#1e293b); border-radius: 22px; padding: 32px 24px; text-align: center; color: #fff; position: relative; overflow: hidden; }
.rw-hero::after { content:''; position:absolute; inset:0; background: radial-gradient(circle at 50% 0%, var(--tbg), transparent 60%); opacity:.25; }
.rw-badge { width: 96px; height: 96px; margin: 0 auto 14px; border-radius: 26px; background: var(--tbg); display: flex; align-items: center; justify-content: center; font-size: 52px; box-shadow: 0 10px 30px rgba(0,0,0,.35); position: relative; z-index: 1; }
.rw-hi { font-size: 14px; opacity: .85; position: relative; z-index: 1; }
.rw-rank { font-size: 34px; font-weight: 900; color: #fbbf24; position: relative; z-index: 1; }
.rw-pts { font-size: 13.5px; opacity: .85; margin-top: 6px; position: relative; z-index: 1; }
.rw-prog { margin-top: 18px; position: relative; z-index: 1; }
.rw-bar { height: 10px; border-radius: 999px; background: rgba(255,255,255,.15); overflow: hidden; }
.rw-fill { height: 100%; background: linear-gradient(90deg,#fbbf24,#f59e0b); border-radius: 999px; }
.rw-tonext { font-size: 13px; margin-top: 10px; opacity: .9; position: relative; z-index: 1; }
.rw-h2 { font-size: 19px; font-weight: 800; color: #0f172a; margin: 26px 0 14px; }
.rw-h2row { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin: 26px 0 14px; }
.rw-h2row .rw-h2 { margin: 0; }
.rw-seeall { background: none; border: none; color: var(--brand); font: inherit; font-weight: 800; font-size: 13.5px; cursor: pointer; flex-shrink: 0; padding: 2px 4px; }
.rw-seeall:hover { text-decoration: underline; }
.rw-ladder { display: flex; flex-direction: column; gap: 10px; }
.rung { display: flex; align-items: center; gap: 14px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 16px; padding: 14px 16px; }
.rung.cur { border-color: #0f172a; box-shadow: 0 4px 16px rgba(15,23,42,.1); }
.rung.done .rung-emoji { filter: none; }
.rung:not(.done) { opacity: .65; }
.rung-emoji { width: 52px; height: 52px; border-radius: 14px; background: var(--tbg); display: flex; align-items: center; justify-content: center; font-size: 28px; }
.rung-info { flex: 1; }
.rung-name { font-size: 16px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px; }
.rung-you { font-size: 10.5px; font-weight: 800; background: #0f172a; color: #fff; padding: 2px 7px; border-radius: 999px; }
.rung-th { font-size: 13px; color: var(--text-muted, #64748b); }
.rung-check { color: #16a34a; }
.rung-lock { color: #cbd5e1; }
.rw-ledger { display: flex; flex-direction: column; gap: 8px; }
.rw-le { display: flex; align-items: center; gap: 12px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 11px 14px; }
.rw-le-ico { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.rw-le-ico.bk { background: #eff6ff; color: #2563eb; }
.rw-le-ico.gm { background: #f0fdfa; color: #0d9488; }
.rw-le-ico.ho { background: #fffbeb; color: #d97706; }
.rw-le-ico.ot { background: #f1f5f9; color: #64748b; }
.rw-le-mid { flex: 1; min-width: 0; }
.rw-le-t { font-size: 14.5px; font-weight: 700; color: #0f172a; }
.rw-le-d { font-size: 12.5px; color: var(--text-muted, #64748b); margin-top: 2px; }
.rw-le-pts { font-size: 15px; font-weight: 900; color: #16a34a; flex-shrink: 0; }
.rw-how { display: flex; gap: 12px; background: color-mix(in srgb, var(--brand) 8%, white); border: 1.5px solid #99f6e4; border-radius: 14px; padding: 16px; margin-top: 22px; color: var(--brand); }
.rw-how div { font-size: 13.5px; color: #334155; line-height: 1.5; }
.rw-signin { text-align: center; padding: 50px 20px; color: var(--brand); }
.rw-signin h2 { font-size: 22px; color: #0f172a; margin: 14px 0 6px; }
.rw-signin p { font-size: 14.5px; color: var(--text-muted, #64748b); margin: 0 0 20px; }
.rw-signin-btn { display: inline-flex; align-items: center; gap: 7px; background: var(--brand); color: #fff; font-weight: 800; padding: 13px 24px; border-radius: 999px; text-decoration: none; }

@media (max-width: 760px) {
  /* keep the dark hero/cards off the screen edges without doubling the .cust gutter */
  .rw { padding: 0 14px; }

  /* Signed-out: lift the Sign-in CTA into the first viewport */
  .rw-signin { padding: 32px 18px; }
  .rw-signin h2 { font-size: 19px; margin: 12px 0 6px; }
  .rw-signin p { font-size: 14px; margin: 0 0 18px; }
  .rw-signin-btn { min-height: 44px; }

  /* Signed-in hero: shrink so points + progress land above the fold */
  .rw-hero { padding: 24px 18px; border-radius: 18px; }
  .rw-badge { width: 72px; height: 72px; border-radius: 20px; margin: 0 auto 10px; }
  .rw-badge svg { width: 36px; height: 36px; }
  .rw-rank { font-size: 24px; line-height: 1.2; }
  .rw-hi { font-size: 13px; }
  .rw-pts { font-size: 13px; line-height: 1.45; margin-top: 8px; }

  /* Ladder: denser rungs so more ranks are visible at a glance */
  .rw-h2 { font-size: 17px; margin: 20px 0 10px; }
  .rw-h2row { margin: 20px 0 10px; }
  .rung { padding: 12px 13px; gap: 12px; border-radius: 14px; }
  .rung-emoji { width: 44px; height: 44px; border-radius: 12px; }
  .rung-you { font-size: 11px; }

  /* Reclaim space at the bottom explainer */
  .rw-how { padding: 14px; margin-top: 18px; }
  .rw-how div { font-size: 13px; }
}
</style>
