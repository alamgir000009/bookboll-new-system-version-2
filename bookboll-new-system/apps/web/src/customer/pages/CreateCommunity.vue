<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { customer, isCustomer, selectedCity, selectedCountry } from '../../lib/customerSession.js'
import { ensureSignedIn } from '../../lib/authGate.js'
import { pushToast } from '../../lib/toast.js'
import { uploadImage, imageUploadConfigured } from '../../lib/imageUpload.js'
import { createCommunity } from '../../customer/community.js'
import { useSeo } from '../../lib/seo.js'
import { ArrowLeft, ArrowRight, UsersRound, Check, Lock, Globe2, ImagePlus, Loader, X } from 'lucide-vue-next'

useSeo({ title: 'Start a community', description: 'Create your own sports community on Bookboll — invite players, post updates and host meetups.', path: '/communities/new', noindex: true })

const router = useRouter()
const step = ref(1)
const saving = ref(false)
const err = ref('')

// Draft survives an accidental refresh (hostDraft.js precedent, but simpler).
const DRAFT_KEY = 'bb_community_draft'
const f = ref({ name: '', sport: '', description: '', cover_image: '', avatar_image: '', is_private: false })
onMounted(async () => {
  if (!isCustomer.value && !(await ensureSignedIn())) { router.push('/communities'); return }
  try { const d = JSON.parse(sessionStorage.getItem(DRAFT_KEY) || 'null'); if (d) f.value = { ...f.value, ...d } } catch { /* ignore */ }
})
function persist() { try { sessionStorage.setItem(DRAFT_KEY, JSON.stringify(f.value)) } catch { /* ignore */ } }

const POPULAR_SPORTS = ['Football', 'Padel', 'Tennis', 'Basketball', 'Volleyball', 'Cricket', 'Badminton', 'Running']

const coverFile = ref(null)
const coverUploading = ref(false)
async function onCoverPick(e) {
  const file = (e.target.files || [])[0]
  if (!file) return
  coverUploading.value = true
  const r = await uploadImage(file, 'communities')
  coverUploading.value = false
  if (r.error) { pushToast(r.error, 'error'); return }
  f.value.cover_image = r.url
  persist()
  if (coverFile.value) coverFile.value.value = ''
}
const avatarFile = ref(null)
const avatarUploading = ref(false)
async function onAvatarPick(e) {
  const file = (e.target.files || [])[0]
  if (!file) return
  avatarUploading.value = true
  const r = await uploadImage(file, 'communities')
  avatarUploading.value = false
  if (r.error) { pushToast(r.error, 'error'); return }
  f.value.avatar_image = r.url
  persist()
  if (avatarFile.value) avatarFile.value.value = ''
}

function next() {
  err.value = ''
  if (step.value === 1) {
    if (!f.value.name.trim()) { err.value = 'Give your community a name.'; return }
    if (!f.value.sport.trim()) { err.value = 'Pick your main sport.'; return }
  }
  persist()
  step.value++
}
function back() { if (step.value > 1) step.value--; else router.push('/communities') }

async function publish() {
  if (saving.value) return
  saving.value = true
  const res = await createCommunity(customer.value.id, {
    ...f.value,
    city_id: selectedCity.value?.id || null,
    country_id: selectedCountry.value?.id || null,
  })
  saving.value = false
  if (res.error) { err.value = res.error; return }
  try { sessionStorage.removeItem(DRAFT_KEY) } catch { /* ignore */ }
  pushToast(`${res.community.name} is live!`)
  router.push(`/communities/${res.community.id}`)
}

const STEPS = ['Basics', 'Look & story', 'Privacy']
</script>

