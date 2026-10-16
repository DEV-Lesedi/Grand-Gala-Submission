// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import Select from './Select'
import Modal from './Modal'

// Like a form using it: keeps the value, and records every change event it gets
function Form({ onChange = () => {} }) {
  const [value, setValue] = useState('')
  return (
    <Select
      name="catering"
      value={value}
      aria-label="Catering"
      onChange={(event) => {
        onChange(event)
        setValue(event.target.value)
      }}
    >
      <option value="">Choose…</option>
      <option value="quote">Please quote catering</option>
      <option value="own">We'll bring our own caterer</option>
      <option value="none" disabled>
        No catering
      </option>
    </Select>
  )
}

describe('Select', () => {
  afterEach(cleanup)

  it('shows the empty option as a hint, then the chosen label', async () => {
    const onChange = vi.fn()
    render(<Form onChange={onChange} />)
    const trigger = screen.getByRole('combobox', { name: 'Catering' })
    expect(trigger.textContent).toContain('Choose…')

    await userEvent.click(trigger)
    await userEvent.click(screen.getByRole('option', { name: 'Please quote catering' }))

    // The same shape a <select> change event has, so existing handlers keep working
    expect(onChange).toHaveBeenCalledWith({ target: { name: 'catering', value: 'quote' } })
    expect(trigger.textContent).toContain('Please quote catering')
    expect(screen.queryByRole('listbox')).toBeNull()
  })

  it('works from the keyboard: open, arrow down, Enter', async () => {
    render(<Form />)
    const trigger = screen.getByRole('combobox', { name: 'Catering' })
    trigger.focus()

    await userEvent.keyboard('{ArrowDown}{ArrowDown}{ArrowDown}{Enter}')

    expect(trigger.textContent).toContain("We'll bring our own caterer")
  })

  it('skips options that are switched off', async () => {
    render(<Form />)
    await userEvent.click(screen.getByRole('combobox', { name: 'Catering' }))

    await userEvent.click(screen.getByRole('option', { name: 'No catering' }))

    expect(screen.getByRole('combobox', { name: 'Catering' }).textContent).toContain('Choose…')
  })

  it('closes only its list on Esc inside a popup, not the popup', async () => {
    const onClose = vi.fn()
    render(
      <Modal title="New event" onClose={onClose}>
        <Form />
      </Modal>,
    )
    await userEvent.click(screen.getByRole('combobox', { name: 'Catering' }))
    expect(screen.getByRole('listbox')).toBeTruthy()

    await userEvent.keyboard('{Escape}')

    expect(screen.queryByRole('listbox')).toBeNull()
    expect(onClose).not.toHaveBeenCalled()
  })
})
