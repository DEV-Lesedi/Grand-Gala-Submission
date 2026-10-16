import Modal from './Modal'
import './Lightbox.css'

// Gallery photo in a Modal (focus, Esc, scroll lock). photo is the photo, or null.
export default function Lightbox({ photo, onClose }) {
  if (!photo) return null

  return (
    <Modal title={photo.title} subtitle={photo.category} onClose={onClose} className="modal-photo">
      <img className="lightbox-image" src={photo.src} alt={photo.title} />
    </Modal>
  )
}
