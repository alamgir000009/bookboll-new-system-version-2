<script setup>
/**
 * CheckIn.vue — MEMBER check-in (/checkin). Memberships only, by design:
 * subscriptions are where identity abuse happens (one member's plan shared by
 * many people), so the pass proves who's walking in and records the visit.
 * Staff scan the member's pass QR (opens this page with ?code=…) or type the
 * 6-char code; the lookup is scoped to the signed-in business and warns on
 * inactive plans and rapid re-use (sharing signal); one tap logs attendance.
 */
import { ref, onMounted, onBeforeUnmount, computed } from 'vue'
import jsQR from 'jsqr'
import { useRoute } from 'vue-router'
import { loadUserContext, currentUser, currentOwnerUserId, isProviderScoped, useCurrentUser } from '../lib/currentUser.js'
import { CAP } from '../lib/permissions.js'
import { lookupPass, recordAttendance, loadRecentCheckins, loadTodayRoster, markPresent } from '../lib/entryPass.js'
import { fmt12 } from '../lib/slots.js'
import { QrCode, Search, CheckCircle2, AlertTriangle, User, Dumbbell, Loader2, History, ShieldCheck, Camera, X, Users, ChevronDown } from 'lucide-vue-next'

const route = useRoute()
const code = ref('')
const busy = ref(false)
const err = ref('')
const result = ref(null)      // lookupPass() result
const confirmed = ref(false)  // attendance recorded for the current lookup
const recent = ref([])
const override = ref(false)   // staff explicitly admitting a non-active member

const viewer = computed(() => ({
  userId: currentOwnerUserId.value,
  isPlatform: !isProviderScoped.value,
}))

// Read-only tiers (provider_viewer / guard) can LOOK UP a pass but recording an
// attendance is a mutation — gate it on the manage cap like every other write.
const { can } = useCurrentUser()
const canRecord = computed(() => can(CAP.MANAGE_SUBSCRIPTIONS))

// MEMBER card (one QR for all memberships): staff pick the class being attended.
const selectedSubId = ref('')
const selectedMembership = computed(() => {
  if (result.value?.memberships) return result.value.memberships.find(m => m.id === selectedSubId.value) || null
  return result.value?.membership || null
})
// Is the looked-up/selected membership actually active (or a live trial)?
const membershipActive = computed(() => {
  const m = selectedMembership.value
  if (!m) return true
  return ['active', 'trial', 'trialing'].includes(m.status) || m.trial_status === 'in_trial'
})
// Confirm is blocked for a lapsed/cancelled member until staff tick "admit anyway".
const confirmBlocked = computed(() =>
  (!membershipActive.value && !override.value) ||
  (!!result.value?.memberships && !selectedSubId.value))

async function lookup() {
  err.value = ''; result.value = null; confirmed.value = false; override.value = false; selectedSubId.value = ''
  const c = code.value.trim().toUpperCase()
  if (!c) return
  busy.value = true
  const r = await lookupPass(c, viewer.value)
  busy.value = false
  if (r.error) { err.value = r.error; buzz([40, 40, 40]); return }
  result.value = r
  if (r.memberships?.length) selectedSubId.value = r.memberships.length === 1 ? r.memberships[0].id : ''
  buzz(60)
}

async function confirm() {
  if (!result.value?.pass || !canRecord.value || confirmBlocked.value) return
  const m = selectedMembership.value
  busy.value = true
  const r = await recordAttendance({
    passId: result.value.pass.id,
    checkedBy: currentUser.value?.id || null,
    note: override.value && !membershipActive.value ? `admitted despite status "${m?.status}"` : null,
    programId: m?.program_id || null,
    subscriptionId: m?.id || null,
  })
  busy.value = false
  if (r.error) { err.value = r.error; return }
  confirmed.value = true
  buzz([50, 30, 90])   // success pattern — meaningful at a noisy door
  loadRecent()
  loadRoster()
}

