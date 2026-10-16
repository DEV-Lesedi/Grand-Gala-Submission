import { Children, isValidElement, useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import './Select.css'

// A dropdown drawn by the app instead of the browser. The browser's own list ignored the page's size
// (tiny and in the wrong place on phones and in a phone preview); this one matches the form.
// Used like a <select>: name, value, onChange(event), disabled, aria-*, and <option value>…</option> children.
// onChange gets { target: { name, value } }, so handlers written for a <select> work as they are.
// variant "bare": no border, for boxes that sit inside another box (the stay search bar).
export default function Select({
  id,
  name,
  value = '',
  onChange,
  disabled = false,
  variant = 'box',
  className = '',
  children,
  'aria-invalid': ariaInvalid,
  'aria-describedby': ariaDescribedBy,
  'aria-label': ariaLabel,
}) {
  const options = optionsFrom(children)
  const generatedId = useId()
  const triggerId = id ?? generatedId
  const listId = triggerId + '-list'
  const triggerRef = useRef(null)
  const listRef = useRef(null)
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0) // the option the arrow keys are on
  const [listStyle, setListStyle] = useState({ top: 0, left: 0, width: 0 })

  const chosen = options.find((option) => option.value === String(value)) ?? options[0]

  // Under the box, as wide as it; above it when there's no room below
  function placeList() {
    const trigger = triggerRef.current
    if (!trigger) return
    const box = trigger.getBoundingClientRect()
    const height = Math.min(options.length * 44 + 12, 300)
    const width = Math.max(box.width, 200)
    const left = Math.min(Math.max(8, box.left), window.innerWidth - width - 8)
    const below = box.bottom + 6
    const top = below + height > window.innerHeight && box.top > height + 6 ? box.top - height - 6 : below
    setListStyle({ top, left, width })
  }

  // Placed once as it opens; scrolling and resizing move it again (below)
  useLayoutEffect(() => {
    if (open) placeList()
  }, [open]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!open) return
    function onPointer(event) {
      if (triggerRef.current?.contains(event.target) || listRef.current?.contains(event.target)) return
      setOpen(false)
    }
    // On window, before anything else: Esc closes only the list, not a popup (Modal) it sits in
    function onKey(event) {
      if (event.key !== 'Escape') return
      event.stopPropagation()
      close()
    }
    function onScroll(event) {
      if (!listRef.current?.contains(event.target)) placeList()
    }
    document.addEventListener('mousedown', onPointer)
    window.addEventListener('keydown', onKey, true)
    window.addEventListener('resize', placeList)
    window.addEventListener('scroll', onScroll, true)
    return () => {
      document.removeEventListener('mousedown', onPointer)
      window.removeEventListener('keydown', onKey, true)
      window.removeEventListener('resize', placeList)
      window.removeEventListener('scroll', onScroll, true)
    }
  })

  // Keep the highlighted option in view while using the arrow keys
  useEffect(() => {
    if (open) listRef.current?.children[active]?.scrollIntoView?.({ block: 'nearest' })
  }, [open, active])

  function openList() {
    if (disabled) return
    setActive(Math.max(0, options.indexOf(chosen)))
    setOpen(true)
  }

  function close() {
    setOpen(false)
    triggerRef.current?.focus()
  }

  function choose(option) {
    if (option.disabled) return
    if (option.value !== String(value)) onChange?.({ target: { name, value: option.value } })
    close()
  }

  // Next option that can be chosen, from index in direction step (1 or -1)
  function step(from, by) {
    let index = from
    for (let tries = 0; tries < options.length; tries++) {
      index = (index + by + options.length) % options.length
      if (!options[index].disabled) return index
    }
    return from
  }

  function handleKey(event) {
    if (!open) {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
        event.preventDefault()
        openList()
      }
      return
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActive(step(active, 1))
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActive(step(active, -1))
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      choose(options[active])
    } else if (event.key === 'Tab') {
      setOpen(false)
    }
  }

  return (
    <div className={`select ${variant === 'bare' ? 'is-bare' : ''} ${className}`.trim()}>
      <button
        ref={triggerRef}
        type="button"
        id={triggerId}
        name={name}
        className={`select-trigger ${chosen && chosen.value === '' ? 'is-placeholder' : ''}`.trim()}
        role="combobox"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        aria-activedescendant={open ? `${listId}-${active}` : undefined}
        aria-invalid={ariaInvalid}
        aria-describedby={ariaDescribedBy}
        aria-label={ariaLabel}
        disabled={disabled}
        onClick={() => (open ? close() : openList())}
        onKeyDown={handleKey}
      >
        <span className="select-value">{chosen?.label}</span>
        <span className="select-chevron" aria-hidden="true" />
      </button>

      {open &&
        createPortal(
          <ul ref={listRef} id={listId} className="select-list" role="listbox" style={listStyle}>
            {options.map((option, index) => (
              <li
                key={option.value}
                id={`${listId}-${index}`}
                role="option"
                aria-selected={option.value === String(value)}
                aria-disabled={option.disabled || undefined}
                className={`select-option ${index === active ? 'is-active' : ''} ${option.disabled ? 'is-disabled' : ''}`.trim()}
                onMouseEnter={() => !option.disabled && setActive(index)}
                // mousedown, not click: picks before the trigger loses focus
                onMouseDown={(event) => {
                  event.preventDefault()
                  choose(option)
                }}
              >
                {option.label}
                {option.value === String(value) && <span className="select-tick" aria-hidden="true">✓</span>}
              </li>
            ))}
          </ul>,
          document.body,
        )}
    </div>
  )
}

// <option value="x">Label</option> children → [{ value: 'x', label: 'Label', disabled }]
function optionsFrom(children) {
  const options = []
  Children.forEach(children, (child) => {
    if (!isValidElement(child) || child.type !== 'option') return
    const label = child.props.children
    const value = child.props.value ?? (typeof label === 'string' ? label : '')
    options.push({ value: String(value), label, disabled: Boolean(child.props.disabled) })
  })
  return options
}
