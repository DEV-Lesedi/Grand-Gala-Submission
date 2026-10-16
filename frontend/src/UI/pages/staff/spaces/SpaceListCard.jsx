import Toggle from '../components/Toggle'
import { currentRateText, typeLabel } from './spaceHelpers'
import { formatDate } from '../../../../lib/dates'
import './SpaceListCard.css'

// The switch sits beside the card button, so the buttons aren't nested.
// blackout: the one covering today, if any
export default function SpaceListCard({ space, blackout, onOpen, onToggleActive }) {
  return (
    <div className={['space-list-card', !space.isActive && 'is-off', blackout && 'is-blacked-out'].filter(Boolean).join(' ')}>
      <button type="button" className="space-list-card-open" onClick={onOpen}>
        <img className="space-list-card-photo" src={space.photo} alt="" />
        <span className="space-list-card-text">
          <span className="space-list-card-name">{space.name}</span>
          <span className="space-list-card-meta">
            {typeLabel(space.type)} · {space.capacity} guests
          </span>
          <span className="space-list-card-rate">{currentRateText(space)}</span>
          {blackout && <span className="spaces-blackout-badge">Blacked out until {formatDate(blackout.end)}</span>}
        </span>
      </button>
      <Toggle checked={space.isActive} onChange={onToggleActive} label={`${space.name} bookable`} />
    </div>
  )
}
