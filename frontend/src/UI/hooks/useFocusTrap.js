import { useEffect } from 'react'

// Everything the Tab key can land on.
const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

// While isActive, Tab and Shift+Tab loop inside containerRef.
export function useFocusTrap(containerRef, isActive) {
  useEffect(() => {
    if (!isActive) return

    function keepFocusInside(event) {
      const container = containerRef.current
      // Only when focus is inside (a popup on top has its own trap)
      if (event.key !== 'Tab' || !container || !container.contains(document.activeElement)) return

      // On screen only (skips display: none)
      const focusable = [...container.querySelectorAll(FOCUSABLE)].filter((element) => element.getClientRects().length > 0)
      if (focusable.length === 0) return
      const first = focusable[0]
      const last = focusable[focusable.length - 1]

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault() // stop the browser's own Tab move
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', keepFocusInside)
    return () => document.removeEventListener('keydown', keepFocusInside)
  }, [containerRef, isActive])
}
