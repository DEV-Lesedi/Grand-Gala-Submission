import { statusLabel, statusTone } from '../../lib/statuses'
import './StatusChip.css'

// statuses: a lib/statuses.js table. Guest audience swaps words; children replace words only.
export default function StatusChip({ statuses, status, audience = 'staff', children }) {
  const text = children ?? statusLabel(statuses, status, audience)
  return <span className={`status-chip tone-${statusTone(statuses, status)}`}>{text}</span>
}
