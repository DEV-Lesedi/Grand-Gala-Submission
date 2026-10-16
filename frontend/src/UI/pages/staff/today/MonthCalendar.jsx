import { useState } from 'react'
import Card from '../../../components/Card'
import StatusChip from '../../../components/StatusChip'
import { BOOKING_STATUSES } from '../../../../lib/statuses'
import { monthCells } from '../../../../lib/bookingCalendar'
import { addDays, addMonths, formatDayDate, formatMonth, startOfMonth } from '../../../../lib/dates'
import { couldNotLoad, useApiData } from '../../../hooks/useApiData'
import { apiGet } from '../../../../services/api'
import './MonthCalendar.css'

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

// How many entries fit in a day's box before it says "+2 more"
const SHOWN_PER_DAY = 2

// today is the venue's "YYYY-MM-DD". Stays span nights; an event is one day.
// Only confirmed, completed, or unpaid bookings with a running hold.
export default function MonthCalendar({ today }) {
  const [month, setMonth] = useState(startOfMonth(today))
  const [selected, setSelected] = useState(today)
  const lastDay = addDays(addMonths(month, 1), -1)
  const loaded = useApiData(() => apiGet(`/staff/calendar?from=${month}&to=${lastDay}`), [month])

  // { '2026-10-13': { date, mode, bookings, events, blackouts }, ... }
  const daysByDate = {}
  for (const day of loaded.data?.days ?? []) {
    daysByDate[day.date] = day
  }
  const selectedDay = daysByDate[selected]

  function goToMonth(newMonth) {
    setMonth(newMonth)
    setSelected(newMonth === startOfMonth(today) ? today : newMonth)
  }

  return (
    <Card className="month-calendar">
      <div className="month-calendar-header">
        <h2>{formatMonth(month)}</h2>
        {loaded.loading && <span className="month-calendar-loading">Loading…</span>}
        <div className="month-calendar-nav">
          <button type="button" onClick={() => goToMonth(addMonths(month, -1))} aria-label="Previous month">
            ←
          </button>
          <button type="button" onClick={() => goToMonth(startOfMonth(today))}>
            Today
          </button>
          <button type="button" onClick={() => goToMonth(addMonths(month, 1))} aria-label="Next month">
            →
          </button>
        </div>
      </div>

      {loaded.error && <p className="page-problem">{couldNotLoad('the calendar', loaded.error)}</p>}

      <div className="month-calendar-legend">
        <span className="is-stay">Stay</span>
        <span className="is-event">Private event</span>
        <span className="is-ticketed">Ticketed event</span>
        <span className="is-blackout">Blocked</span>
      </div>

      <div className="month-calendar-grid">
        {WEEKDAYS.map((weekday) => (
          <div key={weekday} className="month-calendar-weekday">
            {weekday}
          </div>
        ))}
        {monthCells(month).map((date, index) => {
          if (date === null) return <div key={`blank-${index}`} className="month-calendar-blank" />
          const entries = dayEntries(daysByDate[date])
          const classes = ['month-calendar-day']
          if (date === today) classes.push('is-today')
          if (date === selected) classes.push('is-selected')
          return (
            <button key={date} type="button" className={classes.join(' ')} onClick={() => setSelected(date)}>
              <span className="month-calendar-number">{Number(date.slice(8))}</span>
              {entries.slice(0, SHOWN_PER_DAY).map((entry) => (
                <span key={entry.key} className={`month-calendar-entry is-${entry.kind}`}>
                  {entry.label}
                </span>
              ))}
              {entries.length > SHOWN_PER_DAY && (
                <span className="month-calendar-more">+{entries.length - SHOWN_PER_DAY} more</span>
              )}
              {/* Phone: no room for words, so one dot per kind */}
              <span className="month-calendar-dots">
                {[...new Set(entries.map((entry) => entry.kind))].map((kind) => (
                  <span key={kind} className={`month-calendar-dot is-${kind}`} />
                ))}
              </span>
            </button>
          )
        })}
      </div>

      <section className="month-calendar-day-list" aria-live="polite">
        <h3>{formatDayDate(selected)}</h3>
        {dayEntries(selectedDay).length === 0 ? (
          <p className="month-calendar-empty">Nothing booked.</p>
        ) : (
          <ul>
            {selectedDay.bookings.map((booking) => (
              <li key={'b' + booking.reference}>
                <span className={`month-calendar-dot is-${selectedDay.mode === 'event' ? 'event' : 'stay'}`} />
                <div>
                  <p className="month-calendar-item-title">
                    {booking.guest} · {booking.reference}
                  </p>
                  <p className="month-calendar-item-details">{booking.description}</p>
                </div>
                <StatusChip statuses={BOOKING_STATUSES} status={booking.status} />
              </li>
            ))}
            {selectedDay.events.map((event) => (
              <li key={'e' + event.id}>
                <span className="month-calendar-dot is-ticketed" />
                <div>
                  <p className="month-calendar-item-title">{event.title}</p>
                  <p className="month-calendar-item-details">
                    {event.space}
                    {event.startTime && ` · ${event.startTime} – ${event.endTime}`}
                  </p>
                </div>
                <StatusChip statuses={BOOKING_STATUSES} status={event.status} />
              </li>
            ))}
            {selectedDay.blackouts.map((blackout) => (
              <li key={'x' + blackout.id}>
                <span className="month-calendar-dot is-blackout" />
                <div>
                  <p className="month-calendar-item-title">{blackout.space} blocked</p>
                  {blackout.reason && <p className="month-calendar-item-details">{blackout.reason}</p>}
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </Card>
  )
}

// Bookings, then ticketed events, then blackouts. Event-day bookings count as events.
function dayEntries(day) {
  if (!day) return []
  const entries = []
  for (const booking of day.bookings) {
    entries.push({ key: 'b' + booking.reference, kind: day.mode === 'event' ? 'event' : 'stay', label: booking.guest })
  }
  for (const event of day.events) {
    entries.push({ key: 'e' + event.id, kind: 'ticketed', label: event.title })
  }
  for (const blackout of day.blackouts) {
    entries.push({ key: 'x' + blackout.id, kind: 'blackout', label: blackout.space })
  }
  return entries
}
