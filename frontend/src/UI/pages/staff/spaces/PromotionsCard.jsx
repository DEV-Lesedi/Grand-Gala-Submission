import Card from '../../../components/Card'
import Toggle from '../components/Toggle'
import { dateRangeText } from './spaceHelpers'
import { appliesToText, promotionRulesText, promotionStatus, promotionValueText } from './promotionHelpers'
import './PromotionsCard.css'

export default function PromotionsCard({ promotions, onToggle, onAdd, onEdit }) {
  return (
    <Card as="section" className="promotions-card">
      <div className="promotions-header">
        <h2>Promotions</h2>
        <button type="button" className="link-button promotions-add" onClick={onAdd}>
          + Add promotion
        </button>
      </div>

      <ul className="promotions-list">
        {promotions.map((promo) => {
          const status = promotionStatus(promo)
          return (
            <li key={promo.id} className={`promotion ${status.tone === 'muted' ? 'is-faded' : ''}`.trim()}>
              <div className="promotion-text">
                <p className="promotion-title">
                  <span className="promotion-code">{promo.code}</span>
                  {promo.description}
                </p>
                <p className="promotion-rules">
                  <strong>{promotionValueText(promo)}</strong> · {appliesToText(promo)} · {promotionRulesText(promo)} ·{' '}
                  {dateRangeText(promo.validFrom, promo.validTo)}
                </p>
              </div>
              <span className={`promotion-status is-${status.tone}`}>{status.text}</span>
              <button
                type="button"
                className="link-button promotion-edit"
                onClick={() => onEdit(promo)}
                aria-label={`Edit ${promo.code}`}
              >
                Edit
              </button>
              <Toggle checked={promo.isActive} onChange={(isActive) => onToggle(promo.id, isActive)} label={`${promo.code} on`} />
            </li>
          )
        })}
        {promotions.length === 0 && <li className="promotions-empty">No promotions yet.</li>}
      </ul>
    </Card>
  )
}
