import { useState } from 'react'
import FilterChip from '../../../components/FilterChip'
import StaffPageHeader from '../components/StaffPageHeader'
import NeedsQuotationList from './NeedsQuotationList'
import QuotationCard from './QuotationCard'
import { matchesFilter } from './quotationHelpers'
import { sortNewestFirst } from '../enquiries/enquiryHelpers'
import { loadedText, useApiData } from '../../../hooks/useApiData'
import { loadAllQuotations } from '../../../../services/staffQuotations'
import { loadAllStaffEnquiries } from '../../../../services/staffEnquiries'
import { QUOTATIONS_INFO, QUOTATION_FILTERS } from '../../../../lib/staff/quotationsData'
import './QuotationsPage.css'

// An enquiry carries the guest and event a quotation is for.
async function loadPage() {
  const [quotations, enquiries] = await Promise.all([loadAllQuotations(), loadAllStaffEnquiries()])
  return { quotations, enquiries }
}

// No "+ New quotation": they start from an enquiry.
export default function QuotationsPage() {
  const [statusFilter, setStatusFilter] = useState('All')
  const loaded = useApiData(loadPage, [])
  const ALL_QUOTATIONS = loaded.data?.quotations ?? []
  const enquiries = loaded.data?.enquiries ?? []
  function countFor(filter) {
    return ALL_QUOTATIONS.filter((quotation) => matchesFilter(quotation, filter)).length
  }
  const SENT_COUNT = ALL_QUOTATIONS.filter((quotation) => quotation.status === 'sent').length
  const ACCEPTED_COUNT = ALL_QUOTATIONS.filter((quotation) => quotation.status === 'accepted').length
  const PAGE_SUBTITLE = loadedText(loaded, 'the quotations', `${SENT_COUNT} sent · ${ACCEPTED_COUNT} accepted`)
  // Enquiries without a quotation yet, newest first
  const NEEDING_QUOTATION = sortNewestFirst(enquiries.filter((enquiry) => enquiry.status === 'enquiry'))
  const enquiryFor = (quotation) => enquiries.find((enquiry) => enquiry.reference === quotation.bookingReference)

  const activeFilter = QUOTATION_FILTERS.find((filter) => filter.label === statusFilter)
  const visibleQuotations = ALL_QUOTATIONS.filter((quotation) => matchesFilter(quotation, activeFilter))

  return (
    <div className="quotations-page">
      <StaffPageHeader title={QUOTATIONS_INFO.title} subtitle={PAGE_SUBTITLE} />

      <div className="quotations-filters">
        {QUOTATION_FILTERS.map((filter) => (
          <FilterChip key={filter.label} active={statusFilter === filter.label} onClick={() => setStatusFilter(filter.label)}>
            {filter.label} <span className="quotations-filter-count">{countFor(filter)}</span>
          </FilterChip>
        ))}
      </div>

      <div className={`quotations-body ${statusFilter === 'All' ? 'is-all' : ''}`.trim()}>
        <section className="quotations-needs">
          <h2 className="quotations-section-title">
            Needs a quotation <span>{NEEDING_QUOTATION.length} enquiries</span>
          </h2>
          <NeedsQuotationList enquiries={NEEDING_QUOTATION} />
        </section>

        <section className="quotations-all">
          <h2 className="quotations-section-title">All quotations</h2>
          <div className="quotations-list">
            {visibleQuotations.map((quotation) => (
              <QuotationCard key={quotation.reference} quotation={quotation} enquiry={enquiryFor(quotation)} />
            ))}
            {visibleQuotations.length === 0 && <p className="quotations-empty">No quotations here</p>}
          </div>
        </section>
      </div>
    </div>
  )
}
