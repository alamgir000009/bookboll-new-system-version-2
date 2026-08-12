/**
 * Rewire /login /admin /signup links for the split domains.
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

function patch(file, fn) {
  const p = path.join(root, file)
  let s = fs.readFileSync(p, 'utf8')
  const next = fn(s)
  if (next !== s) {
    fs.writeFileSync(p, next)
    console.log('patched', file)
  } else {
    console.log('unchanged', file)
  }
}

// ── Login → signup on web ───────────────────────────────────────────────
patch('apps/admin/src/pages/Login.vue', (s) => {
  if (!s.includes('webSignupUrl')) {
    s = s.replace(
      "import { adminBrand, adminLogoSquareStyle } from '@bookboll/shared/lib/adminBrand.js'",
      "import { adminBrand, adminLogoSquareStyle } from '@bookboll/shared/lib/adminBrand.js'\nimport { webSignupUrl } from '@bookboll/shared/lib/crossApp.js'\nimport { onMounted } from 'vue'\nimport { useRoute } from 'vue-router'",
    )
  }
  // Prefill email from query
  if (!s.includes('useRoute()')) {
    s = s.replace(
      'const router = useRouter()\n\nconst email',
      "const router = useRouter()\nconst route = useRoute()\n\nconst email",
    )
    s = s.replace(
      'const localErr = ref(\'\')\n\nasync function handleLogin',
      `const localErr = ref('')\n\nonMounted(() => {\n  if (route.query.email) email.value = String(route.query.email)\n})\n\nasync function handleLogin`,
    )
  }
  s = s.replace(
    'New business? <router-link to="/signup" class="auth-link">Create an account</router-link>',
    'New business? <a :href="webSignupUrl()" class="auth-link">Create an account</a>',
  )
  // Fix duplicate imports if vue already imported onMounted
  if ((s.match(/from 'vue'/g) || []).length > 1) {
    s = s.replace(/\nimport \{ onMounted \} from 'vue'\nimport \{ useRoute \} from 'vue-router'/, '')
    s = s.replace("import { ref } from 'vue'", "import { ref, onMounted } from 'vue'")
    s = s.replace("import { useRouter } from 'vue-router'", "import { useRouter, useRoute } from 'vue-router'")
  }
  return s
})

// ── Signup → admin handoff ──────────────────────────────────────────────
patch('apps/web/src/pages/Signup.vue', (s) => {
  if (!s.includes('adminAuthCallbackUrl')) {
    s = s.replace(
      "import { BUSINESS_TYPES } from '@bookboll/shared/lib/businessTypes.js'",
      "import { BUSINESS_TYPES } from '@bookboll/shared/lib/businessTypes.js'\nimport { supabase as sbAuth } from '@bookboll/shared/lib/supabase.js'\nimport { adminAuthCallbackUrl, adminLoginUrl } from '@bookboll/shared/lib/crossApp.js'",
    )
  }
  s = s.replace(
    /function goDashboard\(\) \{ router\.replace\('\/admin\?welcome=1'\) \}[^\n]*/,
    `async function goDashboard() {
  const { data: { session } } = await sbAuth.auth.getSession()
  if (session?.access_token && session?.refresh_token) {
    window.location.href = adminAuthCallbackUrl({
      accessToken: session.access_token,
      refreshToken: session.refresh_token,
      welcome: true,
    })
    return
  }
  window.location.href = adminLoginUrl(email.value ? \`email=\${encodeURIComponent(email.value)}\` : '')
}`,
  )
  s = s.replace(
    'Already have an account? <router-link to="/login" class="auth-link">Sign in</router-link>',
    'Already have an account? <a :href="adminLoginUrl()" class="auth-link">Sign in</a>',
  )
  return s
})

