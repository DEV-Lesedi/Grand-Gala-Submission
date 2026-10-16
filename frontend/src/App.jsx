import { BrowserRouter, Routes, Route } from 'react-router-dom'
import GuestLayout from './UI/layouts/GuestLayout'
import SignInPage from './UI/pages/auth/SignInPage'
import CreateAccountPage from './UI/pages/auth/CreateAccountPage'
import ForgotPasswordPage from './UI/pages/auth/ForgotPasswordPage'
import ResetPasswordPage from './UI/pages/auth/ResetPasswordPage'
import MyBookingsPage from './UI/pages/guest/account/MyBookingsPage'
import GalleryPage from './UI/pages/guest/gallery/GalleryPage'
import HomePage from './UI/pages/guest/stays/HomePage'
import BrowseSpacesPage from './UI/pages/guest/stays/BrowseSpacesPage'
import SpaceDetailPage from './UI/pages/guest/stays/SpaceDetailPage'
import ReservePage from './UI/pages/guest/stays/ReservePage'
import EventListPage from './UI/pages/guest/events/EventListPage'
import EventDetailPage from './UI/pages/guest/events/EventDetailPage'
import EventEnquiryPage from './UI/pages/guest/enquire/EventEnquiryPage'
import QuotationReviewPage from './UI/pages/guest/quotation/QuotationReviewPage'
import NotFoundPage from './UI/pages/errors/NotFoundPage'
import CheckoutPage from './UI/pages/guest/checkout/CheckoutPage'
import PaymentReturnPage from './UI/pages/guest/checkout/PaymentReturnPage'
import BookingDetailsPage from './UI/pages/guest/booking/BookingDetailsPage'
import YourTicketsPage from './UI/pages/guest/tickets/YourTicketsPage'
import TicketCheckPage from './UI/pages/guest/tickets/TicketCheckPage'
import GuestUserProvider from './UI/layouts/GuestUserProvider'
import RequireGuest from './UI/layouts/RequireGuest'
import StaffLayout from './UI/layouts/StaffLayout'
import StaffUserProvider from './UI/layouts/StaffUserProvider'
import StaffSignInPage from './UI/pages/auth/StaffSignInPage'
import MyAccountPage from './UI/pages/staff/account/MyAccountPage'
import TeamPage from './UI/pages/staff/team/TeamPage'
import TodayPage from './UI/pages/staff/today/TodayPage'
import BookingsPage from './UI/pages/staff/bookings/BookingsPage'
import EnquiriesPage from './UI/pages/staff/enquiries/EnquiriesPage'
import QuotationsPage from './UI/pages/staff/quotations/QuotationsPage'
import QuotationBuilderPage from './UI/pages/staff/quotations/QuotationBuilderPage'
import SpacesAndRatesPage from './UI/pages/staff/spaces/SpacesAndRatesPage'
import EventsAndTicketsPage from './UI/pages/staff/events/EventsAndTicketsPage'
import TopProgress from './UI/components/TopProgress'
import OfflineBanner from './UI/components/OfflineBanner'

function App() {
  return (
    <BrowserRouter>
      {/* The thin loading bar at the top, for every page */}
      <TopProgress />
      {/* Shown whenever the device loses its connection; hidden otherwise */}
      <OfflineBanner />
      {/* Who is signed in on the guest site and in the staff console (separate sign-ins) */}
      <GuestUserProvider>
      <StaffUserProvider>
        <Routes>
          <Route element={<GuestLayout />}>
            <Route path="/" element={<HomePage />} />
            <Route path="/spaces" element={<BrowseSpacesPage />} />
            <Route path="/spaces/:id" element={<SpaceDetailPage />} />
            <Route path="/create-account" element={<CreateAccountPage />} />
            <Route path="/gallery" element={<GalleryPage />} />
            <Route path="/events" element={<EventListPage />} />
            <Route path="*" element={<NotFoundPage />} />
          </Route>

          {/* No bottom tab bar on phone; the page has a back button */}
          <Route element={<GuestLayout hidePhoneTabs />}>
            <Route path="/sign-in" element={<SignInPage />} />
            {/* One event: on phone its ticket bar sits at the bottom instead of the tabs */}
            <Route path="/events/:id" element={<EventDetailPage />} />
            <Route path="/forgot-password" element={<ForgotPasswordPage />} />
            {/* The link in the reset and staff-invite emails */}
            <Route path="/reset-password" element={<ResetPasswordPage />} />
            {/* Where a ticket's QR code goes: valid or not, whose and which event (no sign-in) */}
            <Route path="/ticket/:code" element={<TicketCheckPage />} />
          </Route>

          {/* Need a guest account: signed out goes to Sign in and comes back */}
          <Route element={<GuestLayout />}>
            <Route element={<RequireGuest />}>
              <Route path="/my-bookings" element={<MyBookingsPage />} />
              <Route path="/quotations/:reference" element={<QuotationReviewPage />} />
              <Route path="/my-bookings/:reference" element={<BookingDetailsPage />} />
              {/* An enquiry is saved on the guest's account */}
              <Route path="/enquire" element={<EventEnquiryPage />} />
            </Route>
            {/* Tickets open without an account: the order carries its own access token */}
            <Route path="/tickets/:reference" element={<YourTicketsPage />} />
          </Route>

          {/* Checkout uses its own header, with no site navigation */}
          <Route path="/checkout" element={<CheckoutPage />} />
          {/* Where PayFast sends the guest back (sandbox mode) */}
          <Route path="/payment/success" element={<PaymentReturnPage />} />
          <Route path="/payment/cancel" element={<PaymentReturnPage cancelled />} />
          {/* Reserve (step 1 of a stay): the same slim header as Checkout */}
          <Route path="/reserve" element={<ReservePage />} />

          {/* Staff sign-in: its own page, outside the guest site and the staff console */}
          <Route path="/staff/sign-in" element={<StaffSignInPage />} />

          <Route path="/staff" element={<StaffLayout />}>
            <Route index element={<TodayPage />} />
            <Route path="bookings" element={<BookingsPage />} />
            <Route path="enquiries" element={<EnquiriesPage />} />
            <Route path="quotations" element={<QuotationsPage />} />
            {/* Builder: "new" starts a quotation, a reference opens one */}
            <Route path="quotations/new" element={<QuotationBuilderPage />} />
            <Route path="quotations/:reference" element={<QuotationBuilderPage />} />
            <Route path="spaces" element={<SpacesAndRatesPage />} />
            <Route path="events" element={<EventsAndTicketsPage />} />
            <Route path="account" element={<MyAccountPage />} />
            <Route path="team" element={<TeamPage />} />
            {/* Unknown /staff/… addresses stay in the staff console */}
            <Route path="*" element={<NotFoundPage homeTo="/staff" homeLabel="Back to Today" />} />
          </Route>
        </Routes>
      </StaffUserProvider>
      </GuestUserProvider>
    </BrowserRouter>
  )
}

export default App
