import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import Button from '../../../components/Button'
import EmptyState from '../../../components/EmptyState'
import { SkeletonPage } from '../../../components/Skeleton'
import FocusedHeader from '../components/FocusedHeader'
import StaySteps from '../components/StaySteps'
import CheckoutConfirmed from './CheckoutConfirmed'
import CheckoutSummary from './CheckoutSummary'
import PromoCodeBox from './PromoCodeBox'
import { couldNotLoad, useApiData } from '../../../hooks/useApiData'
import { useGuestUser } from '../../../hooks/guestUser'
import { orderFrom, pay } from '../../../../services/checkout'
import { PAYFAST_MODE } from '../../../../services/payments'
import { formatRand } from '../../../../lib/money'
import './CheckoutPage.css'

// { field: message }; empty means ready. Contact fields are for tickets only.
function validate(form, order) {
  const errors = {}
  if (!order.needsContact) return errors
  if (form.fullName.trim().split(/\s+/).length < 2) errors.fullName = 'Your first name and surname, please.'
  if (!/^\S+@\S+\.\S+$/.test(form.email)) errors.email = 'An email address for your tickets.'
  if (!form.phone.trim()) errors.phone = 'A number we can reach you on.'
  return errors
}

// Error sits outside the label so it isn't read as the box's name.
function Field({ name, label, optional = false, error, children }) {
  return (
    <div className="checkout-field">
      <label htmlFor={`checkout-${name}`}>
        {label}
        {optional && <span className="checkout-optional"> (optional)</span>}
      </label>
      {children}
      {error && (
        <span id={`checkout-${name}-error`} className="checkout-error">
          {error}
        </span>
      )}
    </div>
  )
}

// The order with the discount code's line and total, or the order as it is when there's no code
function withDiscount(order, promo) {
  if (!order || !promo) return order
  return {
    ...order,
    lines: [...order.lines, { label: `Discount (${promo.code})`, amount: -promo.discount }],
    total: promo.total,
  }
}

