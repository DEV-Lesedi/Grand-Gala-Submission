import BookingRow from '../BookingRow'
import EmptyState from '../../../../components/EmptyState'

export default function TicketsTab({ bookings = [] }) {
  const tickets = bookings.filter((b) => b.status === 'tickets')

  if (tickets.length === 0) {
    return <EmptyState title="No tickets yet" description="Tickets you buy for events will show up here." actionLabel="Browse events" actionTo="/events" />
  }

  return (
    <div className="tab-list">
      {tickets.map((b) => (
        <BookingRow key={b.id} {...b} />
      ))}
    </div>
  )
}
