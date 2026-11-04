<script setup>
/**
 * Choose a platform account to move into a business.
 *
 * This was a native <select> of "name · email", which on real data listed
 * thousands of customer accounts with nothing to tell them apart — the admin
 * could not see who anyone was, let alone find a colleague. A move is
 * destructive (the account leaves the platform team and is signed out), so the
 * picker has to show WHO is being moved: their role, what they can currently
 * reach, and when they were last here.
 *
 * One component for both entry points — assigning an owner while creating a
 * business, and assigning into an existing one — so the two cannot drift into
 * different-looking versions of the same decision.
 */
import { computed, ref, watch } from 'vue'
import { Search, Check, ShieldCheck } from 'lucide-vue-next'
import { roleLabel } from '../lib/permissions.js'

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  candidates: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  /** Shown when nothing matches and nothing is loading. */
  emptyText: { type: String, default: 'No eligible accounts.' },
})
const emit = defineEmits(['update:modelValue', 'search'])

const query = ref('')
// The server also filters (it caps the list), so a team bigger than one page
// still narrows properly rather than only within what was already sent.
let timer = null
watch(query, (q) => {
  clearTimeout(timer)
  timer = setTimeout(() => emit('search', q.trim()), 250)
})

const matches = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return props.candidates
  return props.candidates.filter(c => `${c.name} ${c.email} ${roleLabel(c.role) || ''}`.toLowerCase().includes(q))
})
const selected = computed(() => props.candidates.find(c => String(c.id) === String(props.modelValue)) || null)

function pick(c) {
  emit('update:modelValue', String(c.id) === String(props.modelValue) ? '' : String(c.id))
}
function initials(name) {
  return String(name || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0] || '').join('').toUpperCase() || '?'
}
/** A stable colour per account, so the same person looks the same everywhere. */
function tint(c) {
  const key = String(c.email || c.id || '')
  let h = 0
  for (let i = 0; i < key.length; i++) h = (h * 31 + key.charCodeAt(i)) % 360
  return `hsl(${h} 52% 42%)`
}
function lastSeen(iso) {
  if (!iso) return 'Never signed in'
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000)
  if (days <= 0) return 'Active today'
  if (days === 1) return 'Active yesterday'
  if (days < 30) return `Active ${days}d ago`
  const months = Math.floor(days / 30)
  return `Active ${months}mo ago`
}
</script>

<template>
  <div class="ap">
    <div class="ap-search">
      <Search :size="15" class="ap-search-icon" />
      <input v-model="query" type="search" :placeholder="`Search ${candidates.length} platform account${candidates.length === 1 ? '' : 's'}…`" aria-label="Search platform accounts" />
    </div>

    <p v-if="loading" class="ap-state">Loading accounts…</p>
    <p v-else-if="!matches.length" class="ap-state">{{ query.trim() ? `No account matches “${query.trim()}”.` : emptyText }}</p>

    <ul v-else class="ap-list" role="listbox" :aria-activedescendant="selected ? `ap-${selected.id}` : undefined">
      <li v-for="c in matches" :key="c.id">
        <button type="button" :id="`ap-${c.id}`" class="ap-row" :class="{ on: String(c.id) === String(modelValue) }"
                role="option" :aria-selected="String(c.id) === String(modelValue)" @click="pick(c)">
          <span class="ap-avatar" :style="{ background: tint(c) }">{{ initials(c.name) }}</span>
          <span class="ap-who">
            <span class="ap-name">{{ c.name || 'Unnamed account' }}</span>
            <span class="ap-email">{{ c.email }}</span>
          </span>
          <span class="ap-meta">
            <span v-if="c.role" class="ap-role"><ShieldCheck :size="11" />{{ roleLabel(c.role) }}</span>
            <span class="ap-sub">
              <template v-if="c.venues">{{ c.venues }} venue{{ c.venues === 1 ? '' : 's' }} · </template>{{ lastSeen(c.last_login) }}
            </span>
          </span>
          <Check v-if="String(c.id) === String(modelValue)" :size="16" class="ap-check" />
        </button>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.ap { display: flex; flex-direction: column; gap: 10px; }
.ap-search { position: relative; }
.ap-search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; }
.ap-search input { width: 100%; padding-left: 34px; }
.ap-state { color: var(--text-muted); font-size: 13px; margin: 2px 0 0; }

.ap-list { list-style: none; margin: 0; padding: 0; max-height: 268px; overflow-y: auto; border: 1px solid var(--border); border-radius: 10px; }
.ap-list li + li .ap-row { border-top: 1px solid var(--border); }
.ap-row { display: flex; align-items: center; gap: 11px; width: 100%; padding: 10px 12px; background: transparent; border: 0; text-align: left; cursor: pointer; transition: background .12s; }
.ap-row:hover { background: var(--surface-hover, rgba(127,127,127,.08)); }
.ap-row.on { background: var(--primary-soft, rgba(15,118,110,.14)); }
.ap-row:focus-visible { outline: 2px solid var(--primary); outline-offset: -2px; }

.ap-avatar { flex-shrink: 0; width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 12px; font-weight: 700; letter-spacing: .02em; }
.ap-who { display: flex; flex-direction: column; min-width: 0; flex: 1; gap: 1px; }
.ap-name { font-size: 13.5px; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ap-email { font-size: 12px; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ap-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; flex-shrink: 0; }
.ap-role { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; background: var(--bg); color: var(--text-muted); border: 1px solid var(--border); white-space: nowrap; }
.ap-sub { font-size: 11px; color: var(--text-muted); white-space: nowrap; }
.ap-check { flex-shrink: 0; color: var(--primary); }

@media (max-width: 620px) {
  .ap-meta { display: none; }
  .ap-row { padding: 12px; }
}
</style>
