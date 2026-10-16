import Card from '../../../components/Card'
import Toggle from '../components/Toggle'
import { formatRand } from '../../../../lib/money'
import './AmenitiesCard.css'

export default function AmenitiesCard({ amenities, onToggle, onAdd }) {
  return (
    <Card as="section" className="amenities-card">
      <div className="amenities-header">
        <h2>Amenities</h2>
        <button type="button" className="link-button amenities-add" onClick={onAdd}>
          + Add amenity
        </button>
      </div>
      <ul className="amenities-list">
        {amenities.map((amenity) => (
          <li key={amenity.id} className={`amenity ${amenity.isActive ? '' : 'is-off'}`.trim()}>
            <span>
              <span className="amenity-name">{amenity.name}</span>
              <span className="amenity-price">{formatRand(amenity.price)}</span>
            </span>
            <Toggle checked={amenity.isActive} onChange={(isActive) => onToggle(amenity.id, isActive)} label={`${amenity.name} offered`} />
          </li>
        ))}
      </ul>
    </Card>
  )
}
