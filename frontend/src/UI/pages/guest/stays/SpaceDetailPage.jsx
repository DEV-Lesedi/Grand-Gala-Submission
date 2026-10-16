import { Link, useParams, useSearchParams } from 'react-router-dom'
import Button from '../../../components/Button'
import EmptyState from '../../../components/EmptyState'
import Loaded from '../../../components/Loaded'
import { searchFromParams, searchToParams } from '../components/searchAddress'
import StayPanel from './StayPanel'
import { useApiData } from '../../../hooks/useApiData'
import { findSpace, loadGuestSpaces } from '../../../../services/stays'
import { cardPrice, roomsOf, spaceMeta } from '../../../../lib/stays'
import { SPACE_DESCRIPTIONS, VILLA_FEATURES } from '../../../../lib/guest/staysData'
import { apiGet } from '../../../../services/api'
import './SpaceDetailPage.css'

// Up to 5 photos: own first, then gallery (villa's for a room, grounds for a venue).
function photosFor(space, parent, gallery) {
  const place = parent ? parent.name : space.name
  const extra = gallery.filter(
    (photo) => photo.space === place || (!space.isAccommodation && photo.space === 'Grounds'),
  )
  const sources = [space.photo, ...extra.map((photo) => photo.src)]
  return [...new Set(sources)].slice(0, 5)
}

// /spaces/<database id>, with optional check-in, check-out and guests in the query.
export default function SpaceDetailPage() {
  const { id } = useParams()
  const loaded = useApiData(loadGuestSpaces, [])
  const gallery = useApiData(() => apiGet('/gallery'), [])
  const allSpaces = loaded.data ?? []

  return (
    <div className="space-detail">
      <Loaded
        state={loaded}
        what="this space"
        find={(spaces) => findSpace(spaces, id)}
        notFound={
          <EmptyState
            titleAs="h1"
            title="Space not found"
            description="It may have been removed, or the link is wrong."
            actionLabel="All spaces"
            actionTo="/spaces"
          />
        }
      >
        {(space) => <SpaceDetail space={space} allSpaces={allSpaces} gallery={gallery.data ?? []} />}
      </Loaded>
    </div>
  )
}

// allSpaces: every space, used for this space's rooms and parent villa.
function SpaceDetail({ space, allSpaces, gallery }) {
  const [searchParams, setSearchParams] = useSearchParams()
  const search = searchFromParams(searchParams)
  const backSearch = searchToParams({ ...search, kind: 'all' })
  const rooms = roomsOf(space, allSpaces)
  const parent = allSpaces.find((item) => item.id === space.parentId)
  const photos = photosFor(space, parent, gallery)
  const title = space.type === 'villa' ? `${space.name} at Grand Gala Gardens` : space.name
  const description =
    space.type === 'villa'
      ? SPACE_DESCRIPTIONS.villa
      : space.parentId
        ? SPACE_DESCRIPTIONS.room
        : SPACE_DESCRIPTIONS.venue
  return (
    <>
      <div className="space-detail-back">
        <Link to={`/spaces${backSearch ? `?${backSearch}` : ''}`}>← All spaces</Link>
      </div>

      <div className="space-detail-content">
        <div className="space-detail-title">
          <h1>{title}</h1>
          <span>Centurion</span>
        </div>

        <div className={`space-detail-mosaic has-${photos.length}`}>
          {photos.map((src, index) => (
            <img key={src} src={src} alt={index === 0 ? space.name : ''} />
          ))}
        </div>

        <div className="space-detail-body">
          <div className="space-detail-main">
            <h2>{spaceMeta(space, allSpaces)}</h2>
            <p className="space-detail-description">{description}</p>

            {parent && (
              <p className="space-detail-parent">
                Part of <Link to={`/spaces/${parent.id}${backSearch ? `?${backSearch}` : ''}`}>{parent.name}</Link>.
                Booking a room blocks the whole villa for those nights.
              </p>
            )}

            {space.isAccommodation && (
              <ul className="space-detail-features">
                {VILLA_FEATURES.map((feature) => (
                  <li key={feature}>{feature}</li>
                ))}
                {rooms.length > 0 && <li>{rooms.length} rooms</li>}
              </ul>
            )}
          </div>

          {space.isAccommodation ? (
            <StayPanel
              space={space}
              search={search}
              onChange={(next) =>
                setSearchParams(new URLSearchParams(searchToParams({ ...next, kind: 'all' })), { replace: true })
              }
            />
          ) : (
            <aside className="space-detail-quote">
              <p className="space-detail-price">
                {cardPrice(space).text} <span>per event</span>
              </p>
              <p>Up to {space.capacity} guests</p>
              <Button to={`/enquire?space=${space.id}`}>Request a quote</Button>
              <p className="space-detail-note">Venues are quoted for your event: send a brief, no payment.</p>
            </aside>
          )}
        </div>
      </div>
    </>
  )
}
