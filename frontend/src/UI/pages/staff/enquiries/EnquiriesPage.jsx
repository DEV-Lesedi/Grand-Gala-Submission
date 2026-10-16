import { useState } from 'react'
import Card from '../../../components/Card'
import FilterChip from '../../../components/FilterChip'
import StatusChip from '../../../components/StatusChip'
import { BOOKING_STATUSES } from '../../../../lib/statuses'
import Table from '../../../components/Table'
import StaffPageHeader from '../components/StaffPageHeader'
import { panelSlidesOver } from '../components/panelSlidesOver'
import EnquiryCard from './EnquiryCard'
import EnquiryPanel from './EnquiryPanel'
import { enquiryStageText, eventText, matchesFilter, receivedText, sortNewestFirst } from './enquiryHelpers'
import { formatDate } from '../../../../lib/dates'
import { loadedText, useApiData } from '../../../hooks/useApiData'
import { loadAllStaffEnquiries } from '../../../../services/staffEnquiries'
import { ENQUIRIES_INFO, ENQUIRY_FILTERS } from '../../../../lib/staff/enquiriesData'
import './EnquiriesPage.css'

const enquiryColumns = [
  { key: 'guest', header: 'Guest', render: (enquiry) => <strong>{enquiry.guest}</strong> },
  { key: 'event', header: 'Event', render: eventText },
  { key: 'preferredDate', header: 'Date', render: (enquiry) => formatDate(enquiry.preferredDate) },
  { key: 'estimatedGuests', header: 'Guests' },
  {
    key: 'status',
    header: 'Status',
    render: (enquiry) => {
      return (
        <StatusChip statuses={BOOKING_STATUSES} status={enquiry.status}>
          {enquiryStageText(enquiry)}
        </StatusChip>
      )
    },
  },
  { key: 'received', header: 'Received', render: (enquiry) => receivedText(enquiry.received) },
]

export default function EnquiriesPage() {
  const loaded = useApiData(loadAllStaffEnquiries, [])
  const ALL_ENQUIRIES = loaded.data ?? []
  function countFor(filter) {
    return ALL_ENQUIRIES.filter((enquiry) => matchesFilter(enquiry, filter)).length
  }
  const NEW_COUNT = countFor(ENQUIRY_FILTERS[0]) // "Enquiry": waiting for a quotation
  const QUOTED_COUNT = countFor(ENQUIRY_FILTERS[1])
  const PAGE_SUBTITLE = loadedText(loaded, 'the enquiries', `${NEW_COUNT} enquiries · ${QUOTED_COUNT} quoted`)

  const [statusFilter, setStatusFilter] = useState('Enquiry')
  const [selectedReference, setSelectedReference] = useState(null) // null = the list's first enquiry
  const [isPanelOpen, setIsPanelOpen] = useState(false) // only used below 1280px, where the panel slides over

  const activeFilter = ENQUIRY_FILTERS.find((filter) => filter.label === statusFilter)
  const visibleEnquiries = sortNewestFirst(ALL_ENQUIRIES.filter((enquiry) => matchesFilter(enquiry, activeFilter)))
  // The clicked enquiry, else the first
  const selectedEnquiry =
    visibleEnquiries.find((enquiry) => enquiry.reference === selectedReference) || visibleEnquiries[0]

  function openEnquiry(enquiry) {
    setSelectedReference(enquiry.reference)
    // On wide screens the panel is always shown
    if (panelSlidesOver()) setIsPanelOpen(true)
  }

  // New chip: first enquiry, panel closed
  function changeStatusFilter(label) {
    setStatusFilter(label)
    setSelectedReference(null)
    setIsPanelOpen(false)
  }

  return (
    <div className="enquiries-page">
      <StaffPageHeader
        title={ENQUIRIES_INFO.title}
        subtitle={PAGE_SUBTITLE}
        mobileSubtitle={`${NEW_COUNT} open`}
        // No header button: quotations start from an enquiry's panel
      />

      <div className="enquiries-filters">
        {ENQUIRY_FILTERS.map((filter) => (
          <FilterChip key={filter.label} active={statusFilter === filter.label} onClick={() => changeStatusFilter(filter.label)}>
            {filter.label} <span className="enquiries-filter-count">{countFor(filter)}</span>
          </FilterChip>
        ))}
      </div>

      <div className="enquiries-body">
        <Card className="staff-table-card enquiries-table-card">
          <Table
            columns={enquiryColumns}
            rows={visibleEnquiries}
            getRowKey={(enquiry) => enquiry.reference}
            onRowClick={openEnquiry}
            selectedKey={selectedEnquiry && selectedEnquiry.reference}
          />
          {visibleEnquiries.length === 0 && <p className="enquiries-empty">No enquiries here</p>}
        </Card>

        {selectedEnquiry && (
          <EnquiryPanel enquiry={selectedEnquiry} isOpen={isPanelOpen} onClose={() => setIsPanelOpen(false)} />
        )}

        {/* iPad and phone: cards instead of the table */}
        <div className="enquiries-card-list">
          {visibleEnquiries.map((enquiry) => (
            <EnquiryCard key={enquiry.reference} enquiry={enquiry} onClick={() => openEnquiry(enquiry)} />
          ))}
          {visibleEnquiries.length === 0 && <p className="enquiries-empty">No enquiries here</p>}
        </div>
      </div>
    </div>
  )
}
