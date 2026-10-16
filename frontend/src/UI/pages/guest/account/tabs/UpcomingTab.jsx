import BookingRow from '../BookingRow'
import EmptyState from '../../../../components/EmptyState'

export default function UpcomingTab({ bookings = [] }) {
  if (bookings.length === 0) {
    return <EmptyState title="No upcoming bookings" description="Book a stay or event to see it here." actionLabel="Browse stays" actionTo="/" />
  }

  return (
    <div className="tab-list">
      {bookings.map((b) => (
        <BookingRow key={b.id} {...b} />
      ))}
    </div>
  )
}
