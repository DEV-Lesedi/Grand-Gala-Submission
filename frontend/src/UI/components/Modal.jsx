import { useEffect, useId, useRef } from 'react'
import { createPortal } from 'react-dom'
import { useFocusTrap } from '../hooks/useFocusTrap'
import './Modal.css'

// Render only while open. Closes with ✕, Esc, or a click outside.
export default function Modal({ title, subtitle, footer, onClose, className = '', children }) {
  const closeButtonRef = useRef(null)
  const modalRef = useRef(null)
  const titleId = useId() // a unique id, so aria-labelledby can point at this popup's heading

  // Open: focus ✕ and lock page scroll. Close: undo both
  useEffect(() => {
    const openedFrom = document.activeElement
    closeButtonRef.current.focus()
    document.body.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = ''
      openedFrom?.focus()
    }
  }, [])

  // Capture phase + stopPropagation: Esc closes only this popup
  useEffect(() => {
    function closeOnEscape(event) {
      if (event.key !== 'Escape') return
      event.stopPropagation()
      onClose()
    }
    document.addEventListener('keydown', closeOnEscape, true)
    return () => document.removeEventListener('keydown', closeOnEscape, true)
  }, [onClose])

  useFocusTrap(modalRef, true)

  // Portal: render at the end of <body> so no parent can clip it
  return createPortal(
    <div className="modal-backdrop" onClick={onClose}>
      {/* A click inside isn't a click on the backdrop */}
      <div
        className={`modal ${className}`.trim()}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        ref={modalRef}
        onClick={(event) => event.stopPropagation()}
      >
        <header className="modal-header">
          <div className="modal-heading">
            <h2 className="modal-title" id={titleId}>
              {title}
            </h2>
            {subtitle && <p className="modal-subtitle">{subtitle}</p>}
          </div>
          <button type="button" className="modal-close" onClick={onClose} ref={closeButtonRef} aria-label="Close">
            ✕
          </button>
        </header>

        {children ? <div className="modal-body">{children}</div> : null}

        {footer && <footer className="modal-footer">{footer}</footer>}
      </div>
    </div>,
    document.body,
  )
}
