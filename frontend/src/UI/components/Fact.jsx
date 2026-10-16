import './Fact.css'

export default function Fact({ items = [], className = '' }) {
  return (
    <p className={`fact ${className}`.trim()}>
      {items.filter(Boolean).join(' · ')}
    </p>
  )
}
