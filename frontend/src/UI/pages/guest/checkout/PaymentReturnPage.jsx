import EmptyState from '../../../components/EmptyState'
import FocusedHeader from '../components/FocusedHeader'
import { rememberedPayment } from '../../../../services/checkout'
import './CheckoutPage.css'

// Return from PayFast proves nothing; the booking confirms only when PayFast notifies the API.
export default function PaymentReturnPage({ cancelled = false }) {
  const payment = rememberedPayment()
  const link = !payment?.reference
    ? '/my-bookings'
    : payment.kind === 'tickets'
      ? `/tickets/${payment.reference}`
      : `/my-bookings/${payment.reference}`

  return (
    <div className="checkout">
      <FocusedHeader title={cancelled ? 'Payment cancelled' : 'Payment received'} note="PayFast secure" />
      <div className="checkout-problem">
        {cancelled ? (
          <EmptyState
            titleAs="h1"
            title="You cancelled the payment"
            description="Nothing was charged. Your dates or tickets are held for a while, so you can pay again from My bookings."
            actionLabel="My bookings"
            actionTo="/my-bookings"
          />
        ) : (
          <EmptyState
            titleAs="h1"
            title="Thanks, PayFast has your payment"
            description="We confirm it as soon as PayFast lets us know, usually within a minute. You'll get an email too."
            actionLabel={payment?.kind === 'tickets' ? 'View my tickets' : 'View my booking'}
            actionTo={link}
          />
        )}
      </div>
    </div>
  )
}
