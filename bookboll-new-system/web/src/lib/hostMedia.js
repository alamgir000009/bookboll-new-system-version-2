export function validateHostMedia(form, { photosRequired = true } = {}) {
  const errors = {}
  if (!String(form?.description || '').trim()) errors.description = 'Add a description so guests know what to expect.'
  if (photosRequired && (!Array.isArray(form?.images) || !form.images.some(photo => typeof photo === 'string' && photo.trim()))) errors.images = 'Upload at least one photo for your event.'
  return errors
}
