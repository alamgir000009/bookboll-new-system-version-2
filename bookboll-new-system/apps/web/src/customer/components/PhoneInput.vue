<script setup>
/**
 * PhoneInput — a phone field with a country-code picker.
 *
 *  • The dial code defaults to the shopper's region (`defaultIso`) but is changeable
 *    via a small searchable dropdown.
 *  • v-model is one combined string "<dial> <national>" (e.g. "+974 50123456"), so it
 *    drops into the existing single-string phone fields with no schema change.
 */
import { ref, computed, watch } from 'vue'
import { DIAL_OPTIONS, dialForIso, isoForDial, flagForIso, splitPhone, joinPhone } from '../../customer/phone.js'
import { ChevronDown, Search, Phone } from 'lucide-vue-next'

const props = defineProps({
  modelValue: { type: String, default: '' },
  defaultIso: { type: String, default: '' },
  placeholder: { type: String, default: 'Phone number' },
})
const emit = defineEmits(['update:modelValue'])

const init = splitPhone(props.modelValue, dialForIso(props.defaultIso))
const iso = ref(init.dial ? (isoForDial(init.dial) || (props.defaultIso || '').toUpperCase()) : (props.defaultIso || '').toUpperCase())
const national = ref(init.national)
const open = ref(false)
const search = ref('')

const dial = computed(() => dialForIso(iso.value) || init.dial || '')
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return DIAL_OPTIONS
  return DIAL_OPTIONS.filter(c => c.name.toLowerCase().includes(q) || c.dial.includes(q) || c.iso.toLowerCase() === q)
})

function emitVal() { emit('update:modelValue', joinPhone(dial.value, national.value)) }
function pick(c) { iso.value = c.iso; open.value = false; search.value = ''; emitVal() }
function onNational(e) { national.value = e.target.value.replace(/[^\d\s-]/g, ''); emitVal() }

// Prefill / external changes (e.g. a saved number set after mount).
watch(() => props.modelValue, (v) => {
  if (v === joinPhone(dial.value, national.value)) return
  const s = splitPhone(v, dialForIso(props.defaultIso))
  if (s.dial) iso.value = isoForDial(s.dial) || iso.value
  national.value = s.national
})
// Region resolved after mount → adopt its dial code while the field is still empty.
watch(() => props.defaultIso, (v) => { if (!national.value && v) iso.value = v.toUpperCase() })
</script>

<template>
  <div class="pin" :class="{ open }">
    <button type="button" class="pin-dial" @click="open = !open" :aria-expanded="open">
      <span class="pin-flag">{{ flagForIso(iso) }}</span>
      <span class="pin-code">{{ dial || '+' }}</span>
      <ChevronDown :size="14" class="pin-chev" />
    </button>
    <span class="pin-div"></span>
    <Phone :size="15" class="pin-ico" />
    <input class="pin-num" :value="national" @input="onNational" :placeholder="placeholder" inputmode="tel" autocomplete="tel-national" />

    <div v-if="open" class="pin-pop">
      <div class="pin-search"><Search :size="14" /><input v-model="search" placeholder="Search country or code" autofocus /></div>
      <ul class="pin-list">
        <li v-for="c in filtered" :key="c.iso" :class="{ on: c.iso === iso }" @click="pick(c)">
          <span class="pin-flag">{{ flagForIso(c.iso) }}</span>
          <span class="pin-name">{{ c.name }}</span>
          <span class="pin-ldial">{{ c.dial }}</span>
        </li>
        <li v-if="!filtered.length" class="pin-empty">No match</li>
      </ul>
    </div>
    <div v-if="open" class="pin-backdrop" @click="open = false; search = ''"></div>
  </div>
</template>

<style scoped>
.pin { position: relative; display: flex; align-items: center; gap: 0; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; background: var(--surface, #ffffff); height: 46px; box-sizing: border-box; }
.pin.open, .pin:focus-within { border-color: var(--brand-accent); }
.pin-dial { display: flex; align-items: center; gap: 5px; height: 100%; padding: 0 10px 0 12px; border: none; background: none; cursor: pointer; border-radius: 10px 0 0 10px; font-size: 14.5px; font-weight: 700; color: #0f172a; white-space: nowrap; }
.pin-flag { font-size: 16px; line-height: 1; }
.pin-code { font-variant-numeric: tabular-nums; }
.pin-chev { color: var(--text-light, #94a3b8); flex-shrink: 0; }
.pin-div { width: 1px; height: 22px; background: var(--border, #e2e8f0); flex-shrink: 0; }
.pin-ico { color: var(--text-light, #94a3b8); margin: 0 8px; flex-shrink: 0; }
.pin-num { flex: 1; min-width: 0; border: none; outline: none; background: none; font-size: 15px; color: #0f172a; padding: 0 12px 0 0; height: 100%; }

.pin-pop { position: absolute; z-index: 60; top: calc(100% + 6px); left: 0; width: 280px; max-width: 88vw; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; box-shadow: 0 16px 40px rgba(15,23,42,.18); overflow: hidden; }
.pin-search { display: flex; align-items: center; gap: 7px; padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: var(--text-muted, #64748b); }
.pin-search input { flex: 1; border: none; outline: none; font-size: 14px; background: none; color: #0f172a; }
.pin-list { list-style: none; margin: 0; padding: 4px; max-height: 240px; overflow-y: auto; }
.pin-list li { display: flex; align-items: center; gap: 9px; padding: 9px 10px; border-radius: 8px; cursor: pointer; font-size: 14px; }
.pin-list li:hover { background: #f1f5f9; }
.pin-list li.on { background: color-mix(in srgb, var(--brand) 18%, white); }
.pin-name { flex: 1; min-width: 0; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pin-ldial { color: var(--text-muted, #64748b); font-weight: 700; font-variant-numeric: tabular-nums; }
.pin-empty { padding: 14px; text-align: center; color: var(--text-light, #94a3b8); font-size: 13.5px; }
.pin-backdrop { position: fixed; inset: 0; z-index: 50; }

/* Phone: lift the editable fields to 16px so focusing them never zooms iOS Safari. */
@media (max-width: 760px) {
  .pin-num { font-size: 16px; }
  .pin-dial { font-size: 16px; }
  .pin-search input { font-size: 16px; }
}
</style>
