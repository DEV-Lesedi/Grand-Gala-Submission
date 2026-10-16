import Modal from '../../../components/Modal'
import { formatRand } from '../../../../lib/money'
import { useApiData } from '../../../hooks/useApiData'
import { loadRateCard } from '../../../../services/staffQuotations'
import './RateCardPicker.css'

export default function RateCardPicker({ onPick, onClose }) {
  const rates = useApiData(loadRateCard, [])

  function pick(rate) {
    onPick(rate)
    onClose()
  }

  return (
    <Modal
      title="Add from rate card"
      subtitle="Today's prices from Spaces & rates. Catering and décor are priced per job: add those with + Add line."
      onClose={onClose}
    >
      <ul className="rate-card-list">
        {rates.loading && !rates.data && <li className="page-loading">Loading rates…</li>}
        {rates.error && <li className="page-problem">{rates.error.message}</li>}
        {(rates.data ?? []).map((rate) => (
          <li key={rate.id}>
            <button type="button" className="rate-card-option" onClick={() => pick(rate)}>
              <span>
                <span className="rate-card-name">{rate.description}</span>
                <span className="rate-card-note">{rate.note}</span>
              </span>
              <span className="rate-card-price">{formatRand(rate.unitPrice)}</span>
            </button>
          </li>
        ))}
      </ul>
    </Modal>
  )
}
