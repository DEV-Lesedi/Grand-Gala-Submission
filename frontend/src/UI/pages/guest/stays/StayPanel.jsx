import Button from '../../../components/Button'
import DatePicker from '../../../components/DatePicker'
import { useApiData } from '../../../hooks/useApiData'
import { useBlockedDates } from '../../../hooks/useBlockedDates'
import { checkStay } from '../../../../services/stays'
import { TODAY, addDays } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import { cardPrice } from '../../../../lib/stays'
import './StayPanel.css'

// Reserve stays off with no dates, a blocked stay, or too many guests.
// onChange writes { checkIn, checkOut, guests } into the address.
export default function StayPanel({ space, search, onChange }) {
  const blocked = useBlockedDates([space.id])
  // checkStay knows the rules (dates, guests, free nights) and asks the API for the price
  const check = useApiData(
    () => checkStay({ space, checkIn: search.checkIn, checkOut: search.checkOut, guests: search.guests }),
    [space.id, search.checkIn, search.checkOut, search.guests],
  )
  const price = check.data?.quote ?? null

  // Why Reserve can't be pressed yet (null = it can)
  const blocker = check.loading ? 'Checking your dates…' : check.error?.message || check.data?.reason || null

  // A date box changed: keep the other date sensible (check-out always after check-in)
  function changeDate(name, value) {
    const next = { ...search, [name]: value }
    if (name === 'checkIn' && value && (!next.checkOut || next.checkOut <= value)) next.checkOut = addDays(value, 1)
    onChange(next)
  }

  const reserveLink = `/reserve?${new URLSearchParams({
    space: space.id,
    checkin: search.checkIn,
    checkout: search.checkOut,
    guests: String(search.guests ?? 1),
  })}`

  return (
    <aside className="stay-panel">
      <p className="stay-panel-price">
        {cardPrice(space).text.replace(' night', '')} <span>night</span>
      </p>

      <div className="stay-panel-box">
        <label>
          Check-in
          <DatePicker
            variant="bare"
            value={search.checkIn}
            min={TODAY}
            blocked={blocked}
            onChange={(value) => changeDate('checkIn', value)}
          />
        </label>
        <label>
          Check-out
          <DatePicker
            variant="bare"
            value={search.checkOut}
            min={search.checkIn ? addDays(search.checkIn, 1) : TODAY}
            blocked={blocked}
            stayStart={search.checkIn}
            onChange={(value) => changeDate('checkOut', value)}
          />
        </label>
        <label className="is-wide">
          Guests
          <input
            type="number"
            min="1"
            max={space.capacity}
            inputMode="numeric"
            value={search.guests ?? ''}
            placeholder={`Up to ${space.capacity}`}
            onChange={(event) => onChange({ ...search, guests: Number(event.target.value) || null })}
          />
        </label>
      </div>

      {blocker ? (
        <>
          <Button disabled className="stay-panel-reserve">
            Reserve
          </Button>
          <p className="stay-panel-blocker" role="status">
            {blocker}
          </p>
        </>
      ) : (
        <Button to={reserveLink} className="stay-panel-reserve">
          Reserve
        </Button>
      )}

      {price && !check.loading && (
        <dl className="stay-panel-lines">
          {price.lines.map((line) => (
            <div key={line.label}>
              <dt>{line.label}</dt>
              <dd>{formatRand(line.amount)}</dd>
            </div>
          ))}
          <div className="is-total">
            <dt>Total</dt>
            <dd>{formatRand(price.total)}</dd>
          </div>
        </dl>
      )}
    </aside>
  )
}
