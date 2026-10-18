<script setup>
import { Check } from 'lucide-vue-next'
import { ref, watch, nextTick } from 'vue'
const props = defineProps({ steps: Array, active: String, completed: { type: Array, default: () => [] }, disabled: Boolean })
defineEmits(['select'])
const nav = ref(null)
watch(() => props.active, async () => {
  await nextTick()
  nav.value?.querySelector('[aria-current="step"]')?.scrollIntoView({ block: 'nearest', inline: 'center' })
})
</script>

<template>
  <nav ref="nav" class="cf-steps" aria-label="Setup steps">
    <button v-for="(step, index) in steps" :key="step.id" type="button" :disabled="disabled"
      :class="['cf-step', { active: active === step.id }]" :aria-current="active === step.id ? 'step' : undefined"
      @click="$emit('select', step.id)">
      <span class="cf-step-number"><Check v-if="completed.includes(step.id) && active !== step.id" :size="15" /><template v-else>{{ index + 1 }}</template></span>
      <span><strong>{{ step.label }}</strong><small>{{ step.hint }}</small></span>
    </button>
  </nav>
</template>
