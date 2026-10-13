<script setup>
/**
 * Legacy handoff route from older Supabase signup flows.
 * Sanctum sessions are established via /login — redirect there and preserve
 * email / welcome query params for prefilling.
 */
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Loader } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()

onMounted(() => {
  const email = String(route.query.email || '')
  const welcome = route.query.welcome === '1'
  const query = {}
  if (email) query.email = email
  if (welcome) query.welcome = '1'
  router.replace({ path: '/login', query })
})
</script>

<template>
  <div class="cb">
    <Loader :size="22" class="spin" />
    <p>Opening sign-in…</p>
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
