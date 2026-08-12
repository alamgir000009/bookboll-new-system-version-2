<script setup>
/**
 * PublishNag.vue — a slim banner across the WHOLE provider dashboard while the
 * business's website is still unpublished: "your site is a draft — publish it".
 * Dismissible per session (×) or forever ("Don't show again", per business,
 * stored in localStorage). Hidden on /brand itself (that page IS the fix),
 * for platform roles, and once the site is live.
 */
import { ref, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { allUsers, currentOwnerUserId } from '../lib/currentUser.js'
import { providerSlug, brandHost } from '../lib/brand.js'
import { Globe2, ArrowRight, X } from 'lucide-vue-next'

const route = useRoute()
const owner = computed(() => allUsers.value.find(u => u.id === currentOwnerUserId.value) || null)
const storeKey = computed(() => `bb_pubnag_${currentOwnerUserId.value || ''}`)

const hiddenForever = ref(false)
const hiddenSession = ref(false)
function loadDismissal() {
  try {
    hiddenForever.value = localStorage.getItem(storeKey.value) === 'never'
    hiddenSession.value = sessionStorage.getItem(storeKey.value) === 'later'
  } catch { /* storage unavailable → just show */ }
}
watch(storeKey, loadDismissal, { immediate: true })

function dismissSession() {
  hiddenSession.value = true
  try { sessionStorage.setItem(storeKey.value, 'later') } catch { /* ignore */ }
}
function dismissForever() {
  hiddenForever.value = true
  try { localStorage.setItem(storeKey.value, 'never') } catch { /* ignore */ }
}

const host = computed(() => owner.value ? brandHost(providerSlug(owner.value)) : '')
// Only nag when we positively KNOW the site is unpublished (column present,
// value false) — never for platform roles or pre-migration databases.
const show = computed(() =>
  !!owner.value && owner.value.brand_enabled === false &&
  !hiddenForever.value && !hiddenSession.value &&
  route.path !== '/brand')
</script>

<template>
  <div v-if="show" class="pn">
    <Globe2 :size="16" class="pn-ico" />
    <div class="pn-txt">
      <b>Your website isn't published yet</b> — customers can't visit
      <span class="pn-host" data-no-translate>{{ host }}</span> until you publish it.
    </div>
    <router-link to="/brand" class="pn-cta">Publish my site <ArrowRight :size="13" /></router-link>
    <button type="button" class="pn-never" @click="dismissForever">Don't show again</button>
    <button type="button" class="pn-x" title="Dismiss for now" @click="dismissSession"><X :size="15" /></button>
  </div>
</template>

<style scoped>
.pn { display: flex; align-items: center; gap: 10px; padding: 10px 14px; margin-bottom: 16px;
  background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; font-size: 13px; color: #92400e; }
.pn-ico { flex-shrink: 0; color: #d97706; }
.pn-txt { flex: 1; min-width: 0; line-height: 1.45; }
.pn-txt b { color: #78350f; }
.pn-host { font-weight: 800; color: #78350f; }
.pn-cta { display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0; background: #d97706; color: #fff;
  font-size: 12.5px; font-weight: 800; padding: 8px 14px; border-radius: 999px; text-decoration: none; white-space: nowrap; }
.pn-cta:hover { background: #b45309; }
.pn-never { flex-shrink: 0; background: none; border: none; font-size: 12px; font-weight: 700; color: #b45309;
  text-decoration: underline; cursor: pointer; padding: 4px; white-space: nowrap; }
.pn-x { flex-shrink: 0; width: 30px; height: 30px; border: none; background: none; color: #b45309;
  border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
.pn-x:hover { background: #fef3c7; }
@media (max-width: 700px) {
  .pn { flex-wrap: wrap; }
  .pn-txt { flex-basis: calc(100% - 56px); }
  .pn-cta { min-height: 40px; }
}
</style>
