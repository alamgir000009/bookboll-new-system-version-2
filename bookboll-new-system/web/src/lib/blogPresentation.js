// Old imports can contain tag IDs rather than names. Never show those as topics.
export function readableTags(tags = []) {
  return [...new Set(tags.map(tag => typeof tag === 'string' ? tag.trim() : tag?.name?.trim())
    .filter(tag => tag && !/^\d+$/.test(tag) && !/^[\da-f]{8}-[\da-f-]{27,}$/i.test(tag)))]
}

export function browsePosts(posts, query = '') {
  const normalize = value => String(value || '').normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase()
  const terms = normalize(query).trim().split(/\s+/).filter(Boolean)
  return posts.filter(post => {
    const text = normalize([post.title, post.excerpt, post.author_name, ...readableTags(post.tags)].join(' '))
    return terms.every(term => text.includes(term))
  }).slice().sort((a, b) => (Date.parse(b.published_at) || 0) - (Date.parse(a.published_at) || 0))
}