<template>
  <div class="cc">
    <button type="button" class="cback cback-btn" @click="back"><ArrowLeft :size="16" /> Back</button>

    <div class="cc-steps">
      <template v-for="(s, i) in STEPS" :key="s">
        <div class="cc-step" :class="{ on: step === i + 1, done: step > i + 1 }">
          <span class="cc-step-n"><Check v-if="step > i + 1" :size="13" /><template v-else>{{ i + 1 }}</template></span>
          <span class="cc-step-t">{{ s }}</span>
        </div>
        <span v-if="i < STEPS.length - 1" class="cc-step-bar" :class="{ done: step > i + 1 }"></span>
      </template>
    </div>

    <!-- STEP 1: name + sport -->
    <div v-if="step === 1" class="cc-card">
      <div class="cc-head"><UsersRound :size="22" /><h1>Start a community</h1></div>
      <p class="cc-sub">A club for your sport — members follow your updates, chat and play in your meetups.</p>
      <label class="cc-l">Community name *</label>
      <input v-model="f.name" class="cc-in" placeholder="e.g. Doha Padel Crew" @change="persist" />
      <label class="cc-l">Main sport *</label>
      <input v-model="f.sport" class="cc-in" placeholder="Football, Padel…" @change="persist" />
      <div class="cc-chips">
        <button v-for="s in POPULAR_SPORTS" :key="s" type="button" class="cc-chip" :class="{ on: f.sport === s }" @click="f.sport = s; persist()">{{ s }}</button>
      </div>
    </div>

    <!-- STEP 2: profile photo + cover + description -->
    <div v-if="step === 2" class="cc-card">
      <label class="cc-l">Profile photo <span class="cc-opt">(optional)</span></label>
      <div class="cc-avatar-row">
        <label class="cc-avatar" :style="f.avatar_image ? { backgroundImage: `url(${f.avatar_image})` } : {}">
          <template v-if="!f.avatar_image">
            <Loader v-if="avatarUploading" :size="18" class="cc-spin" /><ImagePlus v-else :size="18" />
          </template>
          <input v-if="imageUploadConfigured()" ref="avatarFile" type="file" accept="image/*" class="cc-file" @change="onAvatarPick" />
        </label>
        <div class="cc-avatar-txt">
          <b>{{ f.avatar_image ? 'Looking good!' : 'Add your club\'s logo or photo' }}</b>
          <span>Shown next to your community everywhere — lists, events and posts.</span>
          <button v-if="f.avatar_image" type="button" class="cc-avatar-x" @click="f.avatar_image = ''; persist()"><X :size="12" /> Remove</button>
        </div>
      </div>
      <label class="cc-l">Cover photo <span class="cc-opt">(optional)</span></label>
      <label class="cc-cover" :style="f.cover_image ? { backgroundImage: `url(${f.cover_image})` } : {}">
        <template v-if="!f.cover_image">
          <Loader v-if="coverUploading" :size="20" class="cc-spin" /><ImagePlus v-else :size="20" />
          <span>{{ imageUploadConfigured() ? 'Add a cover photo' : 'Photo uploads not configured' }}</span>
        </template>
        <button v-else type="button" class="cc-cover-x" @click.prevent="f.cover_image = ''; persist()"><X :size="14" /></button>
        <input v-if="imageUploadConfigured()" ref="coverFile" type="file" accept="image/*" class="cc-file" @change="onCoverPick" />
      </label>
      <label class="cc-l">What's this community about? <span class="cc-opt">(optional)</span></label>
      <textarea v-model="f.description" class="cc-in cc-ta" rows="5" placeholder="Who plays, how often, the vibe — this becomes your About section." @change="persist"></textarea>
    </div>

    <!-- STEP 3: privacy -->
    <div v-if="step === 3" class="cc-card">
      <label class="cc-l">Who can join?</label>
      <div class="cc-priv">
        <button type="button" class="cc-priv-opt" :class="{ on: !f.is_private }" @click="f.is_private = false; persist()">
          <Globe2 :size="22" />
          <b>Open</b>
          <span>Anyone can join instantly</span>
        </button>
        <button type="button" class="cc-priv-opt" :class="{ on: f.is_private }" @click="f.is_private = true; persist()">
          <Lock :size="22" />
          <b>Private</b>
          <span>You approve every member</span>
        </button>
      </div>
      <div class="cc-review">
        <div class="cc-review-row"><span>Name</span><b>{{ f.name }}</b></div>
        <div class="cc-review-row"><span>Sport</span><b>{{ f.sport }}</b></div>
        <div class="cc-review-row"><span>City</span><b>{{ selectedCity?.name || '—' }}</b></div>
        <div class="cc-review-row"><span>Joining</span><b>{{ f.is_private ? 'Private — you approve' : 'Open to everyone' }}</b></div>
      </div>
    </div>

    <div v-if="err" class="cc-err">{{ err }}</div>

    <div class="cc-actions">
      <button type="button" class="cc-back" @click="back"><ArrowLeft :size="16" /> Back</button>
      <button v-if="step < 3" type="button" class="cc-next" @click="next">Continue <ArrowRight :size="16" /></button>
      <button v-else type="button" class="cc-next" :disabled="saving" @click="publish">{{ saving ? 'Creating…' : 'Create community' }}</button>
    </div>
  </div>
</template>

<style scoped>
.cc { max-width: 560px; margin: 0 auto; }
.cback { display: inline-flex; align-items: center; gap: 6px; color: var(--brand); text-decoration: none; font-weight: 700; font-size: 14px; margin-bottom: 14px; }
.cback-btn { background: none; border: none; cursor: pointer; padding: 0; font: inherit; font-weight: 700; }
.cc-file { display: none; }
.cc-spin { animation: ccspin 1s linear infinite; } @keyframes ccspin { to { transform: rotate(360deg); } }

