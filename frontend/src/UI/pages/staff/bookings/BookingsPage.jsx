import { useState } from 'react'
import Button from '../../../components/Button'
import Card from '../../../components/Card'
import FilterChip from '../../../components/FilterChip'
import StatusChip from '../../../components/StatusChip'
import { BOOKING_STATUSES } from '../../../../lib/statuses'
import Table from '../../../components/Table'
import StaffPageHeader from '../components/StaffPageHeader'
import SearchInput from '../components/SearchInput'
import SegmentedControl from '../components/SegmentedControl'
import { panelSlidesOver } from '../components/panelSlidesOver'
import BookingCard from './BookingCard'
import BookingDrawer from './BookingDrawer'
import { isActive, matchesFilter, sortByDate } from './bookingFilters'
import { formatDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import { loadedText, useApiData } from '../../../hooks/useApiData'
import { loadAllStaffBookings } from '../../../../services/staffBookings'
import { BOOKINGS_INFO, BOOKING_FILTERS, BOOKING_TYPES, BOOKINGS_PER_PAGE } from '../../../../lib/staff/bookingsData'
import './BookingsPage.css'

const bookingColumns = [
  { key: 'reference', header: 'Reference', render: (booking) => <strong>{booking.reference}</strong> },
  { key: 'guest', header: 'Guest' },
  { key: 'description', header: 'Booking' },
  { key: 'startDate', header: 'Date', render: (booking) => formatDate(booking.startDate) },
  { key: 'total', header: 'Total', render: (booking) => <strong>{formatRand(booking.total)}</strong> },
  { key: 'status', header: 'Status', render: (booking) => <StatusChip statuses={BOOKING_STATUSES} status={booking.status} /> },
]

export default function BookingsPage() {
  const loaded = useApiData(loadAllStaffBookings, [])
  const ALL_BOOKINGS = loaded.data ?? []
  const ACTIVE_BOOKINGS = ALL_BOOKINGS.filter(isActive)
  const PENDING_COUNT = ACTIVE_BOOKINGS.filter((booking) => booking.status === 'pending_payment').length
  const PAGE_SUBTITLE = loadedText(loaded, 'the bookings', `${ACTIVE_BOOKINGS.length} upcoming · ${PENDING_COUNT} awaiting payment`)

  const [statusFilter, setStatusFilter] = useState('Active')
  const [bookingType, setBookingType] = useState('all')
  const [searchText, setSearchText] = useState('')
  const [selectedReference, setSelectedReference] = useState(null) // null = the list's first booking
  const [isDrawerOpen, setIsDrawerOpen] = useState(false) // only used below 1280px, where the drawer slides over
  const [currentPage, setCurrentPage] = useState(1) // desktop table pages: 1, 2, …

  const search = searchText.trim().toLowerCase()
  const isSearching = search !== ''
  const activeFilter = BOOKING_FILTERS.find((filter) => filter.label === statusFilter)

  function matchesType(booking) {
    return bookingType === 'all' || booking.type === bookingType
  }

  // Reference, short name or full name
  function matchesSearch(booking) {
    return [booking.reference, booking.guest, booking.fullName].some((text) => (text ?? '').toLowerCase().includes(search))
  }

  // Searching ignores the chips
  const matchingBookings = ALL_BOOKINGS.filter((booking) => {
    if (isSearching) return matchesType(booking) && matchesSearch(booking)
    return matchesType(booking) && matchesFilter(booking, activeFilter)
  })
  const visibleBookings = sortByDate(matchingBookings, !isSearching && activeFilter.newestFirst)

  const pageCount = Math.max(1, Math.ceil(visibleBookings.length / BOOKINGS_PER_PAGE))
  const firstIndex = (currentPage - 1) * BOOKINGS_PER_PAGE
  const bookingsOnPage = visibleBookings.slice(firstIndex, firstIndex + BOOKINGS_PER_PAGE)

  // The clicked booking, else the first on this page
  const selectedBooking =
    visibleBookings.find((booking) => booking.reference === selectedReference) || bookingsOnPage[0]
  const pageLabel =
    visibleBookings.length === 0
      ? '0 of 0'
      : `${firstIndex + 1}–${firstIndex + bookingsOnPage.length} of ${visibleBookings.length}`

  // Count per chip (respects Stays/Events)
  function countFor(filter) {
    return ALL_BOOKINGS.filter((booking) => matchesType(booking) && matchesFilter(booking, filter)).length
  }

  function openBooking(booking) {
    setSelectedReference(booking.reference)
    // On wide screens the drawer is always shown
    if (panelSlidesOver()) setIsDrawerOpen(true)
  }

  // New page: forget the clicked booking
  function goToPage(page) {
    setCurrentPage(page)
    setSelectedReference(null)
  }

  // Reset page, search, and drawer. A leftover open flag would slide it back in.
  function changeStatusFilter(label) {
    setStatusFilter(label)
    setSearchText('')
    setIsDrawerOpen(false)
    goToPage(1)
  }

  function changeBookingType(type) {
    setBookingType(type)
    setIsDrawerOpen(false)
    goToPage(1)
  }

  function changeSearchText(text) {
    setSearchText(text)
    setIsDrawerOpen(false)
    goToPage(1)
  }

  return (
    <div className="bookings-page">
      <StaffPageHeader
        title={BOOKINGS_INFO.title}
        subtitle={PAGE_SUBTITLE}
        // No "New booking": bookings only come from guests
        actions={<Button variant="secondary">Export</Button>}
      />

      <div className="bookings-toolbar">
        <div className="bookings-filters">
          {BOOKING_FILTERS.map((filter) => (
            <FilterChip key={filter.label} active={!isSearching && statusFilter === filter.label} onClick={() => changeStatusFilter(filter.label)}>
              {filter.label} <span className="bookings-filter-count">{countFor(filter)}</span>
            </FilterChip>
          ))}
        </div>

        <div className="bookings-type">
          <SegmentedControl options={BOOKING_TYPES} value={bookingType} onChange={changeBookingType} />
        </div>

        <SearchInput className="bookings-search" value={searchText} onChange={changeSearchText} placeholder="Reference or guest" />
      </div>

      <div className="bookings-body">
        <Card className="staff-table-card bookings-table-card">
          <Table
            columns={bookingColumns}
            rows={bookingsOnPage}
            getRowKey={(booking) => booking.reference}
            onRowClick={openBooking}
            selectedKey={selectedBooking && selectedBooking.reference}
          />
          {visibleBookings.length === 0 && <p className="bookings-empty">No bookings found</p>}
          <div className="bookings-table-footer">
            <span>{pageLabel}</span>
            <div className="bookings-pager">
              {currentPage > 1 && (
                <button type="button" className="link-button" onClick={() => goToPage(currentPage - 1)}>
                  ‹ Previous
                </button>
              )}
              {currentPage < pageCount && (
                <button type="button" className="link-button" onClick={() => goToPage(currentPage + 1)}>
                  Next ›
                </button>
              )}
            </div>
          </div>
        </Card>

        {/* No drawer when nothing matches */}
        {selectedBooking && (
          <BookingDrawer
            key={selectedBooking.reference}
            booking={selectedBooking}
            isOpen={isDrawerOpen}
            onClose={() => setIsDrawerOpen(false)}
          />
        )}

        {/* iPad/phone: cards, no paging */}
        <div className="bookings-card-list">
          {visibleBookings.map((booking) => (
            <BookingCard key={booking.reference} booking={booking} onClick={() => openBooking(booking)} />
          ))}
          {visibleBookings.length === 0 && <p className="bookings-empty">No bookings found</p>}
        </div>
      </div>
    </div>
  )
}
