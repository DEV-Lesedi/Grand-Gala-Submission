import { Navigate, useParams, useSearchParams } from 'react-router-dom'
import EmptyState from '../../../components/EmptyState'
import { SkeletonPage } from '../../../components/Skeleton'
import StaffPageHeader from '../components/StaffPageHeader'
import QuotationBuilder from './QuotationBuilder'
import { useApiData } from '../../../hooks/useApiData'
import { loadEnquiry, loadQuotation } from '../../../../services/staffQuotations'

// A quoted enquiry opens its latest quotation instead of a new one.
async function loadBuilder(reference, bookingReference) {
  if (reference) {
    const quotation = await loadQuotation(reference)
    if (!quotation) return { notFound: `Quotation ${reference}` }
    return { quotation, enquiry: await loadEnquiry(quotation.bookingReference) }
  }
  const enquiry = bookingReference ? await loadEnquiry(bookingReference) : null
  if (!enquiry) return { notFound: 'That enquiry' }
  if (enquiry.quote) return { redirect: `/staff/quotations/${enquiry.quote.reference}` }
  return { quotation: null, enquiry }
}

// URL: /quotations/GGG-Q-0034, or /quotations/new?booking=…
export default function QuotationBuilderPage() {
  const { reference } = useParams() // the ":reference" part of the route (undefined on /new)
  const [searchParams] = useSearchParams()
  const bookingReference = searchParams.get('booking')
  const loaded = useApiData(() => loadBuilder(reference, bookingReference), [reference ?? null, bookingReference])
  const result = loaded.data

  if (loaded.loading && !result) return <SkeletonPage />
  if (loaded.error) return <NotFound what="This quotation" description={loaded.error.message} />
  if (result.redirect) return <Navigate to={result.redirect} replace />
  if (result.notFound) return <NotFound what={result.notFound} />
  if (!result.enquiry) return <NotFound what="The enquiry for this quotation" />

  // key: new quotation = fresh builder state
  return (
    <QuotationBuilder
      key={result.quotation?.reference ?? result.enquiry.reference}
      quotation={result.quotation}
      enquiry={result.enquiry}
    />
  )
}

function NotFound({ what, description = 'It may have been removed, or the link is wrong.' }) {
  return (
    <>
      <StaffPageHeader title="Quotations" subtitle="Not found" />
      <EmptyState title={`${what} wasn't found`} description={description} actionLabel="All quotations" actionTo="/staff/quotations" />
    </>
  )
}
