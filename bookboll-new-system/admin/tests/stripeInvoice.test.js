import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { isStripeOnlinePayment, stripeInvoiceUrl, stripeInvoiceTitle } from '../src/lib/stripeInvoice.js'

const paid = { method: 'online', status: 'paid', stripe_invoice_url: 'https://invoice.stripe.com/i/acct_1/test_abc' }

test('the Stripe invoice action exists only for paid online payments', () => {
  assert.equal(isStripeOnlinePayment(paid), true)
  for (const row of [{ ...paid, method: 'cash' }, { ...paid, status: 'pending' }, { ...paid, method: 'card' }, null]) assert.equal(isStripeOnlinePayment(row), false)
  assert.equal(stripeInvoiceUrl({ ...paid, method: 'cash' }), '')
})

test('only https stripe.com links from the server row open; nothing is built from an ID', () => {
  assert.equal(stripeInvoiceUrl(paid), paid.stripe_invoice_url)
  assert.equal(stripeInvoiceUrl({ ...paid, stripe_invoice_url: null, stripe_receipt_url: 'https://pay.stripe.com/receipts/x' }), 'https://pay.stripe.com/receipts/x')
  for (const url of ['http://invoice.stripe.com/i/x', 'https://stripe.com.evil.example/i/x', 'https://example.com/stripe.com', 'javascript:alert(1)', 'in_123', '', undefined]) {
    assert.equal(stripeInvoiceUrl({ ...paid, stripe_invoice_url: url }), '', String(url))
  }
  assert.equal(stripeInvoiceUrl({ ...paid, stripe_invoice_url: undefined, stripe_invoice_id: 'in_123' }), '')
})

test('a paid online payment without a link shows a disabled, explained action', () => {
  assert.match(stripeInvoiceTitle(paid), /Open Stripe invoice/)
  assert.match(stripeInvoiceTitle({ ...paid, stripe_invoice_url: undefined }), /not available/)
  assert.equal(stripeInvoiceTitle({ ...paid, method: 'cash' }), '')
})

test('the Payments page offers the Stripe invoice in both the table row and the detail drawer', () => {
  const page = readFileSync(new URL('../src/pages/Payments.vue', import.meta.url), 'utf8')
  assert.equal((page.match(/isStripeOnlinePayment\(/g) || []).length >= 2, true)
  assert.ok(page.includes(':disabled="!stripeInvoiceUrl(p)"'))
  assert.ok(page.includes('>Stripe invoice</button>'))
  assert.ok(page.includes("window.open(url, '_blank', 'noopener')"))
})
