<script setup>
/**
 * Cross-origin session handoff from business.bookboll.com/signup → admin.bookboll.com.
 * Reads access_token + refresh_token from the query string, establishes the
 * Supabase session on this origin, then lands on the dashboard.
 */
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { supabase } from '../lib/supabase.js'
import { initAuth } from '../lib/auth.js'
import { Loader } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const err = ref('')

onMounted(async () => {
  const access_token = String(route.query.access_token || '')
  const refresh_token = String(route.query.refresh_token || '')
  const welcome = route.query.welcome === '1'
  const email = String(route.query.email || '')

  if (!access_token || !refresh_token) {
    router.replace({ path: '/login', query: email ? { email } : {} })
    return
  }

  const { error } = await supabase.auth.setSession({ access_token, refresh_token })
  if (error) {
    err.value = error.message || 'Could not restore your session.'
    setTimeout(() => {
      router.replace({ path: '/login', query: email ? { email } : {} })
    }, 1600)
    return
  }

  await initAuth()
  router.replace(welcome ? '/admin?welcome=1' : '/admin')
})
</script>

<template>
  <div class="cb">
    <Loader :size="22" class="spin" />
    <p v-if="err">{{ err }}</p>
    <p v-else>Opening your dashboard…</p>
  </div>
</template>

<style scoped>
.cb {
  min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 12px; color: #64748b; font: 500 14px/1.4 system-ui, sans-serif;
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #0f766e22 100%);
}
.cb p { color: #e2e8f0; }
.spin { animation: spin 0.8s linear infinite; color: #14b8a6; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
