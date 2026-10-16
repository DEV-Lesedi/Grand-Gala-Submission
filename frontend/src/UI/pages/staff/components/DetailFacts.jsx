import './DetailFacts.css'

// Label above value, in a grid. facts: [{ label, value }]
// columns: 3 = short values · 2 = long values that wrap
export default function DetailFacts({ facts, columns = 3 }) {
  return (
    <dl className={`detail-facts ${columns === 2 ? 'is-two-columns' : ''}`.trim()}>
      {facts.map((fact) => (
        <div key={fact.label}>
          <dt>{fact.label}</dt>
          <dd>{fact.value}</dd>
        </div>
      ))}
    </dl>
  )
}
