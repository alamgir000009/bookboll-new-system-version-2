<script setup>
import MultiSelect from './MultiSelect.vue'
defineProps({ modelValue: { type: Array, default: () => [] }, options: { type: Array, default: () => [] }, allLabel: { type: String, default: 'All' }, disabled: Boolean })
defineEmits(['update:modelValue'])
</script>
<template><MultiSelect class="ms" :model-value="modelValue" :options="options" :placeholder="allLabel" :max-display="1" :disabled="disabled" @update:model-value="$emit('update:modelValue', $event)" /></template>
