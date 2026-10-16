import './FilterChip.css'

export default function FilterChip({ active = false, onClick, children }) {
  return (
    <button
      type="button"
      className={`filter-chip ${active ? 'is-active' : ''}`.trim()}
      aria-pressed={active}
      onClick={onClick}
    >
      {children}
    </button>
  )
}
