<script setup>
/**
 * GroupNav — the landing page for one sidebar group (/nav/:group).
 * Opened from the navigation breadcrumbs or a mobile group heading;
 * the page explains WHAT this area is for (blurb + tips from navGroups.js)
 * and lists the group's sections as big tappable cards, filtered by the
 * active role's page access. Also the breadcrumb's middle-segment target.
 */
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronRight, Lightbulb } from 'lucide-vue-next'
import { NAV_GROUPS } from '../lib/navGroups.js'
import { canAccessPage } from '../lib/permissions.js'
import { useCurrentUser } from '../lib/currentUser.js'
import FeatureSearch from '../components/FeatureSearch.vue'

const route = useRoute()
const router = useRouter()
const { role, currentOverrides } = useCurrentUser()

const group = computed(() => NAV_GROUPS.find(g => g.key === route.params.group && g.items) || null)
const items = computed(() =>
  (group.value?.items || []).filter(i => canAccessPage(i.path, role.value, currentOverrides.value)))

// Unknown group / single-link group / nothing accessible → back to the dashboard home.
if (!group.value) router.replace('/admin')
</script>

<template>
  <div v-if="group" class="gn">
    <!-- Hero: what is this area for? -->
    <div class="gn-hero">
      <div class="gn-ic"><component :is="group.icon" :size="28" /></div>
      <div class="gn-hero-txt">
        <h1 class="gn-title">{{ group.label }}</h1>
        <p v-if="group.blurb" class="gn-blurb">{{ group.blurb }}</p>
      </div>
    </div>

    <FeatureSearch v-if="group.key === 'settings'" />

    <div class="gn-sect">Choose a section</div>
    <div class="gn-grid">
      <router-link v-for="item in items" :key="item.path" :to="item.path" class="gn-card">
        <span class="gn-card-ic"><component :is="item.icon" :size="22" /></span>
        <span class="gn-card-body">
          <span class="gn-card-t">{{ item.label }}</span>
          <span v-if="item.desc" class="gn-card-d">{{ item.desc }}</span>
        </span>
        <ChevronRight :size="18" class="gn-card-go" />
      </router-link>
    </div>
    <div v-if="!items.length" class="gn-empty">Nothing here is available for your role.</div>

    <!-- Tips: how to get the most out of this area -->
    <div v-if="group.tips?.length" class="gn-tips">
      <div class="gn-tips-h"><Lightbulb :size="16" /> Good to know</div>
      <ul>
        <li v-for="(t, i) in group.tips" :key="i">{{ t }}</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.gn { max-width: 880px; margin: 0 auto; padding: 28px 24px 40px; }

/* Hero */
.gn-hero {
  display: flex; align-items: flex-start; gap: 16px;
  background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0);
  border-radius: 18px; padding: 22px 24px; margin-bottom: 24px;
}
.gn-ic { width: 56px; height: 56px; border-radius: 15px; background: var(--primary-soft, #f0fdfa); color: var(--primary-ink, #0f766e); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.gn-hero-txt { min-width: 0; }
.gn-title { font-size: 23px; font-weight: 800; color: var(--text, #0f172a); margin: 2px 0 6px; }
.gn-blurb { font-size: 14px; color: var(--text-muted, #64748b); line-height: 1.6; margin: 0; max-width: 560px; }

/* Section cards */
.gn-sect { font-size: 12px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: var(--text-light, #94a3b8); margin-bottom: 10px; }
.gn-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.gn-card {
  display: flex; align-items: center; gap: 12px; text-decoration: none;
  background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px;
  padding: 16px; transition: all .13s;
}
.gn-card:hover { border-color: var(--primary, #0f766e); box-shadow: 0 6px 20px rgba(15, 23, 42, .07); transform: translateY(-1px); }
.gn-card-ic { width: 42px; height: 42px; border-radius: 11px; background: var(--bg, #f1f5f9); color: var(--text-muted, #475569); display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all .13s; }
.gn-card:hover .gn-card-ic { background: var(--primary-soft, #f0fdfa); color: var(--primary-ink, #0f766e); }
.gn-card-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.gn-card-t { font-size: 14.5px; font-weight: 700; color: var(--text, #0f172a); }
.gn-card-d { font-size: 12.5px; color: var(--text-muted, #64748b); line-height: 1.4; }
.gn-card-go { color: var(--text-light, #cbd5e1); flex-shrink: 0; transition: all .13s; }
.gn-card:hover .gn-card-go { color: var(--primary-ink, #0f766e); transform: translateX(2px); }
.gn-empty { font-size: 13.5px; color: var(--text-muted, #64748b); padding: 20px 0; }

/* Tips */
.gn-tips { margin-top: 24px; background: var(--primary-soft, #f0fdfa); border: 1px solid var(--primary-border, #99f6e4); border-radius: 14px; padding: 16px 18px; }
.gn-tips-h { display: flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 800; color: var(--primary-ink, #0f766e); margin-bottom: 8px; }
.gn-tips ul { margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 6px; }
.gn-tips li { font-size: 13px; color: var(--primary-ink, #115e59); line-height: 1.55; }

@media (max-width: 600px) {
  .gn { padding: 16px 14px 32px; }
  .gn-hero { flex-direction: column; align-items: center; text-align: center; padding: 18px 16px; }
  .gn-grid { grid-template-columns: 1fr; }
}
</style>
