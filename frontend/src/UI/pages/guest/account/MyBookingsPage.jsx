import { useNavigate, useSearchParams } from 'react-router-dom'
import { useGuestUser } from '../../../hooks/guestUser'
import { couldNotLoad, useApiData } from '../../../hooks/useApiData'
import { loadMyBookings } from '../../../../services/myBookings'
import Button from '../../../components/Button'
import { SkeletonRows } from '../../../components/Skeleton'
import UpcomingTab from './tabs/UpcomingTab'
import QuotationsTab from './tabs/QuotationsTab'
import TicketsTab from './tabs/TicketsTab'
import ProfileTab from './tabs/ProfileTab'
import './MyBookingsPage.css'

// shortLabel: phone pill text · phonePill: false = no pill on phone
const TABS = [
  { key: 'upcoming', label: 'Upcoming', shortLabel: 'Upcoming', phonePill: true, Component: UpcomingTab },
  { key: 'quotations', label: 'Quotations', shortLabel: 'Quotes', phonePill: true, Component: QuotationsTab },
  { key: 'tickets', label: 'Tickets', shortLabel: 'Tickets', phonePill: true, Component: TicketsTab },
  { key: 'profile', label: 'Profile', shortLabel: 'Profile', phonePill: false, Component: ProfileTab },
]

// Only reached signed in (RequireGuest in App.jsx)
export default function MyBookingsPage() {
  const { user, saveProfile, changePassword, signOut } = useGuestUser()
  const navigate = useNavigate()
  const loaded = useApiData(loadMyBookings, [user.id])
  // Upcoming and Quotations use the ahead list. Tickets also include past.
  const lists = {
    upcoming: loaded.data?.upcoming ?? [],
    quotations: loaded.data?.upcoming ?? [],
    tickets: [...(loaded.data?.upcoming ?? []), ...(loaded.data?.past ?? [])],
  }
  // The open tab is in the URL (?tab=profile)
  const [searchParams, setSearchParams] = useSearchParams()
  const tabInUrl = searchParams.get('tab')
  const activeTab = TABS.some((t) => t.key === tabInUrl) ? tabInUrl : 'upcoming'

  function openTab(key) {
    setSearchParams(key === 'upcoming' ? {} : { tab: key }) // default tab omits ?tab=
  }

  const initials = user.name.split(' ').map((n) => n[0]).join('').slice(0, 2)
  const ActiveComponent = TABS.find((t) => t.key === activeTab).Component

  return (
    <div className="my-bookings">
      <div className="my-bookings-header">
        <div className="my-bookings-identity">
          <span className="my-bookings-avatar">{initials}</span>
          <div>
            {/* Phones reach Profile from the Account tab at the bottom, so there it's "My account" */}
            <h1>
              {activeTab === 'profile' ? (
                <>
                  <span className="my-bookings-title-wide">My bookings</span>
                  <span className="my-bookings-title-phone">My account</span>
                </>
              ) : (
                'My bookings'
              )}
            </h1>
            <p>{user.name}</p>
          </div>
        </div>
        <Button to="/">New booking</Button>
      </div>

      {/* Tab roles for screen readers */}
      {/* On phones the Account tab shows only the profile: the booking tabs belong to Bookings */}
      <div className={`my-bookings-tabs ${activeTab === 'profile' ? 'is-account' : ''}`.trim()} role="tablist" aria-label="My bookings">
        {TABS.map((tab) => (
          <button
            key={tab.key}
            type="button"
            role="tab"
            id={`my-bookings-tab-${tab.key}`}
            aria-selected={activeTab === tab.key}
            aria-controls="my-bookings-panel"
            className={`my-bookings-tab ${activeTab === tab.key ? 'is-active' : ''} ${tab.phonePill ? '' : 'is-desktop-only'}`.trim()}
            onClick={() => openTab(tab.key)}
          >
            <span className="my-bookings-tab-label">{tab.label}</span>
            <span className="my-bookings-tab-short">{tab.shortLabel}</span>
          </button>
        ))}
      </div>

      <div role="tabpanel" id="my-bookings-panel" aria-labelledby={`my-bookings-tab-${activeTab}`}>
        {loaded.error && activeTab !== 'profile' && (
          <p className="page-problem">{couldNotLoad('your bookings', loaded.error)}</p>
        )}
        {loaded.loading && !loaded.data && activeTab !== 'profile' ? (
          <SkeletonRows count={3} />
        ) : (
        <ActiveComponent
          bookings={lists[activeTab]}
          user={user}
          onSave={saveProfile}
          onChangePassword={changePassword}
          onSignOut={async () => {
            await signOut()
            navigate('/')
          }}
        />
        )}
      </div>
    </div>
  )
}
