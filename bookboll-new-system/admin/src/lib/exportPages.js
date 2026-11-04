/** Read the whole authorized result set, never just the page on screen.
 * The server still enforces role, business and jurisdiction scope. */
export async function fetchExportPages(fetchPage, query, collection, { limit = 50000 } = {}) {
  const rows = [], seen = new Set(), lookups = {}
  let page = 1
  while (true) {
    const res = await fetchPage({ ...query, page, records_per_page: 100 })
    if (!res.success) throw new Error(res.message || 'Could not prepare the export. Please retry.')
    const data = res.data || {}, paginator = data[collection]
    const batch = Array.isArray(paginator) ? paginator : paginator?.data
    if (!Array.isArray(batch)) throw new Error('The export response was incomplete. Please retry.')
    if (data.truncated) throw new Error('Too many records to export at once. Apply filters and try again.')
    if (paginator?.current_page && Number(paginator.current_page) !== page) throw new Error('The export did not load the next page. Please retry.')
    const before = rows.length
    for (const row of batch) {
      const key = row.id == null ? null : String(row.id)
      if (key != null && seen.has(key)) continue
      if (key != null) seen.add(key)
      rows.push(row)
    }
    for (const [key, value] of Object.entries(data)) {
      if (key === collection || !Array.isArray(value)) continue
      const map = lookups[key] ||= new Map()
      for (const item of value) if (item?.id != null) map.set(String(item.id), item)
    }
    if (rows.length > limit) throw new Error('Too many records to export at once. Apply filters and try again.')
    const total = Number(paginator?.total), last = Number(paginator?.last_page)
    if (Number.isFinite(last) && last > 0 ? page >= last : Number.isFinite(total) ? rows.length >= total : batch.length < 100) {
      if (Number.isFinite(total) && rows.length !== total) throw new Error('Records changed while preparing the export. Please retry.')
      break
    }
    if (rows.length === before || !batch.length) throw new Error('Records changed while preparing the export. Please retry.')
    page++
  }
  return { rows, lookups: Object.fromEntries(Object.entries(lookups).map(([key, map]) => [key, [...map.values()]])) }
}
