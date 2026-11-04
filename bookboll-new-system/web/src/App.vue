<script setup>
import { onMounted } from 'vue'
import CookieConsent from './components/CookieConsent.vue'
import { isWhiteLabel, activeBrand, isBrandPreview } from './lib/brand.js'
import CustomerLayout from './customer/CustomerLayout.vue'
import ToastHost from './components/ToastHost.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import InputDialog from './components/InputDialog.vue'

onMounted(() => {
  document.documentElement.classList.remove('adm-shell')
})
</script>

<template>
  <CustomerLayout />
  <ToastHost />
  <ConfirmDialog />
  <InputDialog />
  <CookieConsent v-if="!isBrandPreview()" :scope="isWhiteLabel ? `storefront:${activeBrand?.id || activeBrand?.slug || activeBrand?.name}` : 'marketplace'" />
</template>
