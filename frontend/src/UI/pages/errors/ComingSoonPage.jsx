import Button from '../../components/Button'
import './NotFoundPage.css'

// A page that's linked to but not built yet. Replace its route with the real page when it exists.
export default function ComingSoonPage({ title, backTo = '/', backLabel = 'Back to home' }) {
  return (
    <div className="not-found">
      <p className="not-found-code">Coming soon</p>
      <h1>{title}</h1>
      <p className="not-found-description">This page is still being built.</p>
      <Button to={backTo}>{backLabel}</Button>
    </div>
  )
}
