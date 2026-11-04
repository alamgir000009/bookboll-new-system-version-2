import { apiFetch } from './api.js'
import { financeResponse } from './finance.js'

export async function financeRequest(path, options) {
  return financeResponse(await apiFetch(path, options))
}
