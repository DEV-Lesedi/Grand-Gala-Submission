import { useState } from 'react'
import Button from '../../../components/Button'
import Card from '../../../components/Card'
import EmptyState from '../../../components/EmptyState'
import KpiTile from '../../../components/KpiTile'
import StatusChip from '../../../components/StatusChip'
import Table from '../../../components/Table'
import StaffPageHeader from '../components/StaffPageHeader'
import { panelSlidesOver } from '../components/panelSlidesOver'
import EventCard from './EventCard'
import EventForm from './EventForm'
import EventPanel from './EventPanel'
import ProgressBar from './ProgressBar'
import { formatDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import { EVENT_SALE_STATUSES } from '../../../../lib/statuses'
import { EVENTS_INFO } from '../../../../lib/staff/eventsData'
import { loadedText, useApiData } from '../../../hooks/useApiData'
import { useSave } from '../../../hooks/useSave'
import * as api from '../../../../services/staffEvents'
import './EventsAndTicketsPage.css'

// Each save reloads the page. A refusal shows its reason at the top.
export default function EventsAndTicketsPage() {
  const loaded = useApiData(api.loadStaffEvents, [])
  // One change, then reload. Returns the answer, or null (reason is in problem).
  const { save, problem } = useSave(loaded.reload)
  const [selectedId, setSelectedId] = useState(null) // null = the first event
  const [isPanelOpen, setIsPanelOpen] = useState(false) // below 1280px the panel slides over
  const [isAdding, setIsAdding] = useState(false) // the New event popup

  const events = loaded.data?.events ?? []
  const onSale = events.filter((event) => event.saleStatus === 'on_sale').length
  // Tickets sold and revenue across the upcoming events (the API doesn't split them by month yet)
  const month = {
    tickets: events.reduce((sum, event) => sum + event.ticketsSold, 0),
    revenue: events.reduce((sum, event) => sum + event.revenue, 0),
  }
  const selected = events.find((event) => event.id === selectedId) || events[0]
  const orders = useApiData(() => (selected ? api.loadEventOrders(selected.id) : Promise.resolve([])), [selected?.id ?? null, loaded.data])

  // A new event goes into the list and opens in the panel
  async function addEvent(form) {
    const saved = await save(() => api.createEvent(form, loaded.data?.spaces ?? []))
    if (saved) openEvent(saved)
  }

  // Refund paid orders, cancel unpaid ones, stop the tickets, then show the next event.
  async function cancelSelected() {
    const reason = window.prompt('Why is the event cancelled? Guests see this in their refund email.', 'The event was cancelled')
    if (!reason) return
    await save(() => api.cancelEvent(selected.id, reason))
    setSelectedId(null)
    setIsPanelOpen(false)
  }

  function openEvent(event) {
    setSelectedId(event.id)
    if (panelSlidesOver()) setIsPanelOpen(true)
  }

  const columns = [
    {
      key: 'title',
      header: 'Event',
      render: (event) => (
        <span className="events-name-cell">
          <img src={event.image} alt="" />
          <span>
            <strong>{event.title}</strong>
            <span className="events-space">{event.spaceNames}</span>
          </span>
        </span>
      ),
    },
    { key: 'date', header: 'Date', render: (event) => formatDate(event.date) },
    {
      key: 'sold',
      header: 'Sold',
      render: (event) => (
        <span className="events-sold">
          {event.ticketsSold} / {event.capacity}
          <ProgressBar value={event.ticketsSold} max={event.capacity} label={`${event.title} sold`} />
        </span>
      ),
    },
    { key: 'revenue', header: 'Revenue', render: (event) => formatRand(event.revenue) },
    { key: 'status', header: 'Status', render: (event) => <StatusChip statuses={EVENT_SALE_STATUSES} status={event.saleStatus} /> },
  ]

  return (
    <div className="events-page">
      <StaffPageHeader
        title={EVENTS_INFO.title}
        subtitle={loadedText(loaded, 'events', `${events.length} public events · ${onSale} on sale`)}
        mobileSubtitle={`${events.length} upcoming`}
        actions={<Button onClick={() => setIsAdding(true)}>New event</Button>}
      />

      {problem && (
        <p className="page-problem" role="alert">
          {problem}
        </p>
      )}

      <section className="events-stats">
        <KpiTile label="Tickets sold (upcoming events)" value={month.tickets} />
        <KpiTile label="Ticket revenue (upcoming events)" value={formatRand(month.revenue)} />
      </section>

      {loaded.data && events.length === 0 && (
        <Card>
          <EmptyState title="No upcoming events" description="Past and cancelled events aren't listed." actionLabel="New event" onAction={() => setIsAdding(true)} />
        </Card>
      )}

      <div className="events-body" hidden={events.length === 0}>
        <Card className="staff-table-card events-table-card">
          <Table
            columns={columns}
            rows={events}
            getRowKey={(event) => event.id}
            onRowClick={openEvent}
            selectedKey={selected?.id}
          />
        </Card>

        {/* iPad/phone: cards instead of the table */}
        <section className="events-card-list">
          <h2 className="events-card-title">Upcoming</h2>
          {events.map((event) => (
            <EventCard key={event.id} event={event} onClick={() => openEvent(event)} />
          ))}
        </section>

        {selected && (
          <EventPanel
            event={selected}
            orders={orders.data ?? []}
            isOpen={isPanelOpen}
            onClose={() => setIsPanelOpen(false)}
            onSaveTiers={(tiers) => save(() => api.saveTiers(selected.id, tiers))}
            onRefund={(reference) => save(() => api.refundOrder(reference, 'Refunded by Grand Gala Gardens'))}
            onCancel={cancelSelected}
          />
        )}
      </div>

      {/* The header's button is desktop only */}
      <Button variant="secondary" className="events-mobile-add" onClick={() => setIsAdding(true)}>
        + New event
      </Button>

      {isAdding && <EventForm spaces={loaded.data?.spaces ?? []} onAdd={addEvent} onClose={() => setIsAdding(false)} />}
    </div>
  )
}
