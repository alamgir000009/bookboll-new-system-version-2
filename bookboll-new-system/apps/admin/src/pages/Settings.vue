<script setup>
import { ref, computed, onMounted } from 'vue'
import { Settings as SettingsIcon, Bell, Shield, DollarSign, Globe as Globe2, Mail, Building2, User, FileText, AlertTriangle, Info, Check } from 'lucide-vue-next'
import { supabase } from '../lib/supabase.js'
import { pushToast } from '../lib/toast.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { changeOwnPassword, authError } from '../lib/auth.js'
import { useRouter } from 'vue-router'

const router = useRouter()
const { isSuperAdmin, isCountryAdmin, currentUser, loadUserContext } = useCurrentUser()

const isAdminView = computed(() => isSuperAdmin.value || isCountryAdmin.value)

// ── Super admin tabs ──────────────────────────────────────────
const tab = ref('platform')
const platform = ref({ commission: 20, currency: 'AED', name: 'Bookboll', tagline: 'Book your game' })
const notifications = ref({ newBooking: true, paymentFailed: true, providerSignup: true, daily: false })

// ── Policies state ────────────────────────────────────────────
const policies = ref({
  cancellation_hours: 48,
  booking_hold_minutes: 10,
  noshow_policy: 'Customer forfeits deposit. Provider receives nothing. Provider may ban the customer from the dashboard.',
  trial_cancel_policy: 'Customer must cancel before the trial period ends. First billing charge applies if not cancelled in time.',
})
const policiesSaving = ref(false)
const policiesSaved = ref(false)

async function loadPolicies() {
  const { data } = await supabase.from('platform_settings').select('key, value')
    .in('key', ['cancellation_hours', 'booking_hold_minutes', 'noshow_policy', 'trial_cancel_policy'])
  if (data) {
    for (const row of data) {
      if (row.key === 'cancellation_hours' || row.key === 'booking_hold_minutes') policies.value[row.key] = Number(row.value)
      else policies.value[row.key] = row.value
    }
  }
}

async function savePolicies() {
  policiesSaving.value = true
  const rows = [
    { key: 'cancellation_hours', value: String(policies.value.cancellation_hours), updated_at: new Date().toISOString() },
    { key: 'booking_hold_minutes', value: String(Math.max(1, Number(policies.value.booking_hold_minutes) || 10)), updated_at: new Date().toISOString() },
    { key: 'noshow_policy', value: policies.value.noshow_policy, updated_at: new Date().toISOString() },
    { key: 'trial_cancel_policy', value: policies.value.trial_cancel_policy, updated_at: new Date().toISOString() },
  ]
  const { error } = await supabase.from('platform_settings').upsert(rows, { onConflict: 'key' })
  policiesSaving.value = false
  if (error) { pushToast('Could not save policies.', 'error'); return }
  policiesSaved.value = true
  pushToast('Policies saved', 'success')
  setTimeout(() => { policiesSaved.value = false }, 2500)
}

// ── Provider admin state ──────────────────────────────────────
const providerTab = ref('profile')
const providerForm = ref({ business_name: '', description: '', phone: '', full_name: '', email: '' })
const providerNotifications = ref({ newBooking: true, cancellation: true, paymentReceived: true, lowWallet: false })
const saving = ref(false)
const saved = ref(false)

onMounted(async () => {
  if (isAdminView.value) loadPolicies()
  if (!isAdminView.value && currentUser.value) {
    providerForm.value = {
      business_name: currentUser.value.business_name || '',
      description: currentUser.value.description || '',
      phone: currentUser.value.phone || '',
      full_name: currentUser.value.full_name || '',
      email: currentUser.value.email || '',
    }
  }
})

