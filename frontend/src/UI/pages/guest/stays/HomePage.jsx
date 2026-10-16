import { Link, useNavigate } from 'react-router-dom'
import Button from '../../../components/Button'
import SpaceCard from '../../../components/SpaceCard'
import { SkeletonCards } from '../../../components/Skeleton'
import StaySearch from '../components/StaySearch'
import { searchToParams } from '../components/searchAddress'
import EventListCard from '../events/EventListCard'
import { couldNotLoad, useApiData } from '../../../hooks/useApiData'
import { loadGuestSpaces } from '../../../../services/stays'
import { loadGuestEvents } from '../../../../services/events'
import { cardPrice, featuredSpaces, spaceMeta, spaceTag } from '../../../../lib/stays'
import { HOME_HERO, HOME_SUMMARY, INTENTS } from '../../../../lib/guest/staysData'
import './HomePage.css'

// Spaces and events come from the API; surrounding copy stays in staysData.js.
export default function HomePage() {
  const navigate = useNavigate()
  const spaces = useApiData(loadGuestSpaces, [])
  const events = useApiData(loadGuestEvents, [])

  const guestSpaces = spaces.data ?? []
  const villa = guestSpaces.find((space) => space.isAccommodation && !space.parentId)
  const featured = featuredSpaces(guestSpaces)
  const upcoming = (events.data ?? []).slice(0, 3)

  // The quick links beside the search: the whole villa, its rooms, then each venue to hire
  const quickLinks = [
    ...(villa ? [{ label: 'Whole villa', to: `/spaces/${villa.id}` }] : []),
    { label: 'Villa rooms', to: '/spaces?filter=villa-rooms' },
    ...guestSpaces
      .filter((space) => !space.isAccommodation)
      .map((space) => ({ label: space.name, to: `/spaces/${space.id}` })),
  ]

  return (
    <div className="home">
      <div className="home-bar">
        <div className="home-bar-inner">
          <StaySearch
            search={{ kind: 'all', checkIn: '', checkOut: '', guests: null }}
            staySpaceIds={villa ? [villa.id] : []}
            onSearch={(search) => navigate(`/spaces?${searchToParams(search)}`)}
          />
          <nav className="home-quick-links" aria-label="Spaces">
            {quickLinks.map((link) => (
              <Link key={link.label} to={link.to}>
                {link.label}
              </Link>
            ))}
          </nav>
          <Button variant="secondary" to="/spaces" className="home-filters">
            Filters
          </Button>
        </div>
      </div>

      <div className="home-content">
        <section className="home-hero">
          <div className="home-hero-photo">
            <img src={HOME_HERO.photo} alt="" />
            <div className="home-hero-text">
              <p>{HOME_HERO.eyebrow}</p>
              <h1>{HOME_HERO.title}</h1>
            </div>
          </div>

          <div className="home-plan">
            <h2>What are you planning?</h2>
            <ul>
              {INTENTS.map((intent) => (
                <li key={intent.title}>
                  <Link to={intent.to}>
                    <strong>{intent.title}</strong>
                    <span>{intent.detail}</span>
                    <span className="home-plan-arrow" aria-hidden="true">
                      →
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
            <div className="home-plan-summary">
              <strong>{HOME_SUMMARY.title}</strong>
              <p>{HOME_SUMMARY.detail}</p>
            </div>
          </div>
        </section>

        <section className="home-section">
          <div className="home-section-header">
            <h2>Spaces guests love</h2>
            <Link to="/spaces">{spaces.data ? `Show all ${guestSpaces.length} →` : 'Show all →'}</Link>
          </div>
          {spaces.error && <p className="page-problem">{couldNotLoad('the spaces', spaces.error)}</p>}
          {spaces.loading && !spaces.data && <SkeletonCards className="home-spaces" count={4} />}
          <div className="home-spaces fade-in">
            {featured.map((space) => (
              <SpaceCard
                key={space.id}
                to={`/spaces/${space.id}`}
                image={space.photo}
                badge={spaceTag(space)}
                title={space.name}
                subtitle={spaceMeta(space, guestSpaces)}
                price={cardPrice(space).text}
              />
            ))}
          </div>
        </section>

        {upcoming.length > 0 && (
          <section className="home-section">
            <div className="home-section-header">
              <h2>What’s on</h2>
              <Link to="/events">See all →</Link>
            </div>
            <div className="home-events">
              {upcoming.map((event) => (
                <EventListCard key={event.id} event={event} />
              ))}
            </div>
          </section>
        )}
      </div>
    </div>
  )
}
