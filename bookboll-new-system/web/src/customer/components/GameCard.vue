<script setup>
/**
 * GameCard — Luma-style TEXT-FIRST event card: time · type, title, host, venue,
 * going-avatars, price + Join. No cover image on the card by design — photos live
 * on the event detail page (the card reads faster and fits more events per screen).
 */
import { computed } from 'vue'
import { Gift, Tag, ChevronRight, MapPin, Users } from 'lucide-vue-next'
import { fmt12 } from '../../lib/slots.js'
import { avatarColor } from '../../lib/avatarColor.js'
import { meetupState, STATE_META } from '../../customer/meetup.js'
const props = defineProps({ game: { type: Object, required: true } })
// Lifecycle chip — only shown when it says something beyond "open for joins".
const lifeState = computed(() => meetupState(props.game))
const lifeMeta = computed(() => lifeState.value !== 'open' ? STATE_META[lifeState.value] : null)
const isTourn = computed(() => props.game.game_type === 'tournament')
const isEvent = computed(() => props.game.game_type === 'event')
const typeLabel = computed(() => isTourn.value ? 'Tournament' : isEvent.value ? 'Hangout' : 'Game')
const topClass = computed(() => isTourn.value ? 'is-tourn' : isEvent.value ? 'is-event' : 'is-game')
const link = computed(() => isTourn.value ? `/tournaments/${props.game.id}` : `/games/${props.game.id}`)

