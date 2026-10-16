import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { canPickDay, monthCells } from '../../lib/bookingCalendar'
import { addMonths, formatDate, formatMonth, startOfMonth, TODAY } from '../../lib/dates'
import './DatePicker.css'

const WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']

function dayLabel(date, available) {
  return available ? formatDate(date) : `${formatDate(date)}, not free`
}

// YYYY-MM-DD. Grey blocked days. stayStart: check-out whose nights must be free. bare: no border.
export default function DatePicker({
  id,
  name,
  value = '',
  onChange,
  min,
  max,
  blocked = new Set(),
  stayStart = '',
  variant = 'box',
  disabled = false,
  className = '',
  'aria-invalid': ariaInvalid,
  'aria-describedby': ariaDescribedBy,
}) {
  const generatedId = useId()
  const triggerId = id ?? generatedId
  const triggerRef = useRef(null)
  const popRef = useRef(null)
  const [open, setOpen] = useState(false)
  const [month, setMonth] = useState(() => startOfMonth(value || min || TODAY))
  const [popStyle, setPopStyle] = useState({ top: 0, left: 0 })

  function placePop() {
    const trigger = triggerRef.current
    if (!trigger) return
    const box = trigger.getBoundingClientRect()
    const width = 292
    const left = Math.min(Math.max(8, box.left), window.innerWidth - width - 8)
    const below = box.bottom + 6
    const top = below + 340 > window.innerHeight && box.top > 340 ? box.top - 334 : below
    setPopStyle({ top, left })
  }

  useLayoutEffect(() => {
    if (open) placePop()
  }, [open, month])

  useEffect(() => {
    if (!open) return
    function onPointer(event) {
      if (triggerRef.current?.contains(event.target) || popRef.current?.contains(event.target)) return
      setOpen(false)
    }
    // Capture phase: Esc closes only the calendar, not a Modal it sits in
    function onKey(event) {
      if (event.key !== 'Escape') return
      event.stopPropagation()
      setOpen(false)
      triggerRef.current?.focus()
    }
    document.addEventListener('mousedown', onPointer)
    window.addEventListener('keydown', onKey, true)
    window.addEventListener('resize', placePop)
    return () => {
      document.removeEventListener('mousedown', onPointer)
      window.removeEventListener('keydown', onKey, true)
      window.removeEventListener('resize', placePop)
    }
  }, [open])

  function pick(date) {
    if (!canPickDay(date, { min, max, blocked, stayStart })) return
    onChange(date)
    setOpen(false)
  }

  function openPicker() {
    setMonth(startOfMonth(value || min || TODAY))
    setOpen(true)
  }

  return (
    <div className={`date-picker ${variant === 'bare' ? 'is-bare' : ''} ${className}`.trim()}>
      <button
        ref={triggerRef}
        type="button"
        id={triggerId}
        name={name}
        className="date-picker-trigger"
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-invalid={ariaInvalid}
        aria-describedby={ariaDescribedBy}
        disabled={disabled}
        onClick={() => (open ? setOpen(false) : openPicker())}
      >
        {value ? formatDate(value) : 'Choose a date'}
      </button>

      {open &&
        createPortal(
          <div ref={popRef} className="date-picker-pop" style={popStyle} role="dialog" aria-label="Choose a date">
            <div className="date-picker-nav">
              <button type="button" className="date-picker-nav-btn" onClick={() => setMonth(addMonths(month, -1))} aria-label="Previous month">
                ‹
              </button>
              <p>{formatMonth(month)}</p>
              <button type="button" className="date-picker-nav-btn" onClick={() => setMonth(addMonths(month, 1))} aria-label="Next month">
                ›
              </button>
            </div>
            <div className="date-picker-week">
              {WEEKDAYS.map((day) => (
                <span key={day}>{day}</span>
              ))}
            </div>
            <div className="date-picker-grid">
              {monthCells(month).map((date, index) => {
                if (!date) return <span key={`pad-${index}`} />
                const available = canPickDay(date, { min, max, blocked, stayStart })
                return (
                  <button
                    key={date}
                    type="button"
                    className={`date-picker-day ${date === value ? 'is-selected' : ''} ${date === TODAY ? 'is-today' : ''} ${available ? '' : 'is-blocked'}`.trim()}
                    disabled={!available}
                    aria-label={dayLabel(date, available)}
                    aria-pressed={date === value}
                    onClick={() => pick(date)}
                  >
                    {Number(date.slice(8))}
                  </button>
                )
              })}
            </div>
            {(blocked.size > 0 || stayStart) && <p className="date-picker-hint">Grey days are already booked or closed.</p>}
          </div>,
          document.body,
        )}
    </div>
  )
}
