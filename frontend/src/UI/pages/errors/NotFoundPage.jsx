import Button from '../../components/Button'
import './NotFoundPage.css'

// Where the button goes (staff pass "/staff")
export default function NotFoundPage({ homeTo = '/', homeLabel = 'Back to home' }) {
  return (
    <div className="not-found">
      <p className="not-found-code">404</p>
      <h1>Page not found</h1>
      <p className="not-found-description">The page you're looking for doesn't exist or has moved.</p>
      <Button to={homeTo}>{homeLabel}</Button>
    </div>
  )
}
