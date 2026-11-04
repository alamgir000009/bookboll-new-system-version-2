<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { customer, isCustomer, selectedCity, selectedCountry } from '../../lib/customerSession.js'
import { ensureSignedIn } from '../../lib/authGate.js'
import { pushToast } from '../../lib/toast.js'
import { uploadImage, imageUploadConfigured } from '../../lib/imageUpload.js'
import { createCommunity } from '../community.js'
import { normalizedPermissions, permissionLabel } from '../../lib/communityAccess.js'
import { useSeo } from '../../lib/seo.js'
import CommunityCard from '../components/CommunityCard.vue'
import CommunityPermissions from '../components/CommunityPermissions.vue'
import CommunityLocationPicker from '../components/CommunityLocationPicker.vue'
import { ArrowLeft, ArrowRight, UsersRound, Check, Lock, Globe2, ImagePlus, Loader, X, Pencil } from 'lucide-vue-next'

useSeo({ title: 'Start a community', path: '/communities/new', noindex: true })
const router = useRouter()
const step = ref(1), saving = ref(false), err = ref(''), ready = ref(false), restored = ref(false), heading = ref(null)
const f = ref({ name: '', sport: '', description: '', cover_image: '', avatar_image: '', is_private: false, settings: normalizedPermissions(), country_id: selectedCountry.value?.id || null, city_id: selectedCity.value?.id || null })
const locationPicker = ref(null)
// Location is part of the form: prefilled from the header, but the host decides.
const location = computed({
  get: () => ({ country_id: f.value.country_id, city_id: f.value.city_id }),
  set: (value) => { f.value.country_id = value?.country_id || null; f.value.city_id = value?.city_id || null },
})
const placeName = computed(() => [
  locationPicker.value?.cityName || (f.value.city_id && selectedCity.value?.id === f.value.city_id ? selectedCity.value?.name : ''),
  locationPicker.value?.countryName || (f.value.country_id && selectedCountry.value?.id === f.value.country_id ? selectedCountry.value?.name : ''),
].filter(Boolean).join(', '))
// What the live preview card shows for the place — the picker's names, so it
// updates the moment a city is chosen, before anything is saved.
const previewCommunity = computed(() => ({
  ...f.value, memberCount: 1,
  city_name: locationPicker.value?.cityName || '', country_name: locationPicker.value?.countryName || '',
}))
const draftKey = computed(() => 'bb_community_draft_' + customer.value?.id)
const STEPS = [
  { title: 'Your community', help: 'Name, interest & where it is' },
  { title: 'Make it yours', help: 'Photos & introduction' },
  { title: 'Access & permissions', help: 'Who joins and contributes' },
  { title: 'Review & create', help: 'Check before going live' },
]
const POPULAR_SPORTS = ['Football', 'Pickleball', 'Padel', 'Tennis', 'Basketball', 'Badminton', 'Running', 'Cricket']
function hasDraftContent(value) {
  return !!(value.name?.trim() || value.sport?.trim() || value.description?.trim() || value.cover_image || value.avatar_image || value.is_private || ['post_permission', 'chat_permission'].some(key => value.settings?.[key] && value.settings[key] !== 'members'))
}
onMounted(async () => {
  if (!isCustomer.value && !(await ensureSignedIn())) { router.replace('/communities'); return }
  try {
    const saved = JSON.parse(sessionStorage.getItem(draftKey.value) || 'null')
    if (saved && hasDraftContent(saved)) {
      // An older draft has no location; the header's choice stands in for it.
      f.value = { ...f.value, ...saved, settings: normalizedPermissions(saved.settings), country_id: saved.country_id || f.value.country_id, city_id: saved.city_id || f.value.city_id }
      restored.value = true
    }
  } catch { /* A broken browser draft must not prevent creating a community. */ }
  ready.value = true
})
function persist() {
  if (!ready.value) return
  try {
    if (hasDraftContent(f.value)) sessionStorage.setItem(draftKey.value, JSON.stringify(f.value))
    else sessionStorage.removeItem(draftKey.value)
  } catch {}
}
watch(f, persist, { deep: true })
async function goStep(value) {
  err.value = ''; step.value = value
  await nextTick()
  heading.value?.scrollIntoView({ block: 'start' })
  heading.value?.focus({ preventScroll: true })
}
function next() {
  err.value = ''
  if (!f.value.name.trim()) { step.value = 1; err.value = 'Enter a community name.'; return }
  if (!f.value.sport.trim()) { step.value = 1; err.value = 'Choose a main interest, or type your own.'; return }
  if (!f.value.country_id || !f.value.city_id) { step.value = 1; err.value = 'Choose the country and city your community is based in.'; return }
  goStep(Math.min(4, step.value + 1))
}
const uploading = ref('')
const avatarFile = ref(null), coverFile = ref(null)
async function pickPhoto(event, kind) {
  const file = event.target.files?.[0]
  if (!file || uploading.value) return
  uploading.value = kind; err.value = ''
  try {
    const result = await uploadImage(file, 'communities')
    if (result.error) { err.value = result.error; return }
    f.value[kind + '_image'] = result.url
  } catch { err.value = 'The photo could not be uploaded. Please try again.' }
  finally { uploading.value = ''; event.target.value = '' }
}
async function publish() {
  if (saving.value || uploading.value) return
  if (!f.value.name.trim() || !f.value.sport.trim() || !f.value.country_id || !f.value.city_id) { step.value = 1; next(); return }
  if (!customer.value?.id) { err.value = 'Sign in again before creating your community.'; return }
  saving.value = true; err.value = ''
  try {
    const result = await createCommunity(customer.value.id, { ...f.value })
    if (result.error) { err.value = result.error; return }
    try { sessionStorage.removeItem(draftKey.value) } catch {}
    pushToast(result.settingsWarning || (result.community.name + ' is live!'), result.settingsWarning ? 'warning' : 'success')
    router.push({ path: '/communities/' + result.community.id, query: result.settingsWarning ? { permissions: 'unconfirmed' } : {} })
  } catch { err.value = 'We could not confirm creation. Check Your communities before trying again.' }
  finally { saving.value = false }
}
</script>
<template>
  <div class="community-journey">
    <router-link to="/communities" class="journey-back"><ArrowLeft :size="17" /> Communities</router-link>
    <header class="journey-title"><h1>Start a community</h1><p>Bring your people together. Set it up your way, then invite your first members.</p></header>
    <p v-if="restored" class="journey-draft" role="status">Your unfinished community has been restored.</p>
    <div class="journey-layout">
      <nav class="journey-progress" aria-label="Creation progress">
        <button v-for="(item, i) in STEPS" :key="item.title" :aria-label="`Step ${i + 1}: ${item.title}`" type="button" :class="{active:step===i+1,done:step>i+1}" :aria-current="step===i+1 ? 'step' : undefined" :disabled="i+1>step || saving" @click="goStep(i+1)"><span class="journey-number"><Check v-if="step>i+1" :size="16" /><template v-else>{{ i+1 }}</template></span><span><strong>{{ item.title }}</strong><small>{{ item.help }}</small></span></button>
        <p class="journey-draft-note">Your draft stays in this browser until you create the community.</p>
      </nav>
      <form class="journey-form" @submit.prevent="step===4 ? publish() : next()">
        <div class="journey-panel">
          <span class="journey-step-count">Step {{ step }} of 4</span>
          <h2 ref="heading" tabindex="-1">{{ STEPS[step-1].title }}</h2>
          <template v-if="step===1">
            <p class="journey-help">Choose a name people will recognize and an interest you share.</p>
            <label class="journey-field" for="community-name">Community name <input id="community-name" v-model="f.name" maxlength="100" autocomplete="off" placeholder="e.g. Doha Pickleball Crew" required /></label>
            <label class="journey-field" for="community-interest">Main interest <input id="community-interest" v-model="f.sport" maxlength="60" placeholder="Choose below or type an interest" required /></label>
            <div class="journey-chips"><button v-for="sport in POPULAR_SPORTS" :key="sport" type="button" :class="{on:f.sport===sport}" :aria-pressed="f.sport===sport" @click="f.sport=sport">{{ sport }}</button></div>
            <div class="journey-field journey-field-block"><span>Where is it based?</span><CommunityLocationPicker ref="locationPicker" v-model="location" id-prefix="community-location" /></div>
          </template>
          <template v-else-if="step===2">
            <p class="journey-help">Help people recognize your group and feel welcome. Everything here is optional.</p>
            <div v-if="imageUploadConfigured()" class="journey-photos">
              <div v-for="kind in ['avatar','cover']" :key="kind"><span class="journey-field">{{ kind==='avatar' ? 'Community photo' : 'Cover photo' }}</span><label class="journey-upload" :class="{busy:!!uploading}"><img v-if="f[kind+'_image']" :src="f[kind+'_image']" alt="" /><Loader v-if="uploading===kind" :size="22" /><ImagePlus v-else :size="22" /><span>{{ f[kind+'_image'] ? 'Change photo' : 'Add photo' }}</span><input type="file" accept="image/*" :disabled="!!uploading" :aria-label="'Upload ' + kind + ' photo'" @change="pickPhoto($event,kind)" /></label><button v-if="f[kind+'_image']" type="button" class="journey-link" @click="f[kind+'_image']=''">Remove photo</button></div>
            </div>
            <label class="journey-field" for="community-story">Introduce your community <span class="journey-optional">Optional</span><textarea id="community-story" v-model="f.description" rows="5" maxlength="3000" placeholder="Who is welcome? What do you enjoy doing together? When do you usually meet?"></textarea></label>
          </template>
          <template v-else-if="step===3">
            <p class="journey-help">Decide who can join and how your group communicates. You can change these settings later.</p>
            <fieldset class="journey-options"><legend>Who can join?</legend><label :class="{on:!f.is_private}"><input type="radio" name="privacy" :checked="!f.is_private" @change="f.is_private=false" /><Globe2 :size="20" /><span><strong>Open community</strong><small>Anyone can join. Your feed is public.</small></span></label><label :class="{on:f.is_private}"><input type="radio" name="privacy" :checked="f.is_private" @change="f.is_private=true" /><Lock :size="20" /><span><strong>Private community</strong><small>Approve join requests. Only members see the feed and chat.</small></span></label></fieldset>
            <CommunityPermissions v-model="f.settings" />
          </template>
          <template v-else>
            <p class="journey-help">Your community will be visible after you create it. You’ll be its owner.</p>
            <div class="journey-review"><section><div><h3>{{ f.name }}</h3><button type="button" @click="goStep(1)"><Pencil :size="14" /> Edit</button></div><p>{{ f.sport }}<template v-if="placeName"> in {{ placeName }}</template></p><p v-if="f.description" class="journey-review-story">{{ f.description }}</p></section><section><div><h3>Access &amp; permissions</h3><button type="button" @click="goStep(3)"><Pencil :size="14" /> Edit</button></div><dl><dt>Joining</dt><dd>{{ f.is_private ? 'Approval required' : 'Anyone can join' }}</dd><dt>Feed posts</dt><dd>{{ permissionLabel(f.settings.post_permission) }}</dd><dt>Group messages</dt><dd>{{ permissionLabel(f.settings.chat_permission) }}</dd></dl></section></div>
            <p class="journey-help">After creation, invite people to join and assign moderators from the Members tab.</p>
          </template>
          <p v-if="err" role="alert" class="journey-error">{{ err }}</p>
        </div>
        <footer class="journey-footer"><button type="button" class="journey-secondary" :disabled="saving" @click="step>1 ? goStep(step-1) : router.push('/communities')"><ArrowLeft :size="16" /> {{ step>1 ? 'Back' : 'Save & exit' }}</button><button class="discover-action" type="submit" :disabled="saving || !!uploading || !ready"><Loader v-if="saving" :size="17" />{{ saving ? 'Creating…' : step===4 ? 'Create community' : 'Continue' }}<ArrowRight v-if="!saving && step<4" :size="16" /></button></footer>
      </form>
      <aside class="journey-preview"><h2>Your community preview</h2><CommunityCard :community="previewCommunity" preview /><p>Your first member is you. Invite your crew once your community is ready.</p></aside>
    </div>
  </div>
</template>

<style scoped>
.journey-field-block { display: block; }
.journey-field-block > span { display: block; margin-bottom: 8px; }
</style>
