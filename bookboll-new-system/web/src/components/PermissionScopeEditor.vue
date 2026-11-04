<script setup>
/**
 * PermissionScopeEditor.vue — per-user capability overrides + location/listing/
 * program scope, for the My Team / Users editor.
 *
 * The chosen ROLE already sets sensible permissions. This component lets you
 * fine-tune beyond the role under an "Advanced — custom permissions" collapse
 * (toggling a role-default cap OFF records a `revoke`; toggling an extra cap ON
 * records a `grant`), and pin the person to specific locations/listings/programs.
 */
import { ref, computed } from 'vue'
import { CAP_INFO, roleBaseCaps, can, editableCapsForRole, prereqsOf, dependentsOf } from '../lib/permissions.js'
import { Check, ChevronDown, SlidersHorizontal } from 'lucide-vue-next'

const props = defineProps({
  role:        { type: String, default: 'staff' },
  permissions: { type: Object, default: () => ({}) },     // { grant:[], revoke:[] }
  locationIds: { type: Array,  default: () => [] },
  listingIds:  { type: Array,  default: () => [] },
  programIds:  { type: Array,  default: () => [] },
  availableLocations: { type: Array, default: () => [] },  // [{id,name}]
  availableListings:  { type: Array, default: () => [] },  // [{id,name,location_id}]
  availablePrograms:  { type: Array, default: () => [] },  // [{id,name,location_id}]
})
const emit = defineEmits([
  'update:permissions', 'update:locationIds', 'update:listingIds', 'update:programIds',
])

const advancedOpen = ref(false)
const baseCaps = computed(() => roleBaseCaps(props.role))
const grant = computed(() => props.permissions?.grant || [])
const revoke = computed(() => props.permissions?.revoke || [])
const hasCustom = computed(() => grant.value.length > 0 || revoke.value.length > 0)

// Group capabilities by their CAP_INFO.group — limited to the caps that make sense
// for this role's side (e.g. provider Staff never see platform-only powers).
const groups = computed(() => {
  const m = {}
  for (const key of editableCapsForRole(props.role)) {
    const info = CAP_INFO[key]
    if (!info) continue
    ;(m[info.group] ||= []).push({ key, label: info.label })
  }
  return Object.entries(m).map(([group, caps]) => ({ group, caps }))
})

const isOn = (cap) => can(cap, props.role, props.permissions)
const isBase = (cap) => baseCaps.value.includes(cap)

// Toggle a capability, cascading dependencies so a contradictory state is
// impossible: turning a cap ON also turns on its prerequisites (e.g. Manage
// bookings ⇒ View bookings); turning a cap OFF also turns off anything that
// depends on it (View bookings off ⇒ Manage bookings off). The resulting
// effective set is converted back into { grant, revoke } relative to the role.
function toggleCap(cap) {
  const editable = editableCapsForRole(props.role)
  const on = new Set(editable.filter(c => isOn(c)))
  if (on.has(cap)) {
    on.delete(cap)
    for (const d of dependentsOf(cap)) on.delete(d)
  } else {
    on.add(cap)
    for (const p of prereqsOf(cap)) on.add(p)
  }
  const base = new Set(baseCaps.value)
  const g = [], r = []
  for (const c of editable) {
    const want = on.has(c)
    if (want && !base.has(c)) g.push(c)
    else if (!want && base.has(c)) r.push(c)
  }
  emit('update:permissions', { grant: g, revoke: r })
}
function resetToRole() { emit('update:permissions', { grant: [], revoke: [] }) }

// ── Scope pickers ──
function toggleId(list, id, evt) {
  const set = new Set(list)
  set.has(id) ? set.delete(id) : set.add(id)
  emit(evt, [...set])
}
// Selected locations narrow the listing/program choices to those AT the chosen
// venues. Items with no venue (location_id null) are business-wide and stay
// pickable regardless of the location selection.
const scopedListings = computed(() =>
  props.locationIds.length
    ? props.availableListings.filter(l => !l.location_id || props.locationIds.includes(l.location_id))
    : props.availableListings)
const scopedPrograms = computed(() =>
  props.locationIds.length
    ? props.availablePrograms.filter(p => !p.location_id || props.locationIds.includes(p.location_id))
    : props.availablePrograms)
</script>

