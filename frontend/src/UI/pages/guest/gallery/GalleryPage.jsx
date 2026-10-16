import { useState } from 'react'
import FilterChip from '../../../components/FilterChip'
import Lightbox from '../../../components/Lightbox'
import { useApiData } from '../../../hooks/useApiData'
import { apiGet } from '../../../../services/api'
import { GALLERY_CATEGORIES } from '../../../../lib/guest/galleryData'
import './GalleryPage.css'

// Photos from GET /gallery.
export default function GalleryPage() {
  const [category, setCategory] = useState('All')
  const [openPhoto, setOpenPhoto] = useState(null)
  const gallery = useApiData(() => apiGet('/gallery'), [])
  const all = gallery.data ?? []

  const photos = category === 'All' ? all : all.filter((p) => p.category === category)

  return (
    <div className="gallery">
      {/* Phone: this becomes a slim top bar with just the title */}
      <header className="gallery-header">
        <h1>Gallery</h1>
        <p className="gallery-subtitle">Tap any photo to open it.</p>
      </header>

      <div className="gallery-filters">
        {GALLERY_CATEGORIES.map((cat) => (
          <FilterChip key={cat} active={category === cat} onClick={() => setCategory(cat)}>
            {cat}
          </FilterChip>
        ))}
      </div>

      {/* A mosaic: "wide" photos span two columns */}
      <div className="gallery-grid">
        {photos.map((photo) => (
          <button
            key={photo.id}
            type="button"
            className={`gallery-item ${photo.wide ? 'is-wide' : ''}`.trim()}
            onClick={() => setOpenPhoto(photo)}
          >
            <img src={photo.src} alt={photo.title} />
            <div className="gallery-item-caption">
              <p className="gallery-item-title">
                <span className="gallery-item-title-long">{photo.title}</span>
                <span className="gallery-item-title-short">{photo.shortTitle}</span>
              </p>
              <p className="gallery-item-space">{photo.space}</p>
            </div>
          </button>
        ))}
      </div>

      <Lightbox photo={openPhoto} onClose={() => setOpenPhoto(null)} />
    </div>
  )
}
