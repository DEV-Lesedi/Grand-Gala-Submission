import { useEffect, useRef } from 'react'
import Card from '../../../components/Card'
import { useFocusTrap } from '../../../hooks/useFocusTrap'
import './DetailPanel.css'

// ≥1280px: always shown. Smaller: slides over when isOpen; Back or Esc calls onClose
export default function DetailPanel({ isOpen, onClose, backLabel, className = '', children }) {
  const backButtonRef = useRef(null) // a handle on the real back button in the page
  const panelRef = useRef(null)

  // Keep Tab inside while it covers the page
  useFocusTrap(panelRef, isOpen)

  // Open: focus Back. Close: focus returns to the row/card
  useEffect(() => {
    if (!isOpen) return
    const openedFrom = document.activeElement
    backButtonRef.current.focus()
    return () => openedFrom.focus()
  }, [isOpen])

  // Esc closes it while open
  useEffect(() => {
    if (!isOpen) return
    function closeOnEscape(event) {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', closeOnEscape)
    return () => window.removeEventListener('keydown', closeOnEscape)
  }, [isOpen, onClose])

  return (
    <Card as="aside" className={`detail-panel ${className} ${isOpen ? 'is-open' : ''}`.trim()} ref={panelRef}>
      <button type="button" className="link-button detail-panel-back" onClick={onClose} ref={backButtonRef}>
        ← {backLabel}
      </button>
      {children}
    </Card>
  )
}
