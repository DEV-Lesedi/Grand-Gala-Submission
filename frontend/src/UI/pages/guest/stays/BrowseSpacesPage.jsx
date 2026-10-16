import { useSearchParams } from 'react-router-dom'
import EmptyState from '../../../components/EmptyState'
import FilterChip from '../../../components/FilterChip'
import SpaceCard from '../../../components/SpaceCard'
import { SkeletonCards } from '../../../components/Skeleton'
import StaySearch from '../components/StaySearch'
import { searchFromParams, searchToParams } from '../components/searchAddress'
import { couldNotLoad, useApiData } from '../../../hooks/useApiData'
import { loadGuestSpaces, unavailableReasons } from '../../../../services/stays'
import { formatDateRange } from '../../../../lib/dates'
import { cardPrice, spaceMeta, spaceTag } from '../../../../lib/stays'
import { BROWSE_FILTERS } from '../../../../lib/guest/staysData'
import './BrowseSpacesPage.css'
import Select from '../../../components/Select'

// Spaces, plus ids taken on the dates (one availability request for all of them).
async function loadSpacesFor(checkIn, checkOut) {
  const spaces = await loadGuestSpaces()
  if (!checkIn || !checkOut) return { spaces, taken: [] }
  // One request for every stay space, not one each
  const reasons = await unavailableReasons(spaces.filter((space) => space.isAccommodation), checkIn, checkOut)
  const taken = spaces.filter((space) => reasons[space.id]).map((space) => space.id)
  return { spaces, taken }
}

// What each quick filter keeps
const FILTER_RULES = {
  'whole-villa': (space) => space.type === 'villa',
  'villa-rooms': (space) => space.type === 'room',
  'event-hire': (space) => !space.isAccommodation,
  'sleeps-4': (space) => space.isAccommodation && space.capacity >= 4,
  'under-1000': (space) => space.isAccommodation && cardPrice(space).amount < 1000,
}

const SORTS = [
  { value: '', label: 'Recommended' },
  { value: 'price-low', label: 'Price: low to high' },
  { value: 'price-high', label: 'Price: high to low' },
]

// Search, filters and sort live in the URL. Booked or blacked-out nights drop out.
export default function BrowseSpacesPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const search = searchFromParams(searchParams)
  const loaded = useApiData(() => loadSpacesFor(search.checkIn, search.checkOut), [search.checkIn, search.checkOut])
  const allSpaces = loaded.data?.spaces ?? []
  const taken = loaded.data?.taken ?? []
  const villa = allSpaces.find((space) => space.isAccommodation && !space.parentId)
  const filters = (searchParams.get('filter') ?? '').split(',').filter((id) => FILTER_RULES[id])
  const sort = SORTS.some((item) => item.value === searchParams.get('sort')) ? searchParams.get('sort') : ''

  // Rebuild the address from the search, filters and sort (leaving out what isn't set)
  function update(changes) {
    const next = { search, filters, sort, ...changes }
    const params = new URLSearchParams(searchToParams(next.search))
    if (next.filters.length) params.set('filter', next.filters.join(','))
    if (next.sort) params.set('sort', next.sort)
    setSearchParams(params)
  }

  function toggleFilter(id) {
    update({ filters: filters.includes(id) ? filters.filter((item) => item !== id) : [...filters, id] })
  }

  const visible = allSpaces.filter((space) => {
    if (search.kind === 'stays' && !space.isAccommodation) return false
    if (search.kind === 'venues' && space.isAccommodation) return false
    if (search.guests && space.capacity < search.guests) return false
    if (filters.length && !filters.some((id) => FILTER_RULES[id](space))) return false
    if (search.checkIn && taken.includes(space.id)) return false
    return true
  })
  if (sort) {
    const price = (space) => cardPrice(space).amount ?? 0
    visible.sort((a, b) => (sort === 'price-low' ? price(a) - price(b) : price(b) - price(a)))
  }

  // Cards carry the search on to the space, so its dates and guests are already filled in
  const cardSearch = searchToParams(search)
  const dates = search.checkIn ? ` · ${formatDateRange(search.checkIn, search.checkOut)}` : ''

  return (
    <div className="browse">
      <div className="browse-bar">
        <div className="browse-bar-inner">
          <StaySearch
            key={searchParams.toString()}
            search={search}
            staySpaceIds={villa ? [villa.id] : []}
            onSearch={(next) => update({ search: next })}
          />
          <div className="browse-filters">
            {BROWSE_FILTERS.map((filter) => (
              <FilterChip key={filter.id} active={filters.includes(filter.id)} onClick={() => toggleFilter(filter.id)}>
                {filter.label}
              </FilterChip>
            ))}
          </div>
          <p className="browse-count" aria-live="polite">
            {loaded.loading
              ? 'Finding spaces…'
              : `${visible.length} ${visible.length === 1 ? 'space' : 'spaces'} at Grand Gala Gardens${dates}`}
          </p>
        </div>
      </div>

      <div className="browse-content">
        <h1 className="browse-title">Spaces</h1>
        <label className="browse-sort">
          Sort
          <Select value={sort} onChange={(event) => update({ sort: event.target.value })}>
            {SORTS.map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
              </option>
            ))}
          </Select>
        </label>

        {loaded.error ? (
          <EmptyState
            title={couldNotLoad('the spaces')}
            description={loaded.error.message}
            actionLabel="Try again"
            onAction={loaded.reload}
          />
        ) : loaded.loading && !loaded.data ? (
          <SkeletonCards className="browse-grid" />
        ) : visible.length > 0 ? (
          <div className={`browse-grid fade-in ${loaded.loading ? 'is-refreshing' : ''}`.trim()}>
            {visible.map((space) => (
              <SpaceCard
                key={space.id}
                to={`/spaces/${space.id}${cardSearch ? `?${cardSearch}` : ''}`}
                image={space.photo}
                badge={spaceTag(space)}
                title={space.name}
                subtitle={spaceMeta(space, allSpaces)}
                price={cardPrice(space).text}
              />
            ))}
          </div>
        ) : (
          <EmptyState
            title={search.checkIn ? 'Nothing free for that search' : 'No spaces match'}
            description="Try other dates, fewer guests or fewer filters."
            actionLabel="Clear search"
            actionTo="/spaces"
          />
        )}
      </div>
    </div>
  )
}
