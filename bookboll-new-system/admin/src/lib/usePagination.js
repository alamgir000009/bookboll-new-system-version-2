import { ref, computed, watch } from 'vue'

export const PER_PAGE_OPTS = [10, 25, 50, 100, 250]

export function usePagination(source, resetDepsOrDefaultPerPage = 25, defaultPerPage = 25) {
  // Support both usePagination(source, number) and usePagination(source, resetDepsArray, number)
  const resetDeps = Array.isArray(resetDepsOrDefaultPerPage) ? resetDepsOrDefaultPerPage : []
  const perPageDefault = Array.isArray(resetDepsOrDefaultPerPage) ? defaultPerPage : resetDepsOrDefaultPerPage

  const page = ref(1)
  const perPage = ref(perPageDefault)

  watch([() => source.value?.length, perPage, ...resetDeps], () => { page.value = 1 })

  const totalPages = computed(() => Math.max(1, Math.ceil((source.value?.length || 0) / perPage.value)))

  const paginated = computed(() => {
    const start = (page.value - 1) * perPage.value
    return (source.value || []).slice(start, start + perPage.value)
  })

  const pageFrom = computed(() => (source.value?.length || 0) === 0 ? 0 : (page.value - 1) * perPage.value + 1)
  const pageTo = computed(() => Math.min(page.value * perPage.value, source.value?.length || 0))

  const visiblePages = computed(() => {
    const total = totalPages.value
    const cur = page.value
    if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
    const pages = new Set([1, total, cur])
    if (cur > 1) pages.add(cur - 1)
    if (cur < total) pages.add(cur + 1)
    return Array.from(pages).sort((a, b) => a - b)
  })

  function goTo(p) {
    page.value = Math.min(totalPages.value, Math.max(1, p))
  }

  const goPage = goTo

  return { page, perPage, totalPages, paginated, visiblePages, pageFrom, pageTo, goTo, goPage }
}