// ── Change my password (Supabase Auth; works for the signed-in account) ──────
const pwNew = ref('')
const pwConfirm = ref('')
const pwSaving = ref(false)
async function changePassword() {
  const p = pwNew.value
  if (p.length < 10) { pushToast('Use at least 10 characters.', 'error'); return }
  if (p !== pwConfirm.value) { pushToast('The two passwords don\'t match.', 'error'); return }
  pwSaving.value = true
  const ok = await changeOwnPassword(p)
  pwSaving.value = false
  if (!ok) { pushToast(authError.value || 'Could not change the password.', 'error'); return }
  pwNew.value = ''; pwConfirm.value = ''
  pushToast('Password changed — use the new one from your next sign-in.', 'success')
}

async function saveProviderProfile() {
  if (!currentUser.value) return
  saving.value = true
  await supabase.from('admin_users').update({
    business_name: providerForm.value.business_name,
    description: providerForm.value.description,
    phone: providerForm.value.phone,
    full_name: providerForm.value.full_name,
  }).eq('id', currentUser.value.id)
  await loadUserContext()
  saving.value = false
  saved.value = true
  pushToast('Profile saved', 'success')
  setTimeout(() => { saved.value = false }, 2500)
}
</script>

<template>
  <div class="page">

    <!-- ── Super / Country Admin Settings ── -->
    <template v-if="isAdminView">
      <div class="page-header">
        <div>
          <div class="page-title">Settings</div>
          <div class="page-subtitle">Platform-wide configuration and preferences</div>
        </div>
        <button class="btn btn-primary">Save All Changes</button>
      </div>

      <div class="grid" style="grid-template-columns: 240px 1fr;">
        <div class="card" style="padding: 12px;">
          <div class="set-nav">
            <div class="set-item" :class="{active: tab==='platform'}" @click="tab='platform'"><SettingsIcon :size="16" />Platform</div>
            <div class="set-item" :class="{active: tab==='commission'}" @click="tab='commission'"><DollarSign :size="16" />Commission</div>
            <div class="set-item" :class="{active: tab==='policies'}" @click="tab='policies'"><FileText :size="16" />Policies</div>
            <div class="set-item" :class="{active: tab==='notifications'}" @click="tab='notifications'"><Bell :size="16" />Notifications</div>
            <div class="set-item" :class="{active: tab==='security'}" @click="tab='security'"><Shield :size="16" />Security</div>
            <div class="set-item" :class="{active: tab==='email'}" @click="tab='email'"><Mail :size="16" />Email Templates</div>
            <div class="set-item" :class="{active: tab==='regional'}" @click="tab='regional'"><Globe2 :size="16" />Regional</div>
          </div>
        </div>

        <div class="card" style="padding: 28px;">
          <div v-if="tab==='platform'">
            <h3>Platform Settings</h3>
            <div class="form-group"><label class="form-label">Platform Name</label><input v-model="platform.name" /></div>
            <div class="form-group"><label class="form-label">Tagline</label><input v-model="platform.tagline" /></div>
            <div class="form-group"><label class="form-label">Default Currency</label><input v-model="platform.currency" /></div>
          </div>
          <div v-if="tab==='commission'">
            <h3>Commission Configuration</h3>
            <p class="muted">Bookboll collects its commission as an online deposit at the time of booking. The customer pays the remaining balance to the provider on arrival.</p>

            <div class="info-banner" style="margin-bottom:20px;">
              <Info :size="16" />
              <div>
                <strong>Rates are managed per country.</strong> Go to
                <button class="inline-link" @click="router.push('/countries')">Countries &amp; Cities</button>
                to set default facility and program rates per country. You can also override the rate on individual listings directly in the listing form.
              </div>
            </div>

            <div class="policy-card">
              <div class="policy-card-title"><DollarSign :size="14" /> How the deposit works</div>
              <ul class="policy-list">
                <li><strong>Provider listings:</strong> Bookboll collects the commission % as an online deposit. The customer pays the remaining balance directly to the provider on arrival.</li>
                <li><strong>Bookboll-owned listings:</strong> Bookboll collects 100% of the payment online — no split is required.</li>
                <li>Commission changes only affect future bookings. Historical bookings retain the rate at time of creation.</li>
              </ul>
            </div>
          </div>

          <!-- POLICIES TAB -->
          <div v-if="tab==='policies'">
            <h3>Booking Policies</h3>
            <p class="muted">Configure cancellation, no-show, and subscription trial rules. These are applied platform-wide.</p>

            <!-- Facility Cancellation -->
            <div class="policy-card">
              <div class="policy-card-title"><AlertTriangle :size="14" /> Facility Booking Cancellation</div>
              <div class="form-group" style="margin-top:12px;">
                <label class="form-label">Cancellation window (hours before booking)</label>
                <div style="display:flex; align-items:center; gap:10px;">
                  <input type="number" min="0" max="168" v-model.number="policies.cancellation_hours" style="width:100px;" />
                  <span class="muted" style="font-size:13px;">hours — customer must cancel at least this far in advance to be eligible for a refund</span>
                </div>
              </div>
              <div class="policy-info-box">
                <Info :size="13" />
                <span>Cancellations made within <strong>{{ policies.cancellation_hours }} hours</strong> of the booking are marked as <strong>No-Show</strong> — no refund is issued.</span>
              </div>
            </div>

            <!-- Checkout hold timer -->
            <div class="policy-card" style="margin-top:14px;">
              <div class="policy-card-title"><AlertTriangle :size="14" /> Checkout Hold Timer</div>
              <div class="form-group" style="margin-top:12px;">
                <label class="form-label">Reserve selected slots for (minutes)</label>
                <div style="display:flex; align-items:center; gap:10px;">
                  <input type="number" min="1" max="60" v-model.number="policies.booking_hold_minutes" style="width:100px;" />
                  <span class="muted" style="font-size:13px;">minutes — how long a customer's chosen time slots stay reserved while they check out</span>
                </div>
              </div>
              <div class="policy-info-box">
                <Info :size="13" />
                <span>While booking, a customer's selected slots are held for <strong>{{ policies.booking_hold_minutes }} minutes</strong> so no one else can take them. If they don't finish in time, the slots are released automatically. Applies to all listings.</span>
              </div>
            </div>

            <!-- No-Show Policy -->
            <div class="policy-card" style="margin-top:14px;">
              <div class="policy-card-title"><AlertTriangle :size="14" /> No-Show Policy</div>
              <div class="form-group" style="margin-top:12px;">
                <label class="form-label">No-show policy text (shown to customers at booking)</label>
                <textarea v-model="policies.noshow_policy" rows="3"></textarea>
              </div>
              <div class="policy-info-box">
                <Info :size="13" />
                <span>When a no-show occurs: the customer's deposit is forfeited. The provider receives nothing for that slot. The provider may ban the customer from their listings via the admin dashboard.</span>
              </div>
            </div>

            <!-- Trial / Program Cancellation -->
            <div class="policy-card" style="margin-top:14px;">
              <div class="policy-card-title"><FileText :size="14" /> Membership Program / Trial Policy</div>
              <div class="form-group" style="margin-top:12px;">
                <label class="form-label">Trial cancellation policy text</label>
                <textarea v-model="policies.trial_cancel_policy" rows="3"></textarea>
              </div>
              <div class="policy-info-box">
                <Info :size="13" />
                <span>Customers must cancel <strong>before the trial period ends</strong> to avoid being charged. If the trial is not cancelled in time, the first billing cycle is charged automatically.</span>
              </div>
            </div>

            <div style="display:flex; align-items:center; gap:12px; margin-top:20px;">
              <button class="btn btn-primary" :disabled="policiesSaving" @click="savePolicies">
                {{ policiesSaving ? 'Saving…' : 'Save Policies' }}
              </button>
              <span v-if="policiesSaved" style="display:flex;align-items:center;gap:5px;color:var(--primary);font-size:13px;font-weight:600;">
                <Check :size="14" /> Saved
              </span>
            </div>
          </div>
          <div v-if="tab==='notifications'">
            <h3>Notifications</h3>
            <label class="toggle"><input type="checkbox" v-model="notifications.newBooking" />New booking alerts</label>
            <label class="toggle"><input type="checkbox" v-model="notifications.paymentFailed" />Payment failure alerts</label>
            <label class="toggle"><input type="checkbox" v-model="notifications.providerSignup" />New provider signups</label>
            <label class="toggle"><input type="checkbox" v-model="notifications.daily" />Daily summary emails</label>
          </div>
          <div v-if="tab==='security'">
            <h3>Security</h3>
            <p class="muted">Change the password for the account you're signed in with ({{ currentUser?.email }}).</p>

            <div class="policy-card">
              <div class="policy-card-title"><Shield :size="14" /> Change my password</div>
              <div class="form-group" style="margin-top:12px;">
                <label class="form-label">New password</label>
                <input type="password" v-model="pwNew" autocomplete="new-password" placeholder="At least 10 characters" style="max-width:340px;" />
              </div>
              <div class="form-group">
                <label class="form-label">Repeat new password</label>
                <input type="password" v-model="pwConfirm" autocomplete="new-password" placeholder="Same password again" style="max-width:340px;" />
              </div>
              <button class="btn btn-primary" :disabled="pwSaving || !pwNew" @click="changePassword">{{ pwSaving ? 'Changing…' : 'Change password' }}</button>
              <div class="policy-info-box" style="margin-top:12px;">
                <Info :size="13" />
                <span>Takes effect immediately — you stay signed in here, and the <strong>new</strong> password is required from your next sign-in on any device.</span>
              </div>
            </div>
          </div>
          <div v-if="tab==='email'"><h3>Email Templates</h3><p class="muted">Customize booking confirmations, receipts and reminders.</p></div>
          <div v-if="tab==='regional'"><h3>Regional</h3><p class="muted">Tax rates, timezones and language preferences per country.</p></div>
        </div>
      </div>
    </template>

    <!-- ── Provider Admin Settings ── -->
    <template v-else>
      <div class="page-header">
        <div>
          <div class="page-title">Settings</div>
          <div class="page-subtitle">Manage your business profile and preferences</div>
        </div>
        <button class="btn btn-primary" :disabled="saving" @click="saveProviderProfile">
          {{ saving ? 'Saving…' : saved ? 'Saved!' : 'Save Changes' }}
        </button>
      </div>

      <div class="grid" style="grid-template-columns: 240px 1fr;">
        <div class="card" style="padding: 12px;">
          <div class="set-nav">
            <div class="set-item" :class="{active: providerTab==='profile'}" @click="providerTab='profile'"><Building2 :size="16" />Business Profile</div>
            <div class="set-item" :class="{active: providerTab==='account'}" @click="providerTab='account'"><User :size="16" />My Account</div>
            <div class="set-item" :class="{active: providerTab==='notifications'}" @click="providerTab='notifications'"><Bell :size="16" />Notifications</div>
            <div class="set-item" :class="{active: providerTab==='security'}" @click="providerTab='security'"><Shield :size="16" />Security</div>
          </div>
        </div>

        <div class="card" style="padding: 28px;">
          <div v-if="providerTab==='profile'">
            <h3>Business Profile</h3>
            <p class="muted">This information is visible to customers on Bookboll.</p>
            <div class="form-group"><label class="form-label">Business Name</label><input v-model="providerForm.business_name" placeholder="e.g. Desert Sports Club" /></div>
            <div class="form-group"><label class="form-label">Phone Number</label><input v-model="providerForm.phone" placeholder="+971 50 000 0000" /></div>
            <div class="form-group"><label class="form-label">Description</label><textarea v-model="providerForm.description" rows="4" placeholder="Tell customers about your facilities, sports offered, and what makes you unique…"></textarea></div>
          </div>

          <div v-if="providerTab==='account'">
            <h3>My Account</h3>
            <p class="muted">Your personal details as the account owner.</p>
            <div class="form-group"><label class="form-label">Full Name</label><input v-model="providerForm.full_name" /></div>
            <div class="form-group"><label class="form-label">Email</label><input :value="providerForm.email" disabled style="opacity:0.6; cursor:not-allowed;" /></div>
            <div class="info-banner"><Shield :size="16" /><div>Email changes require contacting support to verify your identity.</div></div>
          </div>

          <div v-if="providerTab==='notifications'">
            <h3>Notifications</h3>
            <p class="muted">Choose which events trigger an email or push notification.</p>
            <label class="toggle"><input type="checkbox" v-model="providerNotifications.newBooking" />New booking received</label>
            <label class="toggle"><input type="checkbox" v-model="providerNotifications.cancellation" />Booking cancellation</label>
            <label class="toggle"><input type="checkbox" v-model="providerNotifications.paymentReceived" />Payment received</label>
            <label class="toggle"><input type="checkbox" v-model="providerNotifications.lowWallet" />Low wallet balance warning</label>
          </div>

          <div v-if="providerTab==='security'">
            <h3>Security</h3>
            <p class="muted">Keep your account safe.</p>
            <div class="form-group"><label class="form-label">Current Password</label><input type="password" placeholder="••••••••" /></div>
            <div class="form-group"><label class="form-label">New Password</label><input type="password" placeholder="••••••••" /></div>
            <div class="form-group"><label class="form-label">Confirm New Password</label><input type="password" placeholder="••••••••" /></div>
            <button class="btn btn-secondary">Update Password</button>
          </div>
        </div>
      </div>
    </template>

  </div>
