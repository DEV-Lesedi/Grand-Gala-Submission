import './ProgressBar.css'

// value out of max, for example 216 of 300 tickets.
export default function ProgressBar({ value, max, label }) {
  const percent = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0
  return (
    <span className={`progress ${percent === 100 ? 'is-full' : ''}`.trim()} role="progressbar" aria-valuenow={value} aria-valuemax={max} aria-label={label}>
      <span className="progress-fill" style={{ width: `${percent}%` }} />
    </span>
  )
}
