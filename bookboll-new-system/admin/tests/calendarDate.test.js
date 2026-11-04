import test from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'

const moduleUrl = new URL('../src/lib/calendarDate.js', import.meta.url).href
for (const [zone, instant, expected] of [
  ['Asia/Qatar', '2026-10-13T22:00:00Z', '2026-10-14'],
  ['America/Los_Angeles', '2026-10-14T02:00:00Z', '2026-10-13'],
  ['UTC', '2026-01-01T00:00:00Z', '2026-01-01'],
]) {
  test(`booking date keeps the local calendar day in ${zone}`, () => {
    const source = `import {localCalendarDate} from ${JSON.stringify(moduleUrl)}; process.stdout.write(localCalendarDate(new Date(${JSON.stringify(instant)})))`
    const result = execFileSync(process.execPath, ['--input-type=module', '-e', source], { env: { ...process.env, TZ: zone }, encoding: 'utf8' })
    assert.equal(result, expected)
  })
}
