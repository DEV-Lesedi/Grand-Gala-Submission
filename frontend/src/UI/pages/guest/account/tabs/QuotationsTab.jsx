import BookingRow from '../BookingRow'
import EmptyState from '../../../../components/EmptyState'

export default function QuotationsTab({ bookings = [] }) {
  const quotations = bookings.filter((b) => b.status === 'quote_sent')

  if (quotations.length === 0) {
    return <EmptyState title="No quotations yet" description="Send an event enquiry to receive a quote here." actionLabel="Plan an event" actionTo="/events" />
  }

  return (
    <div className="tab-list">
      {quotations.map((b) => (
        <BookingRow key={b.id} {...b} />
      ))}
    </div>
  )
}
