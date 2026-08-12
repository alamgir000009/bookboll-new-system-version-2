/**
 * Convert apps/web and apps/admin into fully self-contained projects:
 * copy packages/shared into each app's src/, rewrite @bookboll/shared imports,
 * add per-app .env / vite / package.json (no workspaces dependency).
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const sharedSrc = path.join(root, 'packages', 'shared', 'src')

function ensureDir(p) { fs.mkdirSync(p, { recursive: true }) }
function cp(from, to) {
  ensureDir(path.dirname(to))
  fs.cpSync(from, to, { recursive: true })
}
function walk(dir, out = []) {
  if (!fs.existsSync(dir)) return out
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, ent.name)
    if (ent.isDirectory()) walk(p, out)
    else out.push(p)
  }
  return out
}
function readEnv() {
  const p = path.join(root, '.env')
  return fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : ''
}

function depthToSrc(file, appSrc) {
  const rel = path.relative(appSrc, path.dirname(file)).replace(/\\/g, '/')
  if (!rel || rel === '.') return './'
  const ups = rel.split('/').filter(Boolean).map(() => '..').join('/')
  return ups + '/'
}

function rewriteImports(file, appSrc) {
  let s = fs.readFileSync(file, 'utf8')
  const before = s
  const prefix = depthToSrc(file, appSrc)

  s = s.replace(/@bookboll\/shared\/style\.css/g, `${prefix}style.css`)
  s = s.replace(/@bookboll\/shared\/lib\/([^'"`]+)/g, (_, m) => `${prefix}lib/${m}`)
  s = s.replace(/@bookboll\/shared\/components\/([^'"`]+)/g, (_, m) => `${prefix}components/${m}`)
  s = s.replace(/@bookboll\/shared\/customer\/([^'"`]+)/g, (_, m) => `${prefix}customer/${m}`)
  s = s.replace(/@bookboll\/shared\/locales\/([^'"`]+)/g, (_, m) => `${prefix}locales/${m}`)

  if (s !== before) fs.writeFileSync(file, s)
}

function standaloneApp(name, { port, isWeb }) {
  const appRoot = path.join(root, 'apps', name)
  const appSrc = path.join(appRoot, 'src')
  console.log(`\n→ Standalone ${name}`)

  // Copy shared trees (merge into existing src)
  for (const dir of ['lib', 'components', 'locales']) {
    cp(path.join(sharedSrc, dir), path.join(appSrc, dir))
  }
  cp(path.join(sharedSrc, 'style.css'), path.join(appSrc, 'style.css'))

  // Customer domain JS — merge into src/customer (web already has pages/components)
  ensureDir(path.join(appSrc, 'customer'))
  for (const f of fs.readdirSync(path.join(sharedSrc, 'customer'))) {
    if (f.endsWith('.js')) {
      cp(path.join(sharedSrc, 'customer', f), path.join(appSrc, 'customer', f))
    }
  }

  // Rewrite all @bookboll/shared imports in this app
  for (const file of walk(appSrc)) {
    if (/\.(vue|js|css)$/.test(file)) rewriteImports(file, appSrc)
  }

  // Per-app .env
  const env = readEnv()
  const webUrl = isWeb ? 'http://localhost:5173' : 'http://localhost:5173'
  const adminUrl = 'http://localhost:5174'
  const prodNote = isWeb
    ? `# Production: VITE_WEB_URL=https://bookboll.com  VITE_ADMIN_URL=https://admin.bookboll.com\n`
    : `# Production: VITE_WEB_URL=https://bookboll.com  VITE_ADMIN_URL=https://admin.bookboll.com\n`

  let appEnv = env
    .replace(/VITE_WEB_URL=.*/g, `VITE_WEB_URL=${webUrl}`)
    .replace(/VITE_ADMIN_URL=.*/g, `VITE_ADMIN_URL=${adminUrl}`)
  if (!/VITE_WEB_URL=/.test(appEnv)) appEnv += `\nVITE_WEB_URL=${webUrl}\n`
  if (!/VITE_ADMIN_URL=/.test(appEnv)) appEnv += `VITE_ADMIN_URL=${adminUrl}\n`
  fs.writeFileSync(path.join(appRoot, '.env'), prodNote + appEnv.trim() + '\n')
  fs.writeFileSync(path.join(appRoot, '.env.example'), `# Copy to .env and fill in values
VITE_SUPABASE_URL=
VITE_SUPABASE_ANON_KEY=
VITE_IMGBB_KEY=
VITE_WEB_URL=http://localhost:5173
VITE_ADMIN_URL=http://localhost:5174
`)

  // Copy .env.local if present (service role etc. — mainly for scripts on web)
  const envLocal = path.join(root, '.env.local')
  if (fs.existsSync(envLocal)) cp(envLocal, path.join(appRoot, '.env.local'))

  // Vite config — self-contained
  fs.writeFileSync(path.join(appRoot, 'vite.config.js'), `import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  envDir: '.',
  server: {
    port: ${port},
    allowedHosts: ['.trycloudflare.com', '.ngrok-free.app', '.ngrok.io', '.ngrok.app'],
  },
})
`)

  // package.json — no workspace / no @bookboll/shared
  const pkg = {
    name: isWeb ? 'bookboll-web' : 'bookboll-admin',
    private: true,
    version: '0.0.0',
    type: 'module',
    scripts: isWeb
      ? {
          dev: `vite --port ${port}`,
          sitemap: 'node scripts/gen-sitemap.mjs',
          build: 'node scripts/gen-sitemap.mjs && vite build',
          preview: 'vite preview',
        }
      : {
          dev: `vite --port ${port}`,
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
      ...(isWeb ? { 'playwright-core': '^1.61.1' } : {}),
    },
  }
  fs.writeFileSync(path.join(appRoot, 'package.json'), JSON.stringify(pkg, null, 2) + '\n')

  // .gitignore per app
  fs.writeFileSync(path.join(appRoot, '.gitignore'), `node_modules
dist
dist-ssr
*.local
.env.local
.env*.local
.DS_Store
`)

  // README
  fs.writeFileSync(path.join(appRoot, 'README.md'), isWeb
    ? `# Bookboll Web (bookboll.com)

Public marketplace, white-label storefronts, /for-business, and provider signup.

\`\`\`bash
npm install
npm run dev    # http://localhost:5173
npm run build
\`\`\`

Set \`VITE_ADMIN_URL\` to the admin app origin (local: http://localhost:5174, prod: https://admin.bookboll.com).
`
    : `# Bookboll Admin (admin.bookboll.com)

Admin / super-admin dashboard and login. Signup lives on the web app.

\`\`\`bash
npm install
npm run dev    # http://localhost:5174
npm run build
\`\`\`

Set \`VITE_WEB_URL\` to the public site origin (local: http://localhost:5173, prod: https://bookboll.com).
`)

  if (isWeb) {
    // Copy sitemap script into the web app
    ensureDir(path.join(appRoot, 'scripts'))
    let sitemap = fs.readFileSync(path.join(root, 'scripts', 'gen-sitemap.mjs'), 'utf8')
    sitemap = sitemap
      .replace("resolve(ROOT, 'apps', 'web', 'public', 'sitemap.xml')", "resolve(ROOT, 'public', 'sitemap.xml')")
      .replace('apps/web/public/sitemap.xml', 'public/sitemap.xml')
    fs.writeFileSync(path.join(appRoot, 'scripts', 'gen-sitemap.mjs'), sitemap)
  }

  console.log(`  ready: apps/${name} (port ${port})`)
}