<template>
  <div class="pse">
    <!-- Advanced: custom permissions (collapsed by default) -->
    <div class="pse-adv">
      <button type="button" class="pse-adv-head" :class="{ open: advancedOpen }" @click="advancedOpen = !advancedOpen">
        <SlidersHorizontal :size="15" />
        <span class="pse-adv-title">Advanced — custom permissions</span>
        <span v-if="hasCustom" class="pse-adv-badge">customised</span>
        <ChevronDown :size="16" class="pse-adv-chev" :class="{ rot: advancedOpen }" />
      </button>

      <div v-show="advancedOpen" class="pse-adv-body">
        <div class="pse-adv-note">
          Start from the role’s defaults, then tick to add or untick to remove for this person.
          <button v-if="hasCustom" type="button" class="pse-reset" @click="resetToRole">Reset to role defaults</button>
        </div>
        <div class="pse-groups">
          <div v-for="grp in groups" :key="grp.group" class="pse-group">
            <div class="pse-group-name">{{ grp.group }}</div>
            <label v-for="c in grp.caps" :key="c.key" class="pse-cap">
              <span class="pse-check" :class="{ on: isOn(c.key) }"><Check v-if="isOn(c.key)" :size="12" /></span>
              <input type="checkbox" :checked="isOn(c.key)" @change="toggleCap(c.key)" hidden />
              <span class="pse-cap-label">{{ c.label }}</span>
              <span v-if="!isBase(c.key) && isOn(c.key)" class="pse-tag granted">added</span>
              <span v-else-if="isBase(c.key) && !isOn(c.key)" class="pse-tag revoked">removed</span>
            </label>
          </div>
        </div>
      </div>
    </div>

    <!-- Scope: locations / listings / programs -->
    <div class="pse-section-title">Access scope <span class="pse-hint">— leave empty for the whole business</span></div>
    <div class="pse-scope">
      <div class="pse-scope-col">
        <div class="pse-scope-head">Locations</div>
        <div class="pse-chiplist">
          <label v-for="l in availableLocations" :key="l.id" class="pse-chip" :class="{ on: locationIds.includes(l.id) }">
            <input type="checkbox" :checked="locationIds.includes(l.id)" @change="toggleId(locationIds, l.id, 'update:locationIds')" hidden />
            {{ l.name }}
          </label>
          <div v-if="!availableLocations.length" class="pse-empty">No locations yet.</div>
        </div>
      </div>

      <div class="pse-scope-col" v-if="availableListings.length">
        <div class="pse-scope-head">Listings <span class="pse-hint">(optional)</span></div>
        <div class="pse-chiplist">
          <label v-for="l in scopedListings" :key="l.id" class="pse-chip" :class="{ on: listingIds.includes(l.id) }">
            <input type="checkbox" :checked="listingIds.includes(l.id)" @change="toggleId(listingIds, l.id, 'update:listingIds')" hidden />
            {{ l.name }}
          </label>
          <div v-if="!scopedListings.length" class="pse-empty">No listings at the selected locations.</div>
        </div>
      </div>

      <div class="pse-scope-col" v-if="availablePrograms.length">
        <div class="pse-scope-head">Programs <span class="pse-hint">(optional)</span></div>
        <div class="pse-chiplist">
          <label v-for="p in scopedPrograms" :key="p.id" class="pse-chip" :class="{ on: programIds.includes(p.id) }">
            <input type="checkbox" :checked="programIds.includes(p.id)" @change="toggleId(programIds, p.id, 'update:programIds')" hidden />
            {{ p.name }}
          </label>
          <div v-if="!scopedPrograms.length" class="pse-empty">No programs at the selected locations.</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.pse { display: flex; flex-direction: column; gap: 14px; }
.pse-section-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
.pse-hint { font-weight: 500; text-transform: none; letter-spacing: 0; color: var(--text-muted); }

/* Advanced collapse */
.pse-adv { border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.pse-adv-head { width: 100%; display: flex; align-items: center; gap: 9px; padding: 11px 13px; background: #f8fafc; border: none; cursor: pointer; font: inherit; color: var(--text); }
.pse-adv-head:hover { background: #f1f5f9; }
.pse-adv-head.open { border-bottom: 1px solid var(--border); }
.pse-adv-head svg:first-child { color: var(--text-muted); }
.pse-adv-title { font-size: 13.5px; font-weight: 700; }
.pse-adv-badge { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 2px 7px; border-radius: 999px; background: #eff6ff; color: #2563eb; }
.pse-adv-chev { margin-left: auto; color: var(--text-muted); transition: transform .15s; }
.pse-adv-chev.rot { transform: rotate(180deg); }
.pse-adv-body { padding: 13px; }
.pse-adv-note { font-size: 12.5px; color: var(--text-muted); margin-bottom: 12px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pse-reset { font-size: 12px; font-weight: 700; color: var(--primary); background: none; border: none; cursor: pointer; text-decoration: underline; }

.pse-groups { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.pse-group { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; }
.pse-group-name { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 8px; }
.pse-cap { display: flex; align-items: center; gap: 8px; padding: 5px 0; cursor: pointer; font-size: 13px; }
.pse-check { width: 18px; height: 18px; border-radius: 5px; border: 1.5px solid var(--border); display: flex; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; }
.pse-check.on { background: var(--primary); border-color: var(--primary); }
.pse-cap-label { flex: 1; }
.pse-tag { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 1px 6px; border-radius: 999px; }
.pse-tag.granted { background: #ecfdf5; color: #047857; }
.pse-tag.revoked { background: #fef2f2; color: #b91c1c; }

.pse-scope { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; }
.pse-scope-head { font-size: 12.5px; font-weight: 700; color: var(--text); margin-bottom: 7px; }
.pse-chiplist { display: flex; flex-wrap: wrap; gap: 6px; }
.pse-chip { padding: 5px 11px; border: 1.5px solid var(--border); border-radius: 999px; background: var(--surface, #ffffff); font-size: 12.5px; cursor: pointer; color: var(--text); }
.pse-chip:hover { border-color: var(--primary); }
.pse-chip.on { border-color: var(--primary); background: #f0fdfa; color: var(--primary); font-weight: 600; }
.pse-empty { font-size: 12.5px; color: var(--text-muted); }
</style>