// Time leads (the timeline already groups by day); a short date tags along so the
// card still reads standalone (home slider, profile) and in the "Anytime" group.
const timeLabel = computed(() => props.game.start_time ? fmt12(props.game.start_time) : (props.game.game_date ? 'All day' : 'Anytime'))
const dateSub = computed(() => props.game.game_date
  ? new Date(props.game.game_date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short' })
  : '')
</script>

<template>
  <router-link :to="link" class="gc" :class="topClass">
    <div class="gc-meta">
      <span class="gc-time">{{ timeLabel }}</span>
      <span v-if="dateSub" class="gc-date-s">{{ dateSub }}</span>
      <span v-if="lifeMeta" class="gc-state" :class="'st-' + lifeState">{{ lifeMeta.label }}</span>
      <span class="gc-type" :class="topClass">{{ typeLabel }}<template v-if="game.sport"> · {{ game.sport }}</template></span>
      <span v-if="game.community_id && game.community_public !== true" class="gc-mem" title="Community members only — join the community to join this event">Members</span>
    </div>
    <div class="gc-title">{{ game.title || game.sport }}</div>
    <!-- People + place context (who's behind it and where — the trust signals) -->
    <div v-if="game.communityName || game.hostName" class="gc-host">
      <template v-if="game.communityName">
        <span class="gc-host-av" :style="game.communityAvatar ? { backgroundImage: `url(${game.communityAvatar})` } : { background: avatarColor(game.communityName) }"><Users v-if="!game.communityAvatar" :size="11" /></span>
        <span class="gc-host-t">By {{ game.communityName }}<template v-if="!/communit(y|ies)\s*$/i.test(game.communityName)"> community</template></span>
      </template>
      <template v-else>
        <span class="gc-host-av" :style="game.hostAvatar ? { backgroundImage: `url(${game.hostAvatar})` } : { background: avatarColor(game.hostName) }">{{ game.hostAvatar ? '' : (game.hostName[0] || '?').toUpperCase() }}</span>
        <span class="gc-host-t">By {{ game.hostName }}</span>
      </template>
    </div>
    <div v-if="game.custom_location_name || game.locationName" class="gc-loc"><MapPin :size="12" /> {{ game.custom_location_name || game.locationName }}</div>
    <div v-if="Number(game.prize_pot) > 0" class="gc-prize"><Gift :size="12" /> {{ game.currency }} {{ Number(game.prize_pot).toLocaleString() }} prize pot</div>
    <!-- Going stack — overlapping avatars of accepted players (+N) -->
    <div v-if="game.goingAvatars && game.goingAvatars.length" class="gc-going">
      <span v-for="(p, i) in game.goingAvatars.slice(0, 4)" :key="i" class="gc-go-av"
        :style="p.avatar ? { backgroundImage: `url(${p.avatar})` } : { background: avatarColor(p.name), color: '#fff' }" :title="p.name">{{ p.avatar ? '' : (p.name || '?')[0].toUpperCase() }}</span>
      <span v-if="game.joinedCount > 4" class="gc-go-more">+{{ game.joinedCount - 4 }}</span>
      <span class="gc-go-t">{{ game.joinedCount }} going</span>
    </div>
    <div class="gc-spacer"></div>
    <div class="gc-foot">
      <span class="gc-price"><Tag :size="12" />
        <template v-if="Number(game.price_per_person) > 0"><b>{{ Number(game.price_per_person).toLocaleString() }} {{ game.currency }}</b> / {{ isTourn ? 'Team' : 'Person' }}</template>
        <template v-else><b>Free</b> entry</template>
      </span>
      <span class="gc-join">{{ game.approval_mode === 'instant' ? 'Join now' : 'Join' }} <ChevronRight :size="14" /></span>
    </div>
  </router-link>
</template>

<style scoped>
/* Accent per type — the type chip and Join pill pick this up. */
.gc { --gc: #1d4ed8; }
.gc.is-tourn { --gc: #e11d48; }
.gc.is-event { --gc: #b45309; }

.gc { display: flex; flex-direction: column; align-self: start; width: 450px; max-width: 100%; justify-self: start; background: var(--surface, #ffffff); border: 1px solid #e8edf3; border-radius: 18px; padding: 15px 17px 14px; text-decoration: none; transition: transform .12s, box-shadow .12s; box-shadow: 0 1px 2px rgba(15,23,42,.05), 0 5px 16px rgba(15,23,42,.07); }
.gc:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(15,23,42,.13); }

/* time · date ........ type chip */
.gc-meta { display: flex; align-items: center; gap: 8px; min-width: 0; flex-wrap: wrap; row-gap: 5px; }
.gc-time { font-size: 13.5px; font-weight: 900; color: var(--gc); letter-spacing: -.01em; }
.gc-date-s { font-size: 12px; font-weight: 700; color: var(--text-light, #94a3b8); white-space: nowrap; }
.gc-type { margin-left: auto; flex-shrink: 0; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--gc); background: color-mix(in srgb, var(--gc) 10%, white); padding: 3px 9px; border-radius: 999px; max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* lifecycle chip (Waitlist / Locked / Live now / Ended / Cancelled) */
.gc-state { flex-shrink: 0; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
.gc-state.st-waitlisted { background: #fef3c7; color: #92400e; }
.gc-state.st-locked { background: #f1f5f9; color: #475569; }
.gc-state.st-active { background: #dcfce7; color: #15803d; }
.gc-state.st-archived { background: #f1f5f9; color: var(--text-light, #94a3b8); }
.gc-state.st-cancelled { background: #fee2e2; color: #b91c1c; }

.gc-title { font-size: 17.5px; font-weight: 900; color: #0f172a; letter-spacing: -.01em; line-height: 1.25; margin-top: 6px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

/* Host + venue context rows (Luma-style trust signals) */
.gc-host { display: flex; align-items: center; gap: 7px; margin-top: 8px; min-width: 0; }
.gc-host-av { width: 20px; height: 20px; border-radius: 50%; background: var(--gc); background-size: cover; background-position: center; color: #fff; font-size: 10.5px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.gc-host-t { font-size: 12.5px; font-weight: 700; color: #475569; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gc-loc { display: flex; align-items: center; gap: 5px; font-size: 12.5px; color: var(--text-light, #94a3b8); font-weight: 600; margin-top: 5px; min-width: 0; }
.gc-loc svg { flex-shrink: 0; }

.gc-prize { align-self: flex-start; display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 800; color: #92400e; background: #fef3c7; border: 1px solid #fde68a; padding: 3px 8px; border-radius: 999px; margin-top: 8px; }

/* Overlapping going-avatars stack */
.gc-going { display: flex; align-items: center; margin-top: 10px; }
.gc-go-av { width: 24px; height: 24px; border-radius: 50%; background: #cbd5e1; background-size: cover; background-position: center; color: #475569; font-size: 10.5px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; border: 2px solid #fff; margin-left: -8px; }
.gc-go-av:first-child { margin-left: 0; }
.gc-go-more { margin-left: -8px; height: 24px; min-width: 24px; padding: 0 6px; border-radius: 999px; background: #f1f5f9; border: 2px solid #fff; color: #475569; font-size: 10.5px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
.gc-go-t { margin-left: 8px; font-size: 12px; font-weight: 700; color: var(--text-muted, #64748b); }

/* price ........ Join */
/* The spacer grows when the card is stretched to match a taller sibling (home
   slider rows), pinning the footer to the card bottom; 12px is the normal gap. */
.gc-spacer { flex: 1 1 auto; min-height: 12px; }
.gc-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-top: 11px; border-top: 1px solid #f1f5f9; }
.gc-price { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; color: var(--text-muted, #64748b); font-weight: 600; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gc-price svg { color: var(--text-light, #94a3b8); flex-shrink: 0; }
.gc-price b { color: #0f172a; font-weight: 800; }
.gc-join { display: inline-flex; align-items: center; gap: 2px; flex-shrink: 0; font-size: 12.5px; font-weight: 800; color: #fff; background: var(--gc); padding: 8px 12px 8px 14px; border-radius: 999px; }
.gc-join svg { transition: transform .12s; }
.gc:hover .gc-join svg { transform: translateX(2px); }

/* Phone: tighter paddings, >=44px Join tap target. Mobile-only — desktop unchanged. */
@media (max-width: 760px) {
  .gc { padding: 13px 14px 12px; }
  .gc-title { font-size: 16px; margin-top: 5px; }
  .gc-host { margin-top: 6px; }
  .gc-host-t, .gc-loc { font-size: 12px; }
  .gc-going { margin-top: 8px; }
  .gc-prize, .gc-instant { margin-top: 6px; font-size: 11px; }
  .gc-spacer { min-height: 10px; }
  .gc-foot { padding-top: 9px; }
  .gc-join { padding: 11px 14px 11px 16px; font-size: 14px; min-height: 44px; }
}

/* Phone: cap the card below the track width so a sliver of the NEXT card
   always peeks — the visual cue that the row scrolls horizontally. */
@media (max-width: 760px) {
  .gc { max-width: 88%; }
}
.gc-mem { display: inline-flex; align-items: center; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: #0f766e; background: #ccfbf1; border-radius: 999px; padding: 3px 8px; margin-inline-start: 6px; }
</style>