.cc-steps { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; }
.cc-step { display: inline-flex; align-items: center; gap: 7px; }
.cc-step-n { width: 24px; height: 24px; border-radius: 50%; background: #f1f5f9; color: var(--text-muted, #64748b); font-size: 12px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.cc-step.on .cc-step-n { background: #0d9488; color: #fff; }
.cc-step.done .cc-step-n { background: #dcfce7; color: #166534; }
.cc-step-t { font-size: 13px; font-weight: 700; color: var(--text-muted, #64748b); }
.cc-step.on .cc-step-t { color: #0f172a; }
.cc-step-bar { flex: 1; height: 2px; background: var(--border, #e2e8f0); border-radius: 2px; }
.cc-step-bar.done { background: #86efac; }

.cc-card { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 18px; padding: 20px; }
.cc-head { display: flex; align-items: center; gap: 10px; }
.cc-head svg { color: #0d9488; }
.cc-head h1 { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; }
.cc-sub { font-size: 13.5px; color: var(--text-muted, #64748b); margin: 6px 0 4px; }
.cc-l { display: block; font-size: 13px; font-weight: 800; color: #334155; margin: 14px 0 6px; }
.cc-opt { font-weight: 600; color: var(--text-light, #94a3b8); }
.cc-in { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 14px; font: inherit; font-size: 16px; outline: none; background: none; color: #0f172a; }
.cc-in:focus { border-color: #0d9488; }
.cc-ta { resize: vertical; }
.cc-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.cc-chip { border: 1.5px solid var(--border, #e2e8f0); background: none; color: #475569; font-weight: 700; font-size: 13px; padding: 7px 14px; border-radius: 999px; cursor: pointer; }
.cc-chip.on { background: #0d9488; border-color: #0d9488; color: #fff; }

.cc-avatar-row { display: flex; align-items: center; gap: 14px; }
.cc-avatar { position: relative; width: 76px; height: 76px; flex-shrink: 0; border-radius: 50%; border: 1.5px dashed var(--border, #e2e8f0); background: #f8fafc center/cover; color: var(--text-light, #94a3b8); display: flex; align-items: center; justify-content: center; cursor: pointer; }
.cc-avatar-txt { display: flex; flex-direction: column; gap: 3px; }
.cc-avatar-txt b { font-size: 13.5px; color: #0f172a; }
.cc-avatar-txt span { font-size: 12.5px; color: var(--text-muted, #64748b); line-height: 1.45; }
.cc-avatar-x { align-self: flex-start; display: inline-flex; align-items: center; gap: 4px; background: none; border: none; padding: 2px 0; font: inherit; font-size: 12.5px; font-weight: 700; color: #dc2626; cursor: pointer; }
.cc-cover { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; height: 140px; border: 1.5px dashed var(--border, #e2e8f0); border-radius: 14px; color: var(--text-light, #94a3b8); font-size: 13.5px; font-weight: 700; cursor: pointer; background: #f8fafc center/cover; }
.cc-cover-x { position: absolute; top: 8px; right: 8px; width: 26px; height: 26px; border-radius: 50%; border: none; background: rgba(15,23,42,.72); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }

.cc-priv { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.cc-priv-opt { display: flex; flex-direction: column; align-items: center; gap: 5px; border: 2px solid var(--border, #e2e8f0); background: none; border-radius: 14px; padding: 18px 10px; cursor: pointer; font: inherit; color: #475569; }
.cc-priv-opt b { font-size: 15px; color: #0f172a; }
.cc-priv-opt span { font-size: 12px; color: var(--text-muted, #64748b); text-align: center; }
.cc-priv-opt.on { border-color: #0d9488; background: #f0fdfa; }
.cc-priv-opt.on svg { color: #0d9488; }

.cc-review { margin-top: 18px; background: #f8fafc; border-radius: 12px; padding: 12px 14px; }
.cc-review-row { display: flex; justify-content: space-between; gap: 10px; font-size: 13.5px; padding: 4px 0; }
.cc-review-row span { color: var(--text-muted, #64748b); }
.cc-review-row b { color: #0f172a; text-align: right; overflow-wrap: anywhere; }

.cc-err { font-size: 13.5px; color: #dc2626; font-weight: 700; margin-top: 12px; }
.cc-actions { display: flex; gap: 10px; margin-top: 16px; }
.cc-back { display: inline-flex; align-items: center; gap: 6px; background: none; border: 1.5px solid var(--border, #e2e8f0); color: var(--text-muted, #64748b); font: inherit; font-weight: 700; padding: 12px 18px; border-radius: 12px; cursor: pointer; }
.cc-next { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 7px; background: #0d9488; color: #fff; font: inherit; font-weight: 800; font-size: 15px; padding: 13px; border: none; border-radius: 12px; cursor: pointer; }
.cc-next:disabled { opacity: .6; }
@media (max-width: 560px) { .cc-step-t { display: none; } .cc-step.on .cc-step-t { display: inline; } }
</style>