// ── Today's classes roster (providers): who's expected, who has arrived ───────
const roster = ref([])
const openSess = ref('')
const marking = ref('')
async function loadRoster() {
  if (!isProviderScoped.value || !currentOwnerUserId.value) { roster.value = []; return }
  roster.value = await loadTodayRoster(currentOwnerUserId.value)
  if (roster.value.length === 1 && !openSess.value) openSess.value = roster.value[0].program.id
}
async function mark(sess, r) {
  if (!canRecord.value || marking.value) return
  marking.value = r.id
  const res = await markPresent(r.sub, sess.program, currentUser.value?.id || null)
  marking.value = ''
  if (res.error) { err.value = res.error; return }
  r.checkedIn = true
  sess.present = sess.roster.filter(x => x.checkedIn).length
  loadRecent()
}

function buzz(pattern) { try { navigator.vibrate?.(pattern) } catch { /* unsupported */ } }

function reset() { code.value = ''; result.value = null; err.value = ''; confirmed.value = false; override.value = false }

async function loadRecent() {
  recent.value = await loadRecentCheckins(currentOwnerUserId.value, !isProviderScoped.value)
}

const fmtD = (d) => d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short' }) : ''
const fmtT = (ts) => ts ? new Date(ts).toLocaleString('en-US', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', hour12: true }) : ''

// ── Camera QR scanning ────────────────────────────────────────────────────────
// Works in EVERY browser with a camera: native BarcodeDetector where available
// (Chrome/Android — fast path), jsQR decoding canvas frames everywhere else
// (iPhone Safari, Firefox, desktop Safari). The pass QR encodes
// `${origin}/checkin?code=XXXXXX`; we parse the code out and run the lookup.
const scanning = ref(false)
const scanErr = ref('')
const videoEl = ref(null)
let stream = null, detector = null, rafId = null, alive = true, cnv = null, cctx = null
const canScan = typeof window !== 'undefined' && !!navigator.mediaDevices?.getUserMedia

function extractCode(raw) {
  const s = String(raw || '').trim()
  try { const u = new URL(s); const q = u.searchParams.get('code'); if (q) return q } catch { /* not a URL */ }
  const m = s.match(/[?&]code=([^&]+)/i)
  if (m) return decodeURIComponent(m[1])
  if (/^[A-Z0-9]{4,12}$/i.test(s)) return s   // a bare code
  return ''
}

async function startScan() {
  scanErr.value = ''
  if (!canScan) { scanErr.value = 'This browser has no camera access — type the code instead.'; return }
  try {
    // Native detector where available (Chrome/Android); jsQR decodes frames
    // via canvas everywhere else (iPhone Safari, Firefox, desktop Safari).
    detector = detector || ('BarcodeDetector' in window ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null)
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
    // The component may have unmounted while the permission prompt was open — the
    // unmount hook already ran (stream was still null then), so release the camera now.
    if (!alive) { stream.getTracks().forEach(t => t.stop()); stream = null; return }
    scanning.value = true
    await Promise.resolve()   // let the <video> mount
    if (videoEl.value) { videoEl.value.srcObject = stream; await videoEl.value.play().catch(() => {}) }
    tick()
  } catch (e) {
    scanning.value = false
    scanErr.value = e?.name === 'NotAllowedError' ? 'Camera permission denied. Allow it or type the code.' : 'Could not open the camera. Type the code instead.'
    stopScan()
  }
}
async function tick() {
  if (!scanning.value || !videoEl.value) return
  try {
    let raws = []
    if (detector) {
      const codes = await detector.detect(videoEl.value)
      raws = (codes || []).map(c => c.rawValue)
    } else if (videoEl.value.readyState >= 2) {
      // jsQR path: decode a downscaled canvas frame (fast enough at ~480px).
      const v = videoEl.value
      const scale = Math.min(1, 480 / (v.videoWidth || 480))
      const w = Math.round((v.videoWidth || 0) * scale), h = Math.round((v.videoHeight || 0) * scale)
      if (w > 0 && h > 0) {
        if (!cnv) { cnv = document.createElement('canvas'); cctx = cnv.getContext('2d', { willReadFrequently: true }) }
        cnv.width = w; cnv.height = h
        cctx.drawImage(v, 0, 0, w, h)
        const img = cctx.getImageData(0, 0, w, h)
        const found = jsQR(img.data, w, h, { inversionAttempts: 'dontInvert' })
        if (found?.data) raws = [found.data]
      }
    }
    const hit = raws.map(r => extractCode(r)).find(Boolean)
    if (hit) { code.value = hit.toUpperCase(); stopScan(); lookup(); return }
  } catch { /* frame not ready */ }
  rafId = requestAnimationFrame(tick)
}
function stopScan() {
  scanning.value = false
  if (rafId) { cancelAnimationFrame(rafId); rafId = null }
  if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null }
}

