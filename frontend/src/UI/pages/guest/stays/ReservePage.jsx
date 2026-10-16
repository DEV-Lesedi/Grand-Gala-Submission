import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import Button from '../../../components/Button'
import { SkeletonPage } from '../../../components/Skeleton'
import DatePicker from '../../../components/DatePicker'
import FocusedHeader from '../components/FocusedHeader'
import StaySteps from '../components/StaySteps'
import { searchFromParams } from '../components/searchAddress'
import { couldNotLoad, useApiData } from '../../../hooks/useApiData'
import { useBlockedDates } from '../../../hooks/useBlockedDates'
import { checkStay, findSpace, loadGuestSpaces, unavailableReasons, withAmenities } from '../../../../services/stays'
import { TODAY, addDays, formatDateRange } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import { cardPrice, spaceMeta } from '../../../../lib/stays'
import './ReservePage.css'

// Villa and rooms, plus why each is unbookable on the dates (or null).
async function loadPlaces(checkIn, checkOut) {
  const places = (await loadGuestSpaces()).filter((space) => space.isAccommodation)
  // One request for all the places, not one each
  const problems = checkIn && checkOut && checkOut > checkIn ? await unavailableReasons(places, checkIn, checkOut) : {}
  return { places, problems }
}

// Chosen place with amenities, and checkStay's answer for the ticked extras.
async function loadChoice(place, checkIn, checkOut, guests, extras) {
  const space = await withAmenities(place)
  const check = await checkStay({ space, checkIn, checkOut, guests, extras })
  return { space, check }
}

