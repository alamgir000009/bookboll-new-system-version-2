<script setup>
import { ArrowUpRight, Building2 } from 'lucide-vue-next'
defineProps({ category: { type: Object, required: true } })
</script>

<template>
  <router-link :to="category.to" class="category-card" :class="{ 'category-card-all': category.key === 'all' }">
    <div class="category-stack" aria-hidden="true">
      <img v-for="(photo, i) in category.photos" :key="photo" :src="photo" alt="" loading="lazy" class="category-photo" :class="`category-photo-${i}`" />
      <div v-if="!category.photos.length" class="category-placeholder"><Building2 :size="36" /></div>
      <span v-if="category.key === 'all'" class="category-all-mark"><Building2 :size="18" /></span>
    </div>
    <span class="category-name">{{ category.label }} <ArrowUpRight :size="16" /></span>
    <span class="category-count">{{ category.count }} {{ category.count === 1 ? 'listing' : 'listings' }}</span>
  </router-link>
</template>

<style scoped>
.category-card { width: 188px; padding: 12px 6px 5px; color: var(--text); display: block; }
.category-stack { height: 132px; position: relative; margin: 8px 9px 17px; isolation: isolate; }
.category-photo, .category-placeholder { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border: 3px solid var(--surface); border-radius: 17px; box-shadow: 0 3px 10px #0f172a18; background: var(--border); }
.category-photo-0 { z-index: 3; }
.category-photo-1 { transform: rotate(-7deg) translate(-6px, -5px); z-index: 2; }
.category-photo-2 { transform: rotate(7deg) translate(6px, -7px); z-index: 1; }
.category-placeholder { display: grid; place-items: center; color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 10%, var(--surface)); }
.category-all-mark { position: absolute; z-index: 4; bottom: 10px; inset-inline-start: 10px; display: grid; place-items: center; width: 32px; height: 32px; border-radius: 10px; background: var(--surface); color: var(--brand-ink, var(--brand)); }
.category-name { display: flex; align-items: center; justify-content: space-between; gap: 6px; font-weight: 700; font-size: 16px; padding-inline: 9px; }
.category-name svg { color: var(--text-muted); flex-shrink: 0; }
.category-count { display: block; font-size: 13px; color: var(--text-muted); margin-top: 3px; padding-inline: 9px; }
.category-card:hover .category-name, .category-card-all .category-name { color: var(--brand-ink, var(--brand)); }
.category-card:focus-visible { outline: 3px solid var(--brand); outline-offset: 1px; border-radius: 12px; }
@media(max-width:760px) {
  .category-card { width: 151px; padding-inline: 3px; }
  .category-stack { height: 105px; margin-bottom: 14px; }
  .category-name { font-size: 14px; padding-inline: 7px; }
  .category-count { font-size: 12px; padding-inline: 7px; }
}
</style>
