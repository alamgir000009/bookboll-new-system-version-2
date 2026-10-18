<script setup>
/**
 * AccountPicker — shown right after dashboard sign-in when the signed-in email is
 * linked to MORE THAN ONE admin_users row (multiple businesses, or a team membership
 * plus an own business). The host picks which business/role to open; the choice is
 * remembered (bb_active_user) so next time it resumes automatically.
 */
import { computed } from 'vue'
import { accountChoices, chooseAdminAccount, signOut, authUser } from '../lib/auth.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { Building2, ShieldCheck, Users, LogOut, ChevronRight } from 'lucide-vue-next'
import BrandMark from '../components/BrandMark.vue'
import { adminBrand, adminLogoSquareStyle } from '../lib/adminBrand.js'

const { allUsers } = useCurrentUser()

const ROLE_LABEL = {
  super_admin: 'Super Admin', country_admin: 'Country Admin', provider_admin: 'Owner',
  facility_admin: 'Manager', senior_employee: 'Senior staff', junior_employee: 'Staff',
  guard: 'Guard', staff: 'Staff',
}
function businessName(u) {
  if (u.role === 'super_admin') return 'Bookboll HQ'
  if (u.role === 'country_admin') return 'Bookboll — Country admin'
  if (u.role === 'provider_admin') return u.business_name || u.full_name || 'My business'
  if (u.owner_user_id) {
    const owner = (allUsers.value || []).find(x => x.id === u.owner_user_id)
    return owner?.business_name || 'A business'
  }
  return u.business_name || 'Business'
}
function roleLabel(u) { return ROLE_LABEL[u.role] || 'Member' }
function initials(name) { return (name || '?').trim().split(/\s+/).map(s => s[0]).slice(0, 2).join('').toUpperCase() }

const accounts = computed(() => accountChoices.value)
function open(id) { chooseAdminAccount(id) }
</script>

<template>
  <div class="ap-shell">
    <div class="ap-card">
      <div class="ap-brand"><div class="ap-logo" :style="adminBrand.logo ? { backgroundImage: `url(${adminBrand.logo})`, backgroundSize: 'cover', backgroundPosition: 'center' } : adminLogoSquareStyle"><BrandMark v-if="!adminBrand.logo" /></div><div><div class="ap-brand-name">Bookboll</div><div class="ap-brand-sub">Admin Portal</div></div></div>
      <h1 class="ap-title">Choose a business</h1>
      <p class="ap-sub">Your account <b>{{ authUser?.email }}</b> manages more than one business. Pick which one to open.</p>

      <ul class="ap-list">
        <li v-for="a in accounts" :key="a.id">
          <button type="button" class="ap-item" @click="open(a.id)">
            <span class="ap-ava">{{ initials(businessName(a)) }}</span>
            <span class="ap-main">
              <span class="ap-name">{{ businessName(a) }}</span>
              <span class="ap-role">
                <component :is="a.role === 'super_admin' || a.role === 'country_admin' ? ShieldCheck : a.role === 'provider_admin' ? Building2 : Users" :size="13" />
                {{ roleLabel(a) }}
              </span>
            </span>
            <ChevronRight :size="18" class="ap-go" />
          </button>
        </li>
      </ul>

      <button type="button" class="ap-signout" @click="signOut"><LogOut :size="15" /> Sign in with a different account</button>
    </div>
  </div>
</template>

<style scoped>
.ap-shell { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #0f766e22 100%); padding: 24px; }
.ap-card { width: 100%; max-width: 440px; background: var(--surface, #ffffff); border-radius: 20px; padding: 32px 32px 24px; box-shadow: 0 24px 60px rgba(0,0,0,.35); }
.ap-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; }
.ap-logo { width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #14b8a6, #0f766e); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px; }
.ap-brand-name { font-size: 18px; font-weight: 800; color: var(--text, #0f172a); }
.ap-brand-sub { font-size: 12px; color: var(--text-muted, #64748b); }
.ap-title { font-size: 22px; font-weight: 800; color: var(--text, #0f172a); margin: 0 0 4px; }
.ap-sub { font-size: 13.5px; color: var(--text-muted, #64748b); margin: 0 0 20px; line-height: 1.5; }
.ap-sub b { color: var(--text, #0f172a); }
.ap-list { list-style: none; margin: 0 0 16px; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.ap-item { width: 100%; display: flex; align-items: center; gap: 13px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 14px; padding: 13px 15px; cursor: pointer; text-align: left; transition: border-color .12s, box-shadow .12s, transform .12s; }
.ap-item:hover { border-color: #14b8a6; box-shadow: 0 8px 22px rgba(15,23,42,.08); transform: translateY(-1px); }
.ap-ava { flex-shrink: 0; width: 42px; height: 42px; border-radius: 12px; background: color-mix(in srgb, #0f766e 12%, var(--surface, #fff)); color: var(--primary-ink, #0f766e); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 15px; }
.ap-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.ap-name { font-size: 15.5px; font-weight: 800; color: var(--text, #0f172a); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ap-role { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 700; color: var(--text-muted, #64748b); }
.ap-go { color: var(--text-light, #cbd5e1); flex-shrink: 0; }
.ap-item:hover .ap-go { color: var(--primary-ink, #14b8a6); }
.ap-signout { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 7px; background: none; border: none; color: var(--text-light, #94a3b8); font-size: 13px; font-weight: 700; cursor: pointer; padding: 8px; }
.ap-signout:hover { color: var(--danger-ink, #dc2626); }

/* Phones (360px / 344px): loosen combined shell+card padding so business names get room */
@media (max-width: 600px) {
  .ap-shell { padding: 14px; }
  .ap-card { padding: 22px 18px 18px; border-radius: 16px; }
  .ap-title { font-size: 20px; }
  .ap-sub b { overflow-wrap: anywhere; }
}
</style>
