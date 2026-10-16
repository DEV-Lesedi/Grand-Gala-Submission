// PayFast via the API. The API sets the amount and signs the form; this file does not price.
import { apiPost } from './api'
import { ticketAccessHeaders } from './ticketOrders'

// demo (default): no money moves; the API confirms locally (APP_ENV=local).
// sandbox (VITE_PAYFAST=sandbox): guest goes to PayFast, which must reach the API.
export const PAYFAST_MODE = import.meta.env.VITE_PAYFAST === 'sandbox' ? 'sandbox' : 'demo'

// Starts payment. type: 'stay', 'quotation', or 'ticket_order'.
// A signed-out ticket order sends its access token. Returns { url, fields }.
export function startPayment(type, reference) {
  const headers = type === 'ticket_order' ? ticketAccessHeaders(reference) : {}
  return apiPost('/payments/checkout', { type, reference }, { headers })
}

// Demo mode: the API confirms the waiting payment through its real PayFast notice code
export function finishPaymentLocally(checkout) {
  return apiPost('/dev/payfast/complete', { merchantReference: checkout.fields.m_payment_id })
}

// Sandbox mode: posts the API's signed fields to PayFast, leaving the app
export function sendToPayFast(checkout) {
  const form = document.createElement('form')
  form.method = 'post'
  form.action = checkout.url
  for (const [name, value] of Object.entries(checkout.fields)) {
    const input = document.createElement('input')
    input.type = 'hidden'
    input.name = name
    input.value = value
    form.appendChild(input)
  }
  document.body.appendChild(form)
  form.submit()
}
