/**
 * Extract provider B2B surface into standalone apps/business.
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const web = path.join(root, 'apps', 'web')
const admin = path.join(root, 'apps', 'admin')
const biz = path.join(root, 'apps', 'business')

function ensureDir(p) { fs.mkdirSync(p, { recursive: true }) }
function cp(from, to) {
  ensureDir(path.dirname(to))
  fs.cpSync(from, to, { recursive: true })
}
function write(p, s) { ensureDir(path.dirname(p)); fs.writeFileSync(p, s) }
function rm(p) { if (fs.existsSync(p)) fs.rmSync(p, { recursive: true, force: true }) }

if (fs.existsSync(biz)) {
  console.log('apps/business already exists — refreshing pages/entry from web…')
} else {
  console.log('→ Creating apps/business from web base…')
  ensureDir(biz)
  for (const dir of ['src/lib', 'src/components', 'src/locales', 'src/assets', 'public']) {
    const from = path.join(web, dir)
    if (fs.existsSync(from)) cp(from, path.join(biz, dir))
  }
  cp(path.join(web, 'src', 'style.css'), path.join(biz, 'src', 'style.css'))
  if (fs.existsSync(path.join(web, '.env.local'))) cp(path.join(web, '.env.local'), path.join(biz, '.env.local'))
}

// Business pages
const pages = [
  'ForBusiness.vue',
  'ForBusinessPricing.vue',
  'ForBusinessBlog.vue',
  'ForBusinessBlogPost.vue',
  'ProviderTerms.vue',
  'Signup.vue',
]
ensureDir(path.join(biz, 'src', 'pages'))
for (const f of pages) {
  cp(path.join(web, 'src', 'pages', f), path.join(biz, 'src', 'pages', f))
}

// package.json / vite / env / gitignore / vercel / readme
write(path.join(biz, 'package.json'), JSON.stringify({
  name: 'bookboll-business',
  private: true,
  version: '0.0.0',
  type: 'module',
  scripts: {
    dev: 'vite --port 5175',
    build: 'vite build',
    preview: 'vite preview',
  },
  dependencies: {
    '@supabase/supabase-js': '^2.105.3',
    '@unhead/vue': '^1.11.20',
    jsqr: '^1.4.0',
    leaflet: '^1.9.4',
    'lucide-vue-next': '^1.0.0',
    qrcode: '^1.5.4',
    vue: '^3.4.38',
    'vue-i18n': '^9.14.5',
    'vue-router': '^4.6.4',
  },
  devDependencies: {
    '@vitejs/plugin-vue': '^5.1.3',
    vite: '^5.4.2',
  },
}, null, 2) + '\n')

write(path.join(biz, 'vite.config.js'), `import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  envDir: '.',
  server: {
    port: 5175,
    allowedHosts: ['.trycloudflare.com', '.ngrok-free.app', '.ngrok.io', '.ngrok.app'],
  },
})
`)

write(path.join(biz, 'index.html'), `<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/svg+xml" href="/logo.svg?v=3" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Bookboll for Business</title>
    <meta name="description" content="Free booking website and marketplace listing for sports venues, gyms and academies." />
    <meta name="theme-color" content="#0f766e" />
  </head>
  <body>
    <div id="app"></div>
    <script type="module" src="/src/main.js"></script>
  </body>
</html>
`)

write(path.join(biz, '.env'), `# bookboll-business — business.bookboll.com
# Production: VITE_WEB_URL=https://bookboll.com  VITE_BUSINESS_URL=https://business.bookboll.com  VITE_ADMIN_URL=https://admin.bookboll.com

VITE_SUPABASE_URL=https://juoljpumvcfptzbeewom.supabase.co
VITE_SUPABASE_ANON_KEY=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Imp1b2xqcHVtdmNmcHR6YmVld29tIiwicm9sZSI6ImFub24iLCJpYXQiOjE3Nzk5MzUzODMsImV4cCI6MjA5NTUxMTM4M30.z8XQ_UcjA_GGNNxmR7Ahj5yU4QYzqAwxN5-tgxJ5zWo
VITE_IMGBB_KEY=741b2a4a7d79d1403b645fb9eaf4be63

VITE_WEB_URL=http://localhost:5173
VITE_BUSINESS_URL=http://localhost:5175
VITE_ADMIN_URL=http://localhost:5174
`)

write(path.join(biz, '.env.example'), `VITE_SUPABASE_URL=
VITE_SUPABASE_ANON_KEY=
VITE_IMGBB_KEY=
VITE_WEB_URL=http://localhost:5173
VITE_BUSINESS_URL=http://localhost:5175
VITE_ADMIN_URL=http://localhost:5174
`)

write(path.join(biz, '.gitignore'), `node_modules
dist
dist-ssr
*.local
.env.local
.env*.local
.DS_Store
`)

write(path.join(biz, 'vercel.json'), `{
  "rewrites": [
    { "source": "/((?!assets/|.*\\\\..*).*)", "destination": "/index.html" }
  ]
}
`)

write(path.join(biz, 'README.md'), `# Bookboll Business (business.bookboll.com)

Provider marketing site: landing, pricing, blog, terms, and signup.

\`\`\`bash
npm install
npm run dev    # http://localhost:5175
npm run build
\`\`\`

- Sign in / dashboard → \`VITE_ADMIN_URL\` (admin.bookboll.com)
- Consumer marketplace → \`VITE_WEB_URL\` (bookboll.com)
`)

// App entry
write(path.join(biz, 'src', 'App.vue'), `<script setup>
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import ToastHost from './components/ToastHost.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import InputDialog from './components/InputDialog.vue'
import { loadAdminBrand } from './lib/adminBrand.js'
import { useHead } from '@unhead/vue'

const route = useRoute()
onMounted(() => { loadAdminBrand() })

useHead(computed(() => route.meta?.indexable ? {} : {
  title: 'Bookboll for Business',
  meta: [{ name: 'robots', content: 'noindex, nofollow' }],
}))
</script>

<template>
  <router-view />
  <ToastHost />
  <ConfirmDialog />
  <InputDialog />
</template>
`)

write(path.join(biz, 'src', 'main.js'), `import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import { createHead } from '@unhead/vue'
import './style.css'
import App from './App.vue'
import { installUnsavedGuards } from './lib/unsavedGuard.js'
import { initAuth, authUser, authLoading } from './lib/auth.js'
import { i18n } from './lib/i18n.js'
import { initAutoTranslate } from './lib/autoTranslate.js'
import { adminDashboardUrl } from './lib/crossApp.js'
import { loadAdminBrand } from './lib/adminBrand.js'

import ForBusiness from './pages/ForBusiness.vue'
import ForBusinessPricing from './pages/ForBusinessPricing.vue'
import ForBusinessBlog from './pages/ForBusinessBlog.vue'
import ForBusinessBlogPost from './pages/ForBusinessBlogPost.vue'
import ProviderTerms from './pages/ProviderTerms.vue'
import Signup from './pages/Signup.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior() { return { top: 0 } },
  routes: [
    { path: '/', component: ForBusiness, meta: { indexable: true, allowAuthed: true } },
    { path: '/pricing', component: ForBusinessPricing, meta: { indexable: true, allowAuthed: true } },
    { path: '/blog', component: ForBusinessBlog, meta: { indexable: true, allowAuthed: true } },
    { path: '/blog/:slug', component: ForBusinessBlogPost, meta: { indexable: true, allowAuthed: true } },
    { path: '/terms', component: ProviderTerms, meta: { indexable: true, allowAuthed: true } },
    { path: '/signup', component: Signup },

    // Legacy path aliases (old bookboll.com/for-business/* bookmarks)
    { path: '/for-business', redirect: '/' },
    { path: '/for-business/pricing', redirect: '/pricing' },
    { path: '/for-business/blog', redirect: '/blog' },
    { path: '/for-business/blog/:slug', redirect: to => \`/blog/\${to.params.slug}\` },
    { path: '/for-business/terms', redirect: '/terms' },
  ],
})

router.beforeEach(async (to) => {
  if (authLoading.value) await initAuth()
  // Signed-in providers opening /signup go to the admin dashboard
  if (to.path === '/signup' && authUser.value && !to.meta?.allowAuthed) {
    window.location.replace(adminDashboardUrl('/admin'))
    return false
  }
})

installUnsavedGuards(router)
loadAdminBrand()
createApp(App).use(router).use(i18n).use(createHead()).mount('#app')
initAutoTranslate()
`)

console.log('✓ apps/business scaffolded')