// orderFrom reads the URL; pay creates the booking or ticket order and starts PayFast.
export default function CheckoutPage() {
  const [searchParams] = useSearchParams()
  const { user, loading: userLoading } = useGuestUser()
  const loaded = useApiData(() => orderFrom(searchParams), [searchParams.toString(), user?.id ?? null])
  const { order: loadedOrder, problem, backTo, backLabel, needsSignIn } = loaded.data ?? {}
  // A discount code the API accepted: { code, discount, total } (see PromoCodeBox)
  const [promo, setPromo] = useState(null)
  const order = withDiscount(loadedOrder, promo)

  const [form, setForm] = useState({ fullName: '', email: '', phone: '', requests: '' })
  const [errors, setErrors] = useState({})
  const [isPaid, setIsPaid] = useState(false)
  const [paying, setPaying] = useState(false)
  const [payError, setPayError] = useState('')
  // First Pay's booking or order, so Pay again doesn't create a second one
  const [created, setCreated] = useState(null) // its reference

  // A signed-in guest's details fill the ticket form (they can still change them)
  const contact = {
    fullName: form.fullName || user?.name || '',
    email: form.email || user?.email || '',
    phone: form.phone || user?.phone || '',
    requests: form.requests,
  }

  function handleChange(event) {
    setForm({ ...contact, [event.target.name]: event.target.value })
  }

  async function handleSubmit(event) {
    event.preventDefault()
    const found = validate(contact, order)
    setErrors(found)
    if (Object.keys(found).length > 0) return

    setPaying(true)
    setPayError('')
    const result = await pay(order, { contact, promo, reference: created })
    if (result.reference) setCreated(result.reference)
    if (result.leftForPayFast) return // the browser is on its way to PayFast
    if (result.paid) {
      setIsPaid(true)
      window.scrollTo(0, 0)
    } else {
      setPayError(result.error)
    }
    setPaying(false)
  }

  // The props every box gets: value, change handler, and its error for screen readers
  function field(name) {
    return {
      id: `checkout-${name}`,
      name,
      value: contact[name],
      onChange: handleChange,
      'aria-invalid': Boolean(errors[name]),
      'aria-describedby': errors[name] ? `checkout-${name}-error` : undefined,
    }
  }

  const here = window.location.pathname + window.location.search
  const mustSignIn = order?.needsAccount && !user && !userLoading
  const paidOrder = order && { ...order, reference: created ?? order.reference }

  return (
    <div className="checkout">
      <FocusedHeader title={isPaid ? 'Booking confirmed' : 'Confirm and pay'} note="PayFast secure" />
      {/* A stay shows where it is in Reserve → Details → Pay */}
      {order?.kind === 'stay' && !isPaid && <StaySteps current={2} />}

      {loaded.loading && !loaded.data && <SkeletonPage />}

      {loaded.error && (
        <div className="checkout-problem">
          <EmptyState titleAs="h1" title={couldNotLoad('your order')} description={loaded.error.message} actionLabel="Try again" onAction={loaded.reload} />
        </div>
      )}

      {needsSignIn && (
        <div className="checkout-problem">
          <EmptyState titleAs="h1" title="Sign in to pay for this." actionLabel="Sign in" actionTo={'/sign-in?next=' + encodeURIComponent(here)} />
        </div>
      )}

      {problem && (
        <div className="checkout-problem">
          <EmptyState titleAs="h1" title={problem} actionLabel={backLabel} actionTo={backTo} />
        </div>
      )}

      {order && isPaid && (
        <CheckoutConfirmed order={paidOrder} fullName={contact.fullName || user?.name || 'there'} email={contact.email || user?.email || ''} />
      )}

      {order && !isPaid && mustSignIn && (
        <div className="checkout-problem">
          <EmptyState
            titleAs="h1"
            title="Sign in to book"
            description="Stays and quotations are booked on your account, so you can find them in My bookings."
            actionLabel="Sign in"
            actionTo={'/sign-in?next=' + encodeURIComponent(here)}
          />
        </div>
      )}

      {order && !isPaid && !mustSignIn && (
        <div className="checkout-body">
          <main className="checkout-main">
            <h1>Confirm and pay</h1>
            <p className="checkout-notice">
              <span aria-hidden="true">✓</span> {order.notice}
            </p>

            {/* noValidate: the page checks the fields itself, with its own messages */}
            <form id="checkout-form" onSubmit={handleSubmit} noValidate>
              {/* No contact fields: special requests uses the full width */}
              <div className={order.needsContact ? 'checkout-fields' : 'checkout-fields is-single'}>
                {order.needsContact && (
                  <>
                    <Field name="fullName" label="Full name" error={errors.fullName}>
                      <input {...field('fullName')} autoComplete="name" placeholder="Your full name" />
                    </Field>
                    <Field name="email" label="Email address" error={errors.email}>
                      <input {...field('email')} type="email" autoComplete="email" placeholder="you@email.com" />
                    </Field>
                    <Field name="phone" label="Mobile number" error={errors.phone}>
                      <input {...field('phone')} type="tel" autoComplete="tel" placeholder="+27 82 000 0000" />
                    </Field>
                  </>
                )}
                {order.kind === 'stay' && (
                  <Field name="requests" label="Special requests" optional>
                    <input {...field('requests')} placeholder="Dietary or access needs…" />
                  </Field>
                )}
              </div>

              {/* Promo only on a new stay or new tickets */}
              {order.canUsePromo && (
                <PromoCodeBox
                  order={loadedOrder}
                  applied={promo}
                  onApply={setPromo}
                  onRemove={() => setPromo(null)}
                  locked={Boolean(created)}
                />
              )}

              <h2 className="checkout-pay-heading">Pay with</h2>
              {/* One way to pay: full payment through PayFast (no deposits, no other methods) */}
              <p className="checkout-payfast">
                <strong>
                  <span aria-hidden="true">🔒</span> Card · PayFast
                </strong>
                <span>
                  {PAYFAST_MODE === 'sandbox'
                    ? 'You’ll be sent to PayFast’s test site to pay'
                    : 'Test mode: no money moves, the payment is confirmed straight away'}
                </span>
              </p>

              {payError && (
                <p className="checkout-error checkout-pay-error" role="alert">
                  {payError}
                </p>
              )}

              <Button type="submit" className="checkout-pay" disabled={paying}>
                {paying ? 'Paying…' : `Pay ${formatRand(order.total)}`}
              </Button>
            </form>
          </main>

          <div className="checkout-side">
            <CheckoutSummary order={order} />
            {!order.needsContact && (
              <p className="checkout-account">
                Booking as <strong>{user?.name}</strong> · {user?.email}
              </p>
            )}
          </div>

          {/* Phone: the total and Pay button stay at the bottom while scrolling */}
          <div className="checkout-bar">
            <span>
              <strong>{formatRand(order.total)}</strong>
              Total (ZAR)
            </span>
            <Button type="submit" form="checkout-form" disabled={paying}>
              {paying ? 'Paying…' : 'Pay with PayFast'}
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