</template>

<style scoped>
h3 { font-size: 17px; font-weight: 600; margin-bottom: 6px; }
.muted { color: var(--text-muted); font-size: 13px; margin-bottom: 16px; }
.set-nav { display: flex; flex-direction: column; gap: 2px; }
.set-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; font-size: 14px; color: var(--text-muted); cursor: pointer; transition: all 0.15s; }
.set-item:hover { background: var(--bg); color: var(--text); }
.set-item.active { background: #ccfbf1; color: var(--primary); font-weight: 500; }
.toggle { display: flex; align-items: center; gap: 10px; padding: 14px; border-bottom: 1px solid var(--border); font-size: 14px; cursor: pointer; }
.toggle input { width: auto; }
.info-banner { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; background: #ccfbf1; border-radius: 8px; color: #0f766e; font-size: 13px; margin-top: 12px; }
.inline-link { background: none; border: none; color: var(--primary); text-decoration: underline; cursor: pointer; font-size: 13px; padding: 0; }
.policy-card { background: #f8fafc; border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 16px 18px; }
.policy-card-title { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.policy-list { margin: 10px 0 0; padding-left: 18px; font-size: 13px; line-height: 1.7; color: var(--text-muted); }
.policy-info-box { display: flex; align-items: flex-start; gap: 8px; margin-top: 10px; padding: 10px 12px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; font-size: 12.5px; color: #1e40af; line-height: 1.5; }
@media (max-width: 900px) {
  .grid { grid-template-columns: 1fr !important; }
  /* Sidebar nav becomes a horizontally scrollable pill row (scrolls inside its card,
     no body overflow) instead of a tall stacked list pushing content down. */
  .set-nav { flex-direction: row; overflow-x: auto; -webkit-overflow-scrolling: touch; gap: 6px; padding-bottom: 4px; scrollbar-width: none; }
  .set-nav::-webkit-scrollbar { display: none; }
  .set-item { white-space: nowrap; flex: 0 0 auto; }
}

/* Phone */
@media (max-width: 600px) {
  /* Beat the cards' inline padding (28px eats too much of a 360px viewport). */
  .grid > .card { padding: 16px !important; }
  /* Full-width, comfortably tappable primary Save button. */
  .page-header .btn { width: 100%; min-height: 44px; justify-content: center; }
  /* Number input + long hint span: allow the hint to drop below the field. */
  .policy-card .form-group > div { flex-wrap: wrap; align-items: flex-start !important; }
  /* Tap targets. */
  .set-item { padding: 12px 14px; }
  .toggle { min-height: 44px; }
  .toggle input { width: 18px; height: 18px; }
}
</style>
