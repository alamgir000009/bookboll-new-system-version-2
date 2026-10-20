import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

/* A toast reports what just happened — often an error from the server, raised
 * by the dialog the person is still looking at. It sat at z-index 4000, under
 * the upgrade modal's backdrop at 4300, so a failed checkout reported nothing
 * the user could see. It has to be the top layer, above everything. */

function sourceFiles(dir, out = []) {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry)
    if (statSync(path).isDirectory()) sourceFiles(path, out)
    else if (/\.(vue|css|js)$/.test(entry)) out.push(path)
  }
  return out
}

const SRC = new URL('../src', import.meta.url).pathname

function toastZIndex() {
  const css = readFileSync(join(SRC, 'components/ToastHost.vue'), 'utf8')
  const block = css.slice(css.indexOf('.toast-host'))
  return Number(/z-index:\s*(\d+)/.exec(block)?.[1])
}

test('the toast outranks every other layer in the app', () => {
  const toast = toastZIndex()
  assert.ok(Number.isFinite(toast), 'the toast host must declare a z-index')

  const offenders = []
  for (const file of sourceFiles(SRC)) {
    // The toast host is allowed to be the highest; skip itself.
    if (file.endsWith('ToastHost.vue')) continue
    const text = readFileSync(file, 'utf8')
    for (const m of text.matchAll(/z-?[iI]ndex:\s*'?(\d{3,})/g)) {
      const value = Number(m[1])
      // WebsiteEmbed generates a snippet for a PROVIDER's own site, not for
      // this dashboard, so its z-index competes with nothing here.
      if (file.endsWith('WebsiteEmbed.vue')) continue
      if (value >= toast) offenders.push(`${file.replace(SRC, 'src')} → ${value}`)
    }
  }

  assert.deepEqual(offenders, [], `these layers would cover the toast:\n${offenders.join('\n')}`)
})

test('the toast escapes any stacking context a parent might create', () => {
  // z-index alone is not enough: a transform or filter on an ancestor traps
  // it. Teleporting to body keeps it a sibling of the dialogs it must beat.
  const source = readFileSync(join(SRC, 'components/ToastHost.vue'), 'utf8')
  assert.match(source, /<Teleport to="body">/)
})

test('the toast stays clickable while its host does not block the page', () => {
  const source = readFileSync(join(SRC, 'components/ToastHost.vue'), 'utf8')
  assert.match(source.slice(source.indexOf('.toast-host')), /pointer-events: none/)
  assert.match(source.slice(source.indexOf('.toast {')), /pointer-events: auto/)
})
