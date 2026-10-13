/**
 * useExport — shared CSV download utility
 *
 * Usage:
 *   import { downloadCSV } from '../lib/useExport.js'
 *
 *   downloadCSV(rows, columns, filename)
 *
 *   columns: Array<{ label: string, key?: string, fn?: (row) => string|number }>
 *     - key:  simple property access  (row[key])
 *     - fn:   transform function      (fn(row))
 */
export function downloadCSV(rows, columns, filename) {
  if (!rows || !rows.length) return
  const bom    = '﻿'   // UTF-8 BOM so Excel opens without encoding issues
  const header = columns.map(c => `"${String(c.label).replace(/"/g, '""')}"`).join(',')
  const body   = rows.map(row =>
    columns.map(c => {
      const raw = c.fn ? c.fn(row) : (row[c.key] ?? '')
      return `"${String(raw ?? '').replace(/"/g, '""')}"`
    }).join(',')
  ).join('\n')

  const blob = new Blob([bom + header + '\n' + body], { type: 'text/csv;charset=utf-8;' })
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href     = url
  a.download = `${filename}_${new Date().toISOString().slice(0, 10)}.csv`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

// Export permission is now PER PAGE — gate each page's Export button with the
// relevant CAP.EXPORT_* via the reactive can() from useCurrentUser, e.g.
//   const canExport = computed(() => can(CAP.EXPORT_BOOKINGS))
