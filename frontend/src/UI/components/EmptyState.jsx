import Button from './Button'
import './EmptyState.css'

// titleAs="h1" when this message is the whole page, so screen readers get a heading.
export default function EmptyState({ title, description, actionLabel, actionTo, onAction, titleAs: Title = 'p' }) {
  return (
    <div className="empty-state">
      <Title className="empty-state-title">{title}</Title>
      {description && <p className="empty-state-description">{description}</p>}
      {actionLabel && (
        <Button variant="secondary" size="sm" to={actionTo} onClick={onAction}>
          {actionLabel}
        </Button>
      )}
    </div>
  )
}
