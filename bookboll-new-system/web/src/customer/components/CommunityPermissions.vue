<script setup>
import { MessageCircle, Newspaper, ShieldCheck } from 'lucide-vue-next'
import { COMMUNITY_AUDIENCES, normalizedPermissions } from '../../lib/communityAccess.js'
const props = defineProps({ modelValue: { type: Object, default: () => ({}) }, id: { type: String, default: 'community-access' } })
const emit = defineEmits(['update:modelValue'])
function change(key, value) { emit('update:modelValue', normalizedPermissions({ ...props.modelValue, [key]: value })) }
</script>
<template>
  <div class="community-permissions">
    <label :for="id + '-posts'"><span><Newspaper :size="20" /><span><strong>Who can post updates?</strong><small>Posts appear on your community’s feed.</small></span></span><select :id="id + '-posts'" :value="normalizedPermissions(modelValue).post_permission" @change="change('post_permission', $event.target.value)"><option v-for="option in COMMUNITY_AUDIENCES" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
    <label :for="id + '-chat'"><span><MessageCircle :size="20" /><span><strong>Who can send group messages?</strong><small>Members can read the chat, even if they cannot send.</small></span></span><select :id="id + '-chat'" :value="normalizedPermissions(modelValue).chat_permission" @change="change('chat_permission', $event.target.value)"><option v-for="option in COMMUNITY_AUDIENCES" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
    <p><ShieldCheck :size="18" /><span>You can assign moderators from the Members tab. Admins and moderators can remove unwanted posts and group messages. Owners and admins manage community settings; moderators can also change who can send messages from the group chat.</span></p>
  </div>
</template>
<style scoped>
.community-permissions label { display: grid; gap: 14px; padding: 20px 0; border-bottom: 1px solid var(--border); }.community-permissions label > span { display: flex; gap: 12px; align-items: flex-start; }.community-permissions svg { color: var(--brand-ink); flex-shrink: 0; margin-top: 3px; }.community-permissions strong { display: block; color: var(--text); font-size: 15px; }.community-permissions small { display: block; margin-top: 5px; color: var(--text-secondary); font-size: 13px; line-height: 1.5; }.community-permissions select { width: 100%; min-height: 46px; border: 1px solid var(--border); border-radius: 9px; padding: 10px 12px; background: var(--bg); color: var(--text); font: inherit; font-size: 14px; }.community-permissions p { display: flex; align-items: flex-start; gap: 10px; padding: 14px 0 0; font-size: 13px; color: var(--text-muted); line-height: 1.6; }.community-permissions select:focus-visible { outline: 2px solid var(--brand-ink); outline-offset: 3px; }
</style>
