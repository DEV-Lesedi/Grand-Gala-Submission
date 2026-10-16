import Button from '../../../components/Button'
import Modal from '../../../components/Modal'
import { formatRand } from '../../../../lib/money'

// Tickets sold: cancel and refund everyone. None sold: delete the event.
export default function CancelEventDialog({ event, onConfirm, onClose }) {
  const hasSales = event.ticketsSold > 0

  const footer = (
    <>
      <Button variant="secondary" onClick={onClose}>
        Keep event
      </Button>
      <Button className="btn-danger" onClick={onConfirm}>
        {hasSales ? `Cancel event & refund ${formatRand(event.revenue)}` : 'Delete event'}
      </Button>
    </>
  )

  return (
    <Modal title={hasSales ? `Cancel ${event.title}?` : `Delete ${event.title}?`} footer={footer} onClose={onClose}>
      {hasSales ? (
        <p>
          {event.ticketsSold} tickets · {formatRand(event.revenue)} goes back to the buyers. Unpaid orders are cancelled
          and the event comes off the list. This can't be undone.
        </p>
      ) : (
        <p>No tickets have been sold, so the event is removed. This can't be undone.</p>
      )}
    </Modal>
  )
}
