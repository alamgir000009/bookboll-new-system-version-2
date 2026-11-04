<script setup>
import { inject } from 'vue'
import { Smartphone } from 'lucide-vue-next'

const download = inject('adminAppDownload', null)
</script>

<template>
  <button v-if="download?.available.value" type="button" class="btn btn-secondary" aria-label="Get the Bookboll Admin app" @click="download.open()">
    <Smartphone :size="16" /> Get the app
  </button>
</template>
