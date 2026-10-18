<script setup>
import { computed } from 'vue'
import { UsersRound, ArrowUpRight, Lock, Check } from 'lucide-vue-next'
import { communityMemberCount } from '../../lib/communityPresentation.js'
const props = defineProps({ community: { type: Object, required: true }, joined: Boolean, preview: Boolean })
const count = computed(() => communityMemberCount(props.community))
const initials = computed(() => (props.community.name || 'Your community').split(/\s+/).slice(0, 2).map(s => s[0]).join('').toUpperCase())
</script>
<template>
  <component :is="preview ? 'article' : 'router-link'" :to="preview ? undefined : `/communities/${community.id}`" class="community-card">
    <div class="community-card-cover" :class="{ 'has-image': community.cover_image }">
      <img v-if="community.cover_image" :src="community.cover_image" alt="" loading="lazy" />
      <span v-else class="community-card-court" aria-hidden="true"></span>
      <span class="community-card-category">{{ community.sport || 'Community' }}</span>
      <span v-if="community.is_private" class="community-card-private"><Lock :size="12" /> Private</span>
    </div>
    <div class="community-card-body">
      <div class="community-card-identity"><span class="community-card-avatar"><img v-if="community.avatar_image" :src="community.avatar_image" alt="" loading="lazy" /><span v-else>{{ initials }}</span></span><span v-if="joined" class="community-card-joined"><Check :size="13" /> Joined</span></div>
      <h3>{{ community.name || 'Your community name' }}</h3>
      <p class="community-card-count"><UsersRound :size="15" /> {{ count.toLocaleString() }} {{ count === 1 ? 'member' : 'members' }}</p>
      <p class="community-card-description">{{ community.description || `A place to connect${community.sport ? ' over ' + community.sport.toLowerCase() : ''}, share updates and plan your next meetup.` }}</p>
      <span class="community-card-action">{{ joined ? 'Visit your community' : 'Explore community' }} <ArrowUpRight :size="17" /></span>
    </div>
  </component>
</template>
<style scoped>
.community-card { display: flex; flex-direction: column; min-width: 0; overflow: hidden; border: 1px solid var(--border); background: var(--surface); border-radius: 16px; text-decoration: none; color: var(--text); }
a.community-card:hover { border-color: var(--brand-ink); } .community-card:focus-visible { outline: 3px solid var(--brand); outline-offset: 3px; }
.community-card-cover { position: relative; height: 120px; background: var(--brand-tint, var(--bg)); color: var(--brand-ink); overflow: hidden; }
.community-card-cover > img { width: 100%; height: 100%; object-fit: cover; }
.community-card-court { position: absolute; width: 180px; height: 150px; border: 2px solid currentColor; opacity: .18; transform: rotate(-22deg); right: -5px; top: -35px; }
.community-card-court::before { content: ''; position: absolute; inset: 22px; border: 2px solid currentColor; }.community-card-court::after { content: ''; position: absolute; top: 50%; left: 0; width: 100%; border-top: 2px solid currentColor; }
.community-card-category, .community-card-private { position: absolute; top: 14px; padding: 5px 9px; background: var(--surface); border-radius: 6px; font-size: 12px; font-weight: 650; color: var(--text); }
.community-card-category { left: 16px; }.community-card-private { right: 12px; display: flex; gap: 5px; align-items: center; }
.community-card-body { padding: 0 20px 18px; display: flex; flex-direction: column; flex: 1; }
.community-card-identity { position: relative; display: flex; align-items: flex-end; justify-content: space-between; gap: 10px; margin-top: -28px; margin-bottom: 14px; }
.community-card-avatar { width: 66px; height: 66px; display: grid; place-items: center; background: var(--brand-tint, var(--bg)); color: var(--brand-ink); border: 4px solid var(--surface); border-radius: 18px; overflow: hidden; font-size: 22px; font-weight: 750; flex-shrink: 0; }.community-card-avatar img { width: 100%; height: 100%; object-fit: cover; }
.community-card-joined { display: flex; align-items: center; gap: 4px; color: var(--success-ink, var(--brand-ink)); font-size: 12px; padding-bottom: 6px; }
.community-card h3 { margin: 0; font-size: 19px; line-height: 1.35; font-weight: 750; overflow-wrap: anywhere; }
.community-card-count { display: flex; gap: 7px; align-items: center; font-size: 13px; color: var(--text-muted); margin: 8px 0 0; }
.community-card-description { margin: 14px 0 20px; font-size: 13px; line-height: 1.65; color: var(--text-secondary); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.community-card-action { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: auto; padding-top: 13px; border-top: 1px solid var(--border); font-size: 13px; font-weight: 650; color: var(--brand-ink); }
</style>