function patchMarketing(file) {
  patch(file, (s) => {
    if (!s.includes('adminLoginUrl') && !s.includes('crossApp')) {
      // insert after first import block line that has vue
      if (s.includes("from '@bookboll/shared/lib/auth.js'")) {
        s = s.replace(
          "from '@bookboll/shared/lib/auth.js'",
          "from '@bookboll/shared/lib/auth.js'\nimport { adminLoginUrl, adminDashboardUrl } from '@bookboll/shared/lib/crossApp.js'",
        )
      } else if (s.includes("from '@bookboll/shared/lib/brand.js'")) {
        s = s.replace(
          "from '@bookboll/shared/lib/brand.js'",
          "from '@bookboll/shared/lib/brand.js'\nimport { adminLoginUrl, adminDashboardUrl } from '@bookboll/shared/lib/crossApp.js'",
        )
      } else {
        s = s.replace(
          /(<script setup>\n)/,
          `$1import { adminLoginUrl, adminDashboardUrl } from '@bookboll/shared/lib/crossApp.js'\n`,
        )
      }
    }
    // External admin links
    s = s.replace(/:to="authUser \? '\/admin' : '\/signup'"/g, ':href="authUser ? adminDashboardUrl() : \'/signup\'"')
    // Convert those that became :href on router-link — change tag
    s = s.replace(/<router-link :href="/g, '<a :href="')
    // Simple to="/admin" when authUser
    s = s.replace(/<router-link v-if="authUser" to="\/admin"/g, '<a v-if="authUser" :href="adminDashboardUrl()"')
    s = s.replace(/<router-link v-else to="\/login"/g, '<a v-else :href="adminLoginUrl()"')
    s = s.replace(/<router-link to="\/login"/g, '<a :href="adminLoginUrl()"')
    // Close tags that were router-link but now <a> — only for ones we changed; leave signup as router-link
    // Fix dangling </router-link> after our <a> replacements — do a careful pass:
    // Replace </router-link> that follow admin/login anchors by processing line-by-line is hard.
    // Instead replace common patterns with full open+close.
    return s
  })
}

for (const f of [
  'apps/web/src/pages/ForBusiness.vue',
  'apps/web/src/pages/ForBusinessPricing.vue',
  'apps/web/src/pages/ForBusinessBlog.vue',
  'apps/web/src/pages/ForBusinessBlogPost.vue',
]) patchMarketing(f)

// Fix broken router-link closings: any <a ...>....</router-link> → </a>
for (const f of [
  'apps/web/src/pages/ForBusiness.vue',
  'apps/web/src/pages/ForBusinessPricing.vue',
  'apps/web/src/pages/ForBusinessBlog.vue',
  'apps/web/src/pages/ForBusinessBlogPost.vue',
  'apps/web/src/pages/Signup.vue',
  'apps/admin/src/pages/Login.vue',
]) {
  patch(f, (s) => {
    // Pairing: when we see <a ...> without matching, replace following </router-link> if the open was <a
    // Simpler heuristic: </router-link> immediately after content that started with <a v-if / <a v-else / <a :href
    let out = ''
    const lines = s.split('\n')
    const stack = []
    for (const line of lines) {
      const opensA = (line.match(/<a\b[^>]*>/g) || []).length
      const opensR = (line.match(/<router-link\b[^>]*(?<!\/)>/g) || []).length
      const closesR = (line.match(/<\/router-link>/g) || []).length
      const closesA = (line.match(/<\/a>/g) || []).length
      for (let i = 0; i < opensA; i++) stack.push('a')
      for (let i = 0; i < opensR; i++) stack.push('r')
      let l = line
      if (closesR) {
        let left = closesR
        l = l.replace(/<\/router-link>/g, () => {
          const top = stack.pop()
          left--
          return top === 'a' ? '</a>' : '</router-link>'
        })
      }
      for (let i = 0; i < closesA; i++) {
        if (stack.length && stack[stack.length - 1] === 'a') stack.pop()
      }
      out += l + '\n'
    }
    return out.endsWith('\n') ? out.slice(0, -1) + (s.endsWith('\n') ? '\n' : '') : out
  })
}

// CustomerLayout + RecruitBanner
patch('apps/web/src/customer/CustomerLayout.vue', (s) => {
  if (!s.includes('adminLoginUrl')) {
    s = s.replace(
      /(<script setup>\n)/,
      `$1import { adminLoginUrl } from '@bookboll/shared/lib/crossApp.js'\n`,
    )
  }
  s = s.replace(
    /<router-link v-if="!embedded" to="\/login" class="cust-admin-link">/,
    '<a v-if="!embedded" :href="adminLoginUrl()" class="cust-admin-link">',
  )
  s = s.replace(
    /class="cust-admin-link"><Shield :size="13" \/> \{\{ \$t\('footer\.adminLogin'\) \}\}<\/router-link>/,
    'class="cust-admin-link"><Shield :size="13" /> {{ $t(\'footer.adminLogin\') }}</a>',
  )
  return s
})

patch('apps/web/src/customer/components/RecruitBanner.vue', (s) => {
  if (!s.includes('adminLoginUrl')) {
    s = s.replace(
      /(<script setup>\n)/,
      `$1import { adminLoginUrl } from '@bookboll/shared/lib/crossApp.js'\n`,
    )
  }
  s = s.replace(
    /<router-link to="\/login" class="recruit-signin">Already a partner\? Sign in<\/router-link>/,
    '<a :href="adminLoginUrl()" class="recruit-signin">Already a partner? Sign in</a>',
  )
  return s
})

console.log('cross-link patch done')
