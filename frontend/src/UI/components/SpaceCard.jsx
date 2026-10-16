import { Link } from 'react-router-dom'
import Card from './Card'
import './SpaceCard.css'

// to makes the whole card a link. Heart only if favourites can be saved. Rating only when set.
export default function SpaceCard({
  image,
  badge,
  title,
  rating,
  subtitle,
  price,
  to,
  onToggleFavorite,
  favorite = false,
}) {
  const content = (
    <>
      <div className="space-card-media">
        <img src={image} alt="" />
        {badge && <span className="space-card-badge">{badge}</span>}
        {onToggleFavorite && (
          <button
            type="button"
            className="space-card-fav"
            aria-pressed={favorite}
            aria-label={favorite ? 'Remove from favorites' : 'Add to favorites'}
            onClick={(event) => {
              event.preventDefault() // inside a link: save, don't open the space
              onToggleFavorite()
            }}
          >
            {favorite ? '♥' : '♡'}
          </button>
        )}
      </div>
      <div className="space-card-body">
        <div className="space-card-title-row">
          <h3>{title}</h3>
          {rating && <span className="space-card-rating">★ {rating}</span>}
        </div>
        {subtitle && <p className="space-card-subtitle">{subtitle}</p>}
        {price && <p className="space-card-price">{price}</p>}
      </div>
    </>
  )

  return to ? (
    <Card as={Link} to={to} className="space-card is-link">
      {content}
    </Card>
  ) : (
    <Card className="space-card">{content}</Card>
  )
}