standaloneApp('web', { port: 5173, isWeb: true })
standaloneApp('admin', { port: 5174, isWeb: false })

// Thin root package — no workspaces; just convenience scripts that cd into apps
fs.writeFileSync(path.join(root, 'package.json'), `{
  "name": "bookboll-root",
  "private": true,
  "version": "0.0.0",
  "description": "Convenience root. Each app under apps/ is a fully standalone project.",
  "scripts": {
    "dev:web": "npm --prefix apps/web run dev",
    "dev:admin": "npm --prefix apps/admin run dev",
    "build:web": "npm --prefix apps/web run build",
    "build:admin": "npm --prefix apps/admin run build",
    "install:all": "npm --prefix apps/web install && npm --prefix apps/admin install"
  }
}
`)

fs.writeFileSync(path.join(root, 'README.md'), `# Bookboll — two standalone apps

This folder contains **two complete, independent projects**. Each has its own \`package.json\`, \`.env\`, Vite config, and source. They do **not** share a workspace package.

| Project | Domain | Path |
|---|---|---|
| **bookboll-web** | bookboll.com | [\`apps/web\`](apps/web) |
| **bookboll-admin** | admin.bookboll.com | [\`apps/admin\`](apps/admin) |

## Web
\`\`\`bash
cd apps/web
npm install
npm run dev      # :5173
\`\`\`

## Admin
\`\`\`bash
cd apps/admin
npm install
npm run dev      # :5174
\`\`\`

Or from repo root: \`npm run install:all\` then \`npm run dev:web\` / \`npm run dev:admin\`.

Each app reads its own \`.env\`. Cross-links use \`VITE_WEB_URL\` and \`VITE_ADMIN_URL\`.
`)

fs.writeFileSync(path.join(root, 'CLAUDE.md'), `# Bookboll — standalone apps

Two fully independent Vite + Vue 3 projects (no shared workspace package):

- \`apps/web\` → bookboll.com (marketplace, white-label, signup, /for-business)
- \`apps/admin\` → admin.bookboll.com (login + dashboard)

Each app owns: \`package.json\`, \`.env\`, \`vite.config.js\`, \`src/\`, \`public/\`.

\`\`\`bash
cd apps/web && npm install && npm run dev
cd apps/admin && npm install && npm run dev
\`\`\`

Supabase/SQL under \`supabase/\` is shared infrastructure, not an npm dependency.
`)

console.log('\n✓ Both apps are standalone. Run npm install inside each apps/* folder.')
