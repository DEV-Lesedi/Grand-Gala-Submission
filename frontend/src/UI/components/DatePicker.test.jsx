// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import DatePicker from './DatePicker'
import Modal from './Modal'

describe('DatePicker', () => {
  afterEach(() => cleanup())

  it('does not choose a day that is already taken', async () => {
    const onChange = vi.fn()
    render(<DatePicker value="" min="2026-12-01" blocked={new Set(['2026-12-12'])} onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: 'Choose a date' }))
    expect(screen.getByRole('button', { name: '12 Dec 2026, not free' }).disabled).toBe(true)

    await userEvent.click(screen.getByRole('button', { name: '14 Dec 2026' }))
    expect(onChange).toHaveBeenCalledWith('2026-12-14')
  })

  it('does not choose a day sooner than the 12-day enquiry lead', async () => {
    const onChange = vi.fn()
    render(<DatePicker value="" min="2026-10-25" onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: 'Choose a date' }))
    expect(screen.getByRole('button', { name: '14 Oct 2026, not free' }).disabled).toBe(true)

    await userEvent.click(screen.getByRole('button', { name: '25 Oct 2026' }))
    expect(onChange).toHaveBeenCalledWith('2026-10-25')
  })

  it('closes only the calendar on Esc inside a popup, not the popup itself', async () => {
    const onClose = vi.fn()
    render(
      <Modal title="New event" onClose={onClose}>
        <DatePicker value="" onChange={() => {}} />
      </Modal>,
    )
    await userEvent.click(screen.getByRole('button', { name: 'Choose a date' }))
    expect(screen.getByRole('dialog', { name: 'Choose a date' })).toBeTruthy()

    await userEvent.keyboard('{Escape}')

    expect(screen.queryByRole('dialog', { name: 'Choose a date' })).toBeNull()
    expect(onClose).not.toHaveBeenCalled()
  })

  it('greys out days after max', async () => {
    render(<DatePicker value="2026-12-10" max="2026-12-12" onChange={() => {}} />)
    await userEvent.click(screen.getByRole('button'))

    expect(screen.getByRole('button', { name: /^12 Dec 2026$/ }).disabled).toBe(false)
    expect(screen.getByRole('button', { name: /^13 Dec 2026/ }).disabled).toBe(true)
  })
})
