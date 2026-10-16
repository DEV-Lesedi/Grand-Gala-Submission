import { describe, expect, it } from 'vitest'
import { blockedDatesFromCalendars, canPickDay, monthCells } from './bookingCalendar'

describe('blockedDatesFromCalendars', () => {
  it('marks every date that is not free, across the spaces asked about', () => {
    const blocked = blockedDatesFromCalendars([
      [
        { date: '2026-12-12', state: 'booked' },
        { date: '2026-12-13', state: 'free' },
      ],
      [{ date: '2026-12-13', state: 'blacked_out' }],
    ])

    expect([...blocked].sort()).toEqual(['2026-12-12', '2026-12-13'])
  })
})

describe('canPickDay', () => {
  it('refuses a taken night as check-in or an event date', () => {
    expect(canPickDay('2026-12-12', { min: '2026-10-14', blocked: new Set(['2026-12-12']) })).toBe(false)
    expect(canPickDay('2026-12-13', { min: '2026-10-14', blocked: new Set(['2026-12-12']) })).toBe(true)
  })

  it('refuses days before the earliest allowed date', () => {
    expect(canPickDay('2026-10-13', { min: '2026-10-14' })).toBe(false)
  })

  it('refuses an enquiry date sooner than 12 days from today', () => {
    expect(canPickDay('2026-10-14', { min: '2026-10-25' })).toBe(false)
    expect(canPickDay('2026-10-24', { min: '2026-10-25' })).toBe(false)
    expect(canPickDay('2026-10-25', { min: '2026-10-25' })).toBe(true)
  })

  it('lets checkout fall on the morning the next stay begins', () => {
    const blocked = new Set(['2026-12-12'])
    expect(canPickDay('2026-12-12', { stayStart: '2026-12-11', blocked })).toBe(true)
    expect(canPickDay('2026-12-13', { stayStart: '2026-12-11', blocked })).toBe(false)
  })
})

describe('monthCells', () => {
  it('pads December 2026 so the 1st sits on a Tuesday', () => {
    const cells = monthCells('2026-12-01')

    expect(cells[0]).toBeNull()
    expect(cells[1]).toBe('2026-12-01')
    expect(cells.at(-1)).toBe('2026-12-31')
  })
})
