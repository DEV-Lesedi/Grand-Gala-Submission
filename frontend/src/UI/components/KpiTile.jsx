import Card from './Card'
import './KpiTile.css'

export default function KpiTile({ label, value, hint, className = '' }) {
  return (
    <Card className={`kpi-tile ${className}`.trim()}>
      <p className="kpi-label">{label}</p>
      <p className="kpi-value">{value}</p>
      {hint && <p className="kpi-hint">{hint}</p>}
    </Card>
  )
}
