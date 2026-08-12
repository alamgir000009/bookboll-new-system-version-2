<script setup>
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import ToastHost from './components/ToastHost.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import InputDialog from './components/InputDialog.vue'
import { loadAdminBrand } from './lib/adminBrand.js'
import { useHead } from '@unhead/vue'

const route = useRoute()
onMounted(() => { loadAdminBrand() })

useHead(computed(() => route.meta?.indexable ? {} : {
  title: 'Bookboll for Business',
  meta: [{ name: 'robots', content: 'noindex, nofollow' }],
}))
</script>

<template>
  <router-view />
  <ToastHost />
  <ConfirmDialog />
  <InputDialog />
</template>
