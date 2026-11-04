<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft, ChevronRight } from 'lucide-vue-next'
import { browseReturnPath } from '../../lib/browseCatalog.js'
import { facilityPath } from '../../lib/cityPages.js'
import { isWhiteLabel } from '../../lib/brand.js'
defineProps({ venue: { type: Object, default: null }, current: { type: String, default: '' } })
const route = useRoute()
const back = computed(() => browseReturnPath(route.query.from))
</script>
<template>
  <nav class="browse-breadcrumb" aria-label="Breadcrumb">
    <router-link :to="back" class="browse-back"><ArrowLeft :size="16" />{{ route.query.from ? 'Back to results' : (isWhiteLabel ? 'All listings' : 'All Venues') }}</router-link>
    <template v-if="venue"><ChevronRight :size="13" /><router-link :to="{ path: facilityPath(venue.id, venue.name), query: { from: back } }">{{ venue.name }}</router-link></template>
    <template v-if="current"><ChevronRight :size="13" /><span aria-current="page">{{ current }}</span></template>
  </nav>
</template>
<style scoped>
.browse-breadcrumb { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; color: var(--text-muted); font-size: 12px; margin-bottom: 16px; min-width: 0; }
.browse-breadcrumb a { display: inline-flex; align-items: center; gap: 7px; min-height: 44px; padding-inline: 4px; }
.browse-breadcrumb svg { flex-shrink: 0; }
.browse-breadcrumb .browse-back { color: var(--brand-ink, var(--brand)); font-weight: 600; }
.browse-breadcrumb a:hover { text-decoration: underline; }
.browse-breadcrumb a:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; border-radius: 5px; }
.browse-breadcrumb span { color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 280px; }
@media(max-width:760px) { .browse-breadcrumb { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; white-space: nowrap; margin-bottom: 10px; } .browse-breadcrumb a { flex-shrink: 0; } .browse-breadcrumb span { display: none; } .browse-breadcrumb > svg:last-of-type { display: none; } }
</style>
