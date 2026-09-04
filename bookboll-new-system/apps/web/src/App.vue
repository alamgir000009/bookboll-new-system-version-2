<script setup>
import { onMounted } from 'vue'
import CustomerLayout from './customer/CustomerLayout.vue'
import ToastHost from './components/ToastHost.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import InputDialog from './components/InputDialog.vue'
import { loadAdminBrand } from './lib/adminBrand.js'


onMounted(() => {
  loadAdminBrand()
  document.documentElement.classList.remove('adm-shell')
})
</script>

<template>
  <CustomerLayout />
  <ToastHost />
  <ConfirmDialog />
  <InputDialog />
</template>
