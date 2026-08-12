import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const customerComps = new Set(
  fs.readdirSync(path.join(root, 'apps/web/src/customer/components'))
    .filter(f => f.endsWith('.vue'))
)

function walk(dir, out = []) {
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, ent.name)
    if (ent.isDirectory()) walk(p, out)
    else if (/\.(vue|js)$/.test(ent.name)) out.push(p)
  }
  return out
}

for (const file of walk(path.join(root, 'apps/web/src/customer'))) {
  let s = fs.readFileSync(file, 'utf8')
  const before = s
  s = s.replace(
    /from ['"]@bookboll\/shared\/components\/([^'"]+)['"]/g,
    (full, name) => {
      if (customerComps.has(name)) {
        // pages → ../components ; CustomerLayout → ./components ; components → ./ 
        const rel = file.includes(`${path.sep}pages${path.sep}`)
          ? '../components/'
          : file.endsWith(`CustomerLayout.vue`)
            ? './components/'
            : './'
        return `from '${rel}${name}'`
      }
      return full
    },
  )
  if (s !== before) {
    fs.writeFileSync(file, s)
    console.log('fixed', path.relative(root, file))
  }
}
console.log('done')