onMounted(async () => {
  await loadUserContext()
  loadRecent()
  loadRoster()
  const q = String(route.query.code || '').trim()
  if (q) { code.value = q.toUpperCase(); lookup() }
})
onBeforeUnmount(() => { alive = false; stopScan() })
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><QrCode :size="22" style="vertical-align:-4px" /> Member check-in</h1>
        <p class="page-subtitle">Verify a member's identity at the door: scan their membership pass QR with any phone camera (it opens this page), or type their code — then record the visit. Stops shared or expired subscriptions.</p>
      </div>
    </div>

    <div class="ci-grid">
      <div>
        <!-- Code entry -->
        <div class="card ci-entry">
          <button v-if="canScan" type="button" class="ci-scan-cta" @click="startScan">
            <Camera :size="18" /> Scan pass with camera
          </button>
          <div v-if="scanErr" class="ci-scan-err"><AlertTriangle :size="13" /> {{ scanErr }}</div>
          <div class="ci-or"><span>{{ canScan ? 'or enter the code' : 'Enter the pass code' }}</span></div>
          <label class="form-label" for="ci-code">Pass code</label>
          <div class="ci-row">
            <input id="ci-code" v-model="code" class="ci-input" placeholder="e.g. K7M2XQ" maxlength="10"
              autocomplete="off" autocapitalize="characters" spellcheck="false"
              @keyup.enter="lookup" @input="code = code.toUpperCase()" data-no-translate />
            <button type="button" class="btn btn-primary" :disabled="busy || !code.trim()" @click="lookup">
              <Loader2 v-if="busy" :size="15" class="ci-spin" /><Search v-else :size="15" /> Look up
            </button>
          </div>
          <div v-if="err" class="ci-err"><AlertTriangle :size="14" /> {{ err }}</div>
        </div>

        <!-- Camera scanner overlay -->
        <div v-if="scanning" class="ci-cam-back" @click.self="stopScan">
          <div class="ci-cam">
            <div class="ci-cam-head"><span><Camera :size="15" /> Point at the member's pass QR</span>
              <button type="button" class="ci-cam-x" @click="stopScan"><X :size="18" /></button></div>
            <div class="ci-cam-video-wrap">
              <video ref="videoEl" class="ci-cam-video" playsinline muted></video>
              <div class="ci-cam-frame"></div>
            </div>
            <div class="ci-cam-hint">Hold steady — it scans automatically.</div>
          </div>
        </div>

        <!-- Result -->
        <div v-if="result" class="card ci-result">
          <div class="ci-res-head">
            <span class="ci-kind membership"><Dumbbell :size="13" /> Membership</span>
            <span class="ci-code" data-no-translate>{{ result.pass.code }}</span>
          </div>

          <div class="ci-customer">
            <img v-if="result.customer?.avatar_url" :src="result.customer.avatar_url" class="ci-photo" alt="Member profile photo" />
            <div v-else class="ci-avatar"><User :size="22" /></div>
            <div class="ci-cust-info">
              <div class="ci-name">{{ result.customer?.full_name || 'Member' }}</div>
              <div class="ci-sub">{{ result.customer?.email }}<template v-if="result.customer?.phone"> · {{ result.customer.phone }}</template></div>
              <div v-if="result.customer?.avatar_url" class="ci-idhint"><ShieldCheck :size="12" /> Match this photo to the person in front of you.</div>
              <div v-else class="ci-idhint warn"><AlertTriangle :size="12" /> No profile photo on file — check a name on ID instead.</div>
            </div>
          </div>

          <!-- MEMBER card: all memberships at this business — pick the class attended -->
          <div v-if="result.memberships" class="ci-mems">
            <div class="ci-mems-h">{{ result.memberships.length > 1 ? 'Tap the class being attended' : 'Membership' }}</div>
            <label v-for="m in result.memberships" :key="m.id" class="ci-mem" :class="{ on: selectedSubId === m.id }">
              <input type="radio" :value="m.id" v-model="selectedSubId" />
              <span class="ci-mem-body">
                <span class="ci-mem-name">{{ m.programName }}</span>
                <span class="ci-mem-meta">
                  <em class="ci-chip" :class="m.trial_status === 'in_trial' ? 'trial' : (m.status === 'active' ? 'good' : 'warn2')">{{ m.trial_status === 'in_trial' ? 'Free trial' : m.status }}</em>
                  <em v-if="m.nextBilling" class="ci-chip soft">{{ m.trial_status === 'in_trial' ? 'First payment' : 'Paid until' }} {{ fmtD(m.nextBilling) }}</em>
                  <em v-if="m.joiningFee > 0" class="ci-chip" :class="m.feePaid ? 'good' : 'warn2'">{{ m.feePaid ? 'Joining fee paid' : 'Joining fee due' }}</em>
                </span>
              </span>
            </label>
            <div v-if="result.attendance.length" class="ci-fact"><span>Last visit</span><b>{{ fmtT(result.attendance[0].checked_at) }}</b></div>
          </div>

          <div v-else-if="result.membership" class="ci-facts">
            <div class="ci-fact"><span>Program</span><b>{{ result.membership.programName }}</b></div>
            <div class="ci-fact"><span>Status</span><b class="ci-cap">{{ result.membership.trial_status === 'in_trial' ? 'Free trial' : result.membership.status }}</b></div>
            <div v-if="result.membership.next_billing_at" class="ci-fact"><span>{{ result.membership.trial_status === 'in_trial' ? 'First payment' : 'Paid until' }}</span><b>{{ fmtD(result.membership.next_billing_at) }}</b></div>
            <div v-if="result.membership.joiningFee > 0" class="ci-fact"><span>Joining fee</span><b>{{ result.membership.feePaid ? 'Paid ✓' : 'Due with first charge' }}</b></div>
            <div v-if="result.attendance.length" class="ci-fact"><span>Last visit</span><b>{{ fmtT(result.attendance[0].checked_at) }}</b></div>
          </div>

          <div v-for="(w, i) in result.warnings" :key="i" class="ci-warn"><AlertTriangle :size="14" /> {{ w }}</div>

          <!-- Lapsed/cancelled member: check-in is blocked until staff explicitly admit -->
          <label v-if="!membershipActive && canRecord" class="ci-override">
            <input type="checkbox" v-model="override" />
            <span>This membership isn't active — <b>admit anyway</b> (recorded as an override).</span>
          </label>

          <div class="ci-actions">
            <button v-if="!confirmed && canRecord" type="button" class="btn btn-primary ci-confirm" :disabled="busy || confirmBlocked" @click="confirm">
              <CheckCircle2 :size="16" /> {{ confirmBlocked ? 'Membership not active' : 'Confirm check-in' }}
            </button>
            <div v-else-if="confirmed" class="ci-done"><CheckCircle2 :size="17" /> Checked in — attendance recorded.</div>
            <button type="button" class="btn ci-next" @click="reset">Next customer</button>
          </div>
        </div>
        <!-- ── Today's classes: who's expected, who has arrived ── -->
        <div v-if="roster.length" class="card ci-roster">
          <div class="ci-roster-h"><Users :size="15" /> Today's classes</div>
          <div v-for="sess in roster" :key="sess.program.id" class="ci-sess">
            <button type="button" class="ci-sess-head" @click="openSess = openSess === sess.program.id ? '' : sess.program.id">
              <span class="ci-sess-name">{{ sess.program.name }}</span>
              <span class="ci-sess-time">{{ fmt12(String(sess.time).slice(0, 5)) }}</span>
              <span class="ci-sess-count" :class="{ full: sess.present >= sess.expected && sess.expected > 0 }">{{ sess.present }}/{{ sess.expected }} checked in</span>
              <ChevronDown :size="15" class="ci-sess-chev" :class="{ rot: openSess === sess.program.id }" />
            </button>
            <div v-if="openSess === sess.program.id" class="ci-sess-body">
              <div v-if="!sess.roster.length" class="ci-recent-empty">No members enrolled yet.</div>
              <div v-for="r in sess.roster" :key="r.id" class="ci-ros-row">
                <span class="ci-ros-dot" :class="{ in: r.checkedIn }"></span>
                <span class="ci-ros-name">{{ r.name }}</span>
                <em v-if="r.status !== 'active'" class="ci-chip" :class="r.status === 'trial' ? 'trial' : 'warn2'">{{ r.status === 'trial' ? 'Free trial' : r.status }}</em>
                <span class="ci-ros-right">
                  <span v-if="r.checkedIn" class="ci-ros-in"><CheckCircle2 :size="13" /> Here</span>
                  <button v-else-if="canRecord" type="button" class="ci-ros-mark" :disabled="marking === r.id" @click="mark(sess, r)">{{ marking === r.id ? '…' : 'Mark present' }}</button>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent check-ins -->
      <div class="card ci-recent">
        <div class="ci-recent-h"><History :size="15" /> Recent check-ins</div>
        <div v-if="!recent.length" class="ci-recent-empty">No check-ins yet. Confirmed passes appear here.</div>
        <div v-for="l in recent" :key="l.id" class="ci-recent-row">
          <span class="ci-recent-name">{{ l.memberName || 'Member' }}</span>
          <span class="ci-recent-code" data-no-translate>{{ l.pass?.code || '—' }}</span>
          <span class="ci-recent-when">{{ fmtT(l.checked_at) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ci-grid { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 18px; align-items: start; }

.ci-entry { padding: 18px; }
.ci-scan-cta { width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px; margin-bottom: 4px; border: none; border-radius: 12px; background: var(--primary); color: #fff; font-size: 15px; font-weight: 800; cursor: pointer; min-height: 50px; }
.ci-scan-cta:hover { filter: brightness(.96); }
.ci-scan-err { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: #b45309; margin-top: 8px; }
.ci-or { display: flex; align-items: center; text-align: center; color: var(--text-light, #94a3b8); font-size: 12px; font-weight: 600; margin: 12px 0 10px; }
.ci-or::before, .ci-or::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.ci-or span { padding: 0 12px; }
.ci-row { display: flex; gap: 10px; }

/* Camera overlay */
.ci-cam-back { position: fixed; inset: 0; z-index: 80; background: rgba(0,0,0,.85); display: flex; align-items: center; justify-content: center; padding: 16px; }
.ci-cam { width: 100%; max-width: 440px; background: #0f172a; border-radius: 18px; overflow: hidden; }
.ci-cam-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; color: #fff; font-size: 14px; font-weight: 700; }
.ci-cam-head span { display: inline-flex; align-items: center; gap: 7px; }
.ci-cam-x { color: #fff; padding: 4px; display: inline-flex; }
.ci-cam-video-wrap { position: relative; aspect-ratio: 1 / 1; background: #000; }
.ci-cam-video { width: 100%; height: 100%; object-fit: cover; }
.ci-cam-frame { position: absolute; inset: 16%; border: 3px solid rgba(255,255,255,.9); border-radius: 16px; box-shadow: 0 0 0 100vmax rgba(0,0,0,.35); }
.ci-cam-hint { padding: 12px 16px; color: #cbd5e1; font-size: 13px; text-align: center; }

.ci-override { display: flex; align-items: flex-start; gap: 9px; padding: 11px 13px; margin-top: 12px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; font-size: 13px; color: #92400e; cursor: pointer; }
.ci-override input { width: 18px; height: 18px; margin-top: 1px; accent-color: #b45309; flex-shrink: 0; }
.ci-input { flex: 1; min-width: 0; border: 1.5px solid var(--border); border-radius: 10px; padding: 12px 14px; font-size: 20px; font-weight: 800; letter-spacing: .25em; text-transform: uppercase; font-variant-numeric: tabular-nums; }
.ci-input:focus { outline: none; border-color: var(--primary); }
.ci-err { display: flex; align-items: center; gap: 7px; color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 9px 12px; font-size: 13.5px; font-weight: 600; margin-top: 12px; }

.ci-result { margin-top: 16px; padding: 18px; }
.ci-res-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
.ci-kind { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800; padding: 5px 11px; border-radius: 999px; }
.ci-kind.booking { color: #1d4ed8; background: #eff6ff; }
.ci-kind.membership { color: #7c3aed; background: #f5f3ff; }
.ci-code { font-size: 15px; font-weight: 900; letter-spacing: .22em; color: var(--text-muted, #64748b); }

.ci-customer { display: flex; align-items: center; gap: 14px; padding: 12px; background: #f8fafc; border-radius: 12px; }
/* BIG photo on purpose — this is the identity check, not decoration */
.ci-photo { width: 84px; height: 84px; border-radius: 14px; object-fit: cover; flex-shrink: 0; border: 2px solid var(--border, #e2e8f0); }
.ci-avatar { width: 84px; height: 84px; border-radius: 14px; background: var(--border, #e2e8f0); color: var(--text-muted, #64748b); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ci-cust-info { min-width: 0; }
.ci-idhint { display: flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700; color: #15803d; margin-top: 6px; }
.ci-idhint.warn { color: #b45309; }
.ci-name { font-size: 15.5px; font-weight: 800; color: #0f172a; }
.ci-sub { font-size: 12.5px; color: var(--text-muted, #64748b); margin-top: 1px; word-break: break-word; }

.ci-facts { display: flex; flex-direction: column; gap: 0; margin-top: 12px; }
.ci-fact { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding: 8px 2px; border-bottom: 1px solid #f1f5f9; font-size: 13.5px; }
.ci-fact span { color: var(--text-light, #94a3b8); font-weight: 600; flex-shrink: 0; }
.ci-fact b { color: #0f172a; font-weight: 700; text-align: end; }
.ci-cap { text-transform: capitalize; }

/* Member-card membership picker */
.ci-mems { margin-top: 12px; }
.ci-mems-h { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--text-light, #94a3b8); margin-bottom: 8px; }
.ci-mem { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 11px; margin-bottom: 8px; cursor: pointer; transition: border-color .12s, background .12s; }
.ci-mem.on { border-color: var(--primary); background: color-mix(in srgb, var(--primary) 6%, #fff); }
.ci-mem input { width: 17px; height: 17px; margin-top: 2px; accent-color: var(--primary); flex-shrink: 0; }
.ci-mem-body { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
.ci-mem-name { font-size: 14px; font-weight: 800; color: #0f172a; }
.ci-mem-meta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ci-chip { font-style: normal; font-size: 11px; font-weight: 800; padding: 2px 9px; border-radius: 999px; text-transform: capitalize; }
.ci-chip.good { background: #dcfce7; color: #166534; }
.ci-chip.trial { background: #fef3c7; color: #92400e; }
.ci-chip.warn2 { background: #fee2e2; color: #b91c1c; }
.ci-chip.soft { background: #f1f5f9; color: #475569; text-transform: none; }

/* Today's classes roster */
.ci-roster { margin-top: 16px; padding: 16px; }
.ci-roster-h { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 800; color: #334155; margin-bottom: 10px; }
.ci-sess { border: 1px solid var(--border); border-radius: 12px; margin-bottom: 8px; overflow: hidden; }
.ci-sess-head { width: 100%; display: flex; align-items: center; gap: 10px; padding: 11px 13px; background: #f8fafc; border: none; cursor: pointer; text-align: left; }
.ci-sess-name { font-size: 13.5px; font-weight: 800; color: #0f172a; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ci-sess-time { font-size: 12px; font-weight: 700; color: var(--primary); flex-shrink: 0; }
.ci-sess-count { margin-left: auto; font-size: 12px; font-weight: 700; color: var(--text-muted, #64748b); flex-shrink: 0; }
.ci-sess-count.full { color: #166534; }
.ci-sess-chev { color: var(--text-light, #94a3b8); transition: transform .15s; flex-shrink: 0; }
.ci-sess-chev.rot { transform: rotate(180deg); }
.ci-sess-body { padding: 6px 13px 10px; }
.ci-ros-row { display: flex; align-items: center; gap: 8px; padding: 7px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
.ci-ros-row:last-child { border-bottom: none; }
.ci-ros-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--border, #e2e8f0); flex-shrink: 0; }
.ci-ros-dot.in { background: #16a34a; }
.ci-ros-name { font-weight: 700; color: #0f172a; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ci-ros-right { margin-left: auto; flex-shrink: 0; }
.ci-ros-in { display: inline-flex; align-items: center; gap: 4px; color: #166534; font-weight: 700; font-size: 12.5px; }
.ci-ros-mark { font-size: 12px; font-weight: 700; color: var(--primary); background: #f0fdfa; border: 1px solid var(--primary); border-radius: 7px; padding: 4px 10px; cursor: pointer; }
.ci-ros-mark:disabled { opacity: .6; }

.ci-warn { display: flex; align-items: flex-start; gap: 7px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 9px 12px; font-size: 13px; font-weight: 600; margin-top: 10px; }
.ci-warn svg { flex-shrink: 0; margin-top: 1px; }

.ci-actions { display: flex; align-items: center; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
.ci-confirm { display: inline-flex; align-items: center; gap: 7px; }
.ci-done { display: inline-flex; align-items: center; gap: 7px; color: #15803d; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 10px 14px; font-size: 14px; font-weight: 700; }
.ci-next { margin-inline-start: auto; }

.ci-recent { padding: 16px; }
.ci-recent-h { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 800; color: #334155; margin-bottom: 10px; }
.ci-recent-empty { font-size: 12.5px; color: var(--text-light, #94a3b8); line-height: 1.5; }
.ci-recent-row { display: flex; align-items: center; gap: 8px; padding: 7px 0; border-bottom: 1px solid #f1f5f9; font-size: 12.5px; }
.ci-recent-name { font-weight: 700; color: #0f172a; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ci-recent-code { font-weight: 700; letter-spacing: .1em; color: var(--text-light, #94a3b8); font-size: 11px; }
.ci-recent-when { margin-inline-start: auto; color: var(--text-light, #94a3b8); white-space: nowrap; }

.ci-spin { animation: cispin 1s linear infinite; } @keyframes cispin { to { transform: rotate(360deg); } }

@media (max-width: 900px) {
  .ci-grid { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
  .ci-row { flex-direction: column; }
  .ci-input { font-size: 22px; text-align: center; }
  .ci-actions .ci-confirm { flex: 1 1 100%; justify-content: center; padding: 13px; }
  .ci-next { margin-inline-start: 0; flex: 1; justify-content: center; }
  .ci-row .btn, .ci-next { min-height: 44px; justify-content: center; }
}
</style>
