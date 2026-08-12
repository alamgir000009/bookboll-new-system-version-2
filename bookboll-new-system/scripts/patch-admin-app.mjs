import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const srcApp = path.join(root, 'src', 'App.vue')
const dest = path.join(root, 'apps', 'admin', 'src', 'App.vue')

let s = fs.readFileSync(srcApp, 'utf8')
s = s.replace(/from '\.\/lib\//g, "from '@bookboll/shared/lib/")
s = s.replace(/from '\.\/components\//g, "from '@bookboll/shared/components/")
s = s.replace(/import CustomerLayout from '\.\/customer\/CustomerLayout\.vue'\n/, '')
s = s.replace(
  /import \{ User, Menu, X, ChevronRight, LogOut, Moon, Sun \}/,
  'import { User, Menu, X, ChevronRight, ChevronDown, LogOut, Moon, Sun }',
)
s = s.replace(
  /\n  <!-- Public customer storefront[\s\S]*?<CustomerLayout v-else-if="area === 'customer'" \/>\n\n/,
  '\n\n',
)
s = s.replace(/\/\/ Which shell to render:[^\n]+\n/, '// Shell: auth (login) or admin dashboard.\n')
fs.writeFileSync(dest, s)
console.log('Wrote', dest)
