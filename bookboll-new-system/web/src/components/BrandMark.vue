<script setup>
/**
 * BrandMark — the official Bookboll glyph (a full circle + a half circle).
 * Renders in `currentColor`, so drop it inside any teal/coloured badge and it
 * picks up that badge's text colour (white on the brand square). Replaces the old
 * "BB" text placeholder across the business/dashboard surfaces.
 */
</script>

<template>
  <svg class="bb-mark" viewBox="0 0 100 100" fill="currentColor" aria-hidden="true" focusable="false">
    <circle cx="37" cy="50" r="21" />
    <path d="M62 29 A21 21 0 0 1 62 71 Z" />
  </svg>
</template>

<style scoped>
.bb-mark { width: 70%; height: 70%; display: block; }
</style>