// Choices live in the URL (amenity ids as extras). Checkout reprices; the URL never holds a price.
export default function ReservePage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const search = searchFromParams(searchParams)
  // Only the chosen place shows, until the guest asks to see the others ("Change")
  const [showAllPlaces, setShowAllPlaces] = useState(false)
  const placesData = useApiData(() => loadPlaces(search.checkIn, search.checkOut), [search.checkIn, search.checkOut])
  const STAY_SPACES = placesData.data?.places ?? []
  const problems = placesData.data?.problems ?? {}
  const listed = findSpace(STAY_SPACES, searchParams.get('space')) ?? STAY_SPACES[0]
  const blocked = useBlockedDates(listed ? [listed.id] : [])
  const extras = (searchParams.get('extras') ?? '').split(',').map(Number).filter(Boolean)
  const choice = useApiData(
    () => (listed ? loadChoice(listed, search.checkIn, search.checkOut, search.guests, extras) : Promise.resolve(null)),
    [listed?.id ?? null, search.checkIn, search.checkOut, search.guests, extras.join(',')],
  )
  const EXTRAS = choice.data?.space?.amenities ?? []

  if (!listed) {
    return (
      <div className="reserve">
        <FocusedHeader title="Reserve" note="Secure booking" />
        {placesData.error ? (
          <p className="page-problem">{couldNotLoad('the places to stay', placesData.error)}</p>
        ) : (
          <SkeletonPage />
        )}
      </div>
    )
  }
  const space = listed

  // Update the URL in place, so Back returns to the space, not each change.
  function update(changes) {
    const next = {
      space: space.id,
      checkin: search.checkIn,
      checkout: search.checkOut,
      guests: search.guests ?? '',
      extras: extras.join(','),
      ...changes,
    }
    const params = new URLSearchParams()
    for (const [key, value] of Object.entries(next)) if (value) params.set(key, String(value))
    setSearchParams(params, { replace: true })
  }

  function changeCheckIn(value) {
    const checkout = search.checkOut && search.checkOut > value ? search.checkOut : value ? addDays(value, 1) : ''
    update({ checkin: value, checkout })
  }

  function toggleExtra(id) {
    update({ extras: (extras.includes(id) ? extras.filter((item) => item !== id) : [...extras, id]).join(',') })
  }

  const hasDates = Boolean(search.checkIn && search.checkOut)
  // API total: nights (including seasonal rates) plus ticked extras.
  const price = choice.data?.check.quote ?? null
  const total = price?.total ?? 0

  // Why another place on the list can't be booked on these dates (null = it can)
  function problemFor(option) {
    if (!hasDates) return null
    return problems[option.id] ?? null
  }
  // Why the guest can't continue yet (null = they can): checkStay's answer for the chosen place
  const blocker =
    placesData.loading || choice.loading ? 'Checking your dates…' : choice.error?.message || choice.data?.check.reason || null

  const checkoutLink = `/checkout?${new URLSearchParams({
    stay: space.id,
    checkin: search.checkIn,
    checkout: search.checkOut,
    guests: String(search.guests ?? 1),
    ...(extras.length ? { extras: extras.join(',') } : {}),
  })}`

  return (
    <div className="reserve">
      <FocusedHeader title={`Reserve · ${space.name}`} note="Secure booking" />
      <StaySteps current={1} />

      <div className="reserve-body">
        <main className="reserve-main">
          <h1>How would you like to stay?</h1>

          {/* One choice at a time: radio buttons, styled as cards */}
          <fieldset className="reserve-options">
            <legend className="reserve-hidden">Where to stay</legend>
            {/* Chosen place first; the rest stay hidden until Change. */}
            {[space, ...(showAllPlaces ? STAY_SPACES.filter((option) => option.id !== space.id) : [])].map((option) => {
              const problem = problemFor(option)
              const tooSmall = search.guests > option.capacity
              return (
                <label
                  key={option.id}
                  className={`reserve-option ${option.id === space.id ? 'is-selected' : ''} ${problem || tooSmall ? 'is-off' : ''}`.trim()}
                >
                  <input
                    type="radio"
                    name="space"
                    checked={option.id === space.id}
                    onChange={() => {
                      update({ space: option.id })
                      setShowAllPlaces(false)
                    }}
                  />
                  <img src={option.photo} alt="" />
                  <span className="reserve-option-text">
                    <strong>{option.name}</strong>
                    <span>{problem ?? (tooSmall ? `Sleeps ${option.capacity}` : spaceMeta(option, STAY_SPACES))}</span>
                  </span>
                  <span className="reserve-option-price">
                    <strong>{cardPrice(option).text.replace(' night', '')}</strong>
                    <span>per night</span>
                  </span>
                </label>
              )
            })}
          </fieldset>
          {STAY_SPACES.length > 1 && (
            <button type="button" className="link-button reserve-change" onClick={() => setShowAllPlaces(!showAllPlaces)}>
              {showAllPlaces ? 'Hide other places' : 'Change room or villa'}
            </button>
          )}

          <div className="reserve-dates">
            <label>
              Check-in
              <DatePicker
                value={search.checkIn}
                min={TODAY}
                blocked={blocked}
                onChange={changeCheckIn}
              />
            </label>
            <label>
              Check-out
              <DatePicker
                value={search.checkOut}
                min={search.checkIn ? addDays(search.checkIn, 1) : TODAY}
                blocked={blocked}
                stayStart={search.checkIn}
                onChange={(value) => update({ checkout: value })}
              />
            </label>
            <label>
              Guests
              <input
                type="number"
                min="1"
                inputMode="numeric"
                value={search.guests ?? ''}
                onChange={(event) => update({ guests: Number(event.target.value) || '' })}
              />
            </label>
          </div>

          <h2>Add to your stay</h2>
          <div className="reserve-extras">
            {EXTRAS.map((extra) => (
              <label key={extra.id} className="reserve-extra">
                <input type="checkbox" checked={extras.includes(extra.id)} onChange={() => toggleExtra(extra.id)} />
                <span>{extra.name}</span>
                <span className="reserve-extra-price">{formatRand(extra.price)}</span>
              </label>
            ))}
          </div>
        </main>

        <aside className="reserve-summary" aria-label="Your stay">
          <div className="reserve-summary-top">
            <img src={space.photo} alt="" />
            <span>
              <strong>{space.name}</strong>
              <span>
                {hasDates ? `${formatDateRange(search.checkIn, search.checkOut)} · ` : ''}
                {price?.nights ? `${price.nights} ${price.nights === 1 ? 'night' : 'nights'}` : 'Choose dates'}
              </span>
            </span>
          </div>

          <dl className="reserve-summary-lines">
            {/* The API's lines: the nights, then each extra that's ticked */}
            {price?.lines?.map((line) => (
              <div key={line.label}>
                <dt>{line.label}</dt>
                <dd>{formatRand(line.amount)}</dd>
              </div>
            ))}
            {EXTRAS.filter((extra) => !extras.includes(extra.id)).map((extra) => (
              <div key={extra.id}>
                <dt>{extra.name}</dt>
                <dd>Not added</dd>
              </div>
            ))}
            <div className="is-total">
              <dt>Total</dt>
              <dd>{blocker ? '—' : formatRand(total)}</dd>
            </div>
          </dl>

          {blocker ? (
            <>
              <Button disabled className="reserve-continue">
                Continue to details
              </Button>
              <p className="reserve-blocker" role="status">
                {blocker}
              </p>
            </>
          ) : (
            <Button to={checkoutLink} className="reserve-continue">
              Continue to details
            </Button>
          )}
        </aside>
      </div>
    </div>
  )
}
