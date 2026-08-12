/**
 * imageUpload.js — shared client-side image handling for admin forms.
 *
 *  • compressImage(file)  — canvas resize + JPEG re-encode, shrinking quality until the
 *    blob is under a target size. Keeps uploads small + fast.
 *  • uploadImage(file)    — compress, then upload to imgbb (VITE_IMGBB_KEY) and return a
 *    hosted URL. Returns { url } or { error } (never throws).
 *
 * Used by Listings, LocationModal, and ClassesSubscriptions so every image field is a
 * proper "pick/drop a file → auto-compress → upload" flow instead of pasting a URL.
 */

export async function compressImage(file, { maxDim = 1600, maxBytes = 1024 * 1024 } = {}) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const img = new Image()
    img.onload = () => {
      URL.revokeObjectURL(url)
      let { width, height } = img
      const longest = Math.max(width, height)
      if (longest > maxDim) {
        const s = maxDim / longest
        width = Math.round(width * s)
        height = Math.round(height * s)
      }
      const canvas = document.createElement('canvas')
      canvas.width = width
      canvas.height = height
      const ctx = canvas.getContext('2d')
      ctx.drawImage(img, 0, 0, width, height)
      let quality = 0.85
      const tryCompress = () => {
        canvas.toBlob((blob) => {
          if (!blob) { reject(new Error('Could not compress image')); return }
          if (blob.size > maxBytes && quality > 0.4) { quality -= 0.12; tryCompress() }
          else resolve(blob)
        }, 'image/jpeg', quality)
      }
      tryCompress()
    }
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Could not load image')) }
    img.src = url
  })
}

// Is image upload available? (false when no imgbb key — callers can keep a URL fallback.)
export const imageUploadConfigured = () => !!import.meta.env.VITE_IMGBB_KEY

// Compress + upload one file. Returns { url } on success or { error } on failure.
export async function uploadImage(file) {
  if (!file || !file.type?.startsWith('image/')) return { error: 'Please choose a valid image file.' }
  const apiKey = import.meta.env.VITE_IMGBB_KEY
  if (!apiKey) return { error: 'Image upload isn’t configured — add VITE_IMGBB_KEY to your .env (or paste a URL).' }
  try {
    const compressed = await compressImage(file)
    const base64 = await new Promise((resolve, reject) => {
      const r = new FileReader()
      r.onload = () => resolve(String(r.result).split(',')[1])
      r.onerror = reject
      r.readAsDataURL(compressed)
    })
    const body = new FormData()
    body.append('key', apiKey)
    body.append('image', base64)
    body.append('name', (file.name || 'image').replace(/\.[^.]+$/, ''))
    const res = await fetch('https://api.imgbb.com/1/upload', { method: 'POST', body })
    const json = await res.json()
    if (!json.success) throw new Error(json.error?.message || 'Upload failed')
    return { url: json.data.url }
  } catch (e) {
    return { error: e.message || 'Upload failed. Try again.' }
  }
}
