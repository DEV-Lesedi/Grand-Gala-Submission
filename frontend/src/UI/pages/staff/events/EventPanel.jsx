import { useState } from 'react'
import Button from '../../../components/Button'
import DetailPanel from '../components/DetailPanel'
import AttendeeList from './AttendeeList'
import CancelEventDialog from './CancelEventDialog'
import TierForm from './TierForm'
import ProgressBar from './ProgressBar'
import { formatDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import './EventPanel.css'

// tiers include sold. orders are this event's ticket orders.
export default function EventPanel({ event, orders, isOpen, onClose, onSaveTiers, onRefund, onCancel }) {
  const [openPopup, setOpenPopup] = useState(null) // null, 'attendees', 'tiers' or 'cancel'

  return (
    <DetailPanel className="event-panel" isOpen={isOpen} onClose={onClose} backLabel="Back to events">
      <div className="event-panel-cover" style={{ backgroundImage: `url(${event.image})` }}>
        <h2 className="event-panel-title">{event.title}</h2>
      </div>
      <p className="event-panel-meta">
        {formatDate(event.date)} · {event.startTime} · {event.spaceNames}
      </p>
      {/* Guest event (/events/:id) in a new tab, so this console stays open */}
      <a href={`/events/${event.id}`} target="_blank" rel="noreferrer" className="event-panel-guest-link">
        View guest page ↗
      </a>

      <section>
        <div className="event-panel-heading-row">
          <h3 className="event-panel-heading">Ticket tiers</h3>
          <button type="button" className="link-button event-panel-edit" onClick={() => setOpenPopup('tiers')} aria-label="Edit ticket tiers">
            Edit
          </button>
        </div>
        <ul className="event-tiers">
          {event.tiers.map((tier) => (
            <li key={tier.id} className="event-tier">
              <span className="event-tier-line">
                <span className="event-tier-name">{tier.name}</span>
                <span>
                  {formatRand(tier.price)} · {tier.sold} of {tier.allocation}
                </span>
              </span>
              <ProgressBar value={tier.sold} max={tier.allocation} label={`${tier.name} sold`} />
            </li>
          ))}
        </ul>
      </section>

      <div className="event-panel-actions">
        <Button onClick={() => setOpenPopup('attendees')}>Attendee list</Button>
        <Button variant="secondary" className="event-panel-cancel" onClick={() => setOpenPopup('cancel')}>
          {event.ticketsSold > 0 ? 'Cancel & refund' : 'Delete event'}
        </Button>
      </div>

      {openPopup === 'tiers' && <TierForm event={event} onSave={onSaveTiers} onClose={() => setOpenPopup(null)} />}
      {openPopup === 'attendees' && (
        <AttendeeList event={event} orders={orders} onRefund={onRefund} onClose={() => setOpenPopup(null)} />
      )}
      {openPopup === 'cancel' && (
        <CancelEventDialog
          event={event}
          onConfirm={() => {
            setOpenPopup(null)
            onCancel()
          }}
          onClose={() => setOpenPopup(null)}
        />
      )}
    </DetailPanel>
  )
}
