import { Link } from 'react-router-dom'
import Card from '../../../components/Card'
import KpiTile from '../../../components/KpiTile'
import StatusChip from '../../../components/StatusChip'
import { BOOKING_STATUSES } from '../../../../lib/statuses'
import Table from '../../../components/Table'
import StaffPageHeader from '../components/StaffPageHeader'
import MonthCalendar from './MonthCalendar'
import { loadedText, useApiData } from '../../../hooks/useApiData'
import { apiGet } from '../../../../services/api'
import { formatDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import './TodayPage.css'

const count = (number, word) => `${number} ${word}${number === 1 ? '' : 's'}`

// Table date: a stay starts on check-in, an event on its date.
function toRow(booking) {
  return { ...booking, startDate: booking.checkIn ?? booking.date }
}

const bookingColumns = [
  { key: 'reference', header: 'Ref', render: (booking) => <strong>{booking.reference}</strong> },
  { key: 'guest', header: 'Guest' },
  { key: 'description', header: 'Booking' },
  { key: 'startDate', header: 'Date', render: (booking) => formatDate(booking.startDate) },
  { key: 'status', header: 'Status', render: (booking) => <StatusChip statuses={BOOKING_STATUSES} status={booking.status} /> },
]

export default function TodayPage() {
  const loaded = useApiData(() => apiGet('/staff/today'), [])
  const today = loaded.data
  const ARRIVALS_TODAY = (today?.bookings ?? []).map(toRow).filter((booking) => booking.startDate === today?.date)
  const TODAY_INFO = {
    date: today?.date,
    daySummary: loadedText(
      loaded,
      'today',
      today && `${count(today.arrivals, 'arrival')} · ${count(today.departures, 'departure')} · ${today.awaitingPaymentCount} awaiting payment`,
    ),
    bookingsSummary: today ? `${count(today.arrivals, 'arrival')} · ${count(today.departures, 'departure')}` : '',
  }
  const TODAY_STATS = [
    { label: 'Arrivals today', value: today ? String(today.arrivals) : '–' },
    { label: 'Open enquiries', value: today ? String(today.openEnquiries) : '–' },
    { label: 'Awaiting payment', value: today ? formatRand(today.awaitingPaymentTotal) : '–' },
  ]

  return (
    <div className="today-page">
      {/* No "New quotation": they start from an enquiry */}
      <StaffPageHeader
        title={TODAY_INFO.date ? formatDate(TODAY_INFO.date) : 'Today'}
        subtitle={TODAY_INFO.daySummary}
        mobileTitle={
          <>
            gg connect <span>staff</span>
          </>
        }
        // null, not omitted: hides the phone date. A default applies only if missing.
        mobileSubtitle={null}
      />

      <section className="today-stats">
        {TODAY_STATS.map((stat) => (
          <KpiTile key={stat.label} label={stat.label} value={stat.value} className="today-stat" />
        ))}
      </section>

      {/* Wait for the API date so the calendar opens on the venue's month */}
      {today && <MonthCalendar today={today.date} />}

      <Card className="staff-table-card today-table-card">
        <div className="today-table-header">
          <h2>Today’s bookings</h2>
          <span className="today-table-summary">{TODAY_INFO.bookingsSummary}</span>
          <Link to="/staff/bookings" className="today-table-link">
            All bookings →
          </Link>
        </div>
        <Table columns={bookingColumns} rows={ARRIVALS_TODAY} getRowKey={(booking) => booking.reference} />
      </Card>

      {/* iPad/phone: cards instead of the table */}
      <section className="today-booking-list">
        <h2>Arrivals today</h2>
        {ARRIVALS_TODAY.map((booking) => (
          <Card key={booking.reference} className="today-booking-card">
            <div>
              <p className="today-booking-title">
                {booking.guest} · {booking.reference}
              </p>
              <p className="today-booking-details">
                {booking.description} · {formatDate(booking.startDate)}
              </p>
            </div>
            <StatusChip statuses={BOOKING_STATUSES} status={booking.status} />
          </Card>
        ))}
      </section>
    </div>
  )
}
