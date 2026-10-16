import { describe, expect, it } from 'vitest'
import {
  BOOKING_STATUSES,
  MY_BOOKING_STATUSES,
  QUOTATION_STATUSES,
  TICKET_ORDER_STATUSES,
  guestQuotationStatus,
  isQuotationPaid,
  statusLabel,
  statusTone,
} from './statuses'

describe('statusLabel', () => {
  it('gives the staff words by default', () => {
    expect(statusLabel(BOOKING_STATUSES, 'pending_payment')).toBe('Pending payment')
    expect(statusLabel(QUOTATION_STATUSES, 'sent')).toBe('Sent')
  })

  it('gives the guest words when guests see other words', () => {
    expect(statusLabel(QUOTATION_STATUSES, 'sent', 'guest')).toBe('Awaiting your decision')
    expect(statusLabel(QUOTATION_STATUSES, 'accepted', 'guest')).toBe('Accepted · awaiting payment')
  })

  it('falls back to the normal words when there are no guest words', () => {
    expect(statusLabel(QUOTATION_STATUSES, 'declined', 'guest')).toBe('Declined')
    expect(statusLabel(MY_BOOKING_STATUSES, 'quote_sent', 'guest')).toBe('Quote sent')
    expect(statusLabel(TICKET_ORDER_STATUSES, 'paid', 'guest')).toBe('Tickets ready')
  })

  it('shows an unknown status as it is', () => {
    expect(statusLabel(BOOKING_STATUSES, 'on_hold')).toBe('on_hold')
  })
})

describe('statusTone', () => {
  it('gives the chip colour', () => {
    expect(statusTone(BOOKING_STATUSES, 'confirmed')).toBe('success')
    expect(statusTone(BOOKING_STATUSES, 'quoted')).toBe('warning')
    expect(statusTone(MY_BOOKING_STATUSES, 'tickets')).toBe('success')
    expect(statusTone(TICKET_ORDER_STATUSES, 'pending_payment')).toBe('warning')
  })

  it('is grey for an unknown status', () => {
    expect(statusTone(BOOKING_STATUSES, 'on_hold')).toBe('muted')
  })
})

describe('the tables use the API keys', () => {
  it('My bookings cards use quote_sent and tickets, staff bookings use quoted', () => {
    expect(Object.keys(MY_BOOKING_STATUSES)).toEqual(
      expect.arrayContaining(['enquiry', 'quote_sent', 'pending_payment', 'confirmed', 'tickets', 'cancelled', 'expired', 'refunded']),
    )
    expect(BOOKING_STATUSES.quoted).toBeDefined()
    expect(BOOKING_STATUSES.quote_sent).toBeUndefined()
  })
})

describe('isQuotationPaid', () => {
  it('is paid once the API sends a paidDate', () => {
    expect(isQuotationPaid({ status: 'accepted', paidDate: '2026-09-01' })).toBe(true)
  })

  it('is not paid while paidDate is null', () => {
    expect(isQuotationPaid({ status: 'accepted', paidDate: null })).toBe(false)
  })
})

describe('guestQuotationStatus', () => {
  const today = '2026-10-13'

  it('is sent while it is still valid', () => {
    expect(guestQuotationStatus({ status: 'sent', validUntil: '2026-10-20' }, today)).toBe('sent')
  })

  it('is expired once a sent one is past its valid-until date', () => {
    expect(guestQuotationStatus({ status: 'sent', validUntil: '2026-10-12' }, today)).toBe('expired')
  })

  it('is accepted until paid, then paid', () => {
    expect(guestQuotationStatus({ status: 'accepted', paidDate: null }, today)).toBe('accepted')
    expect(guestQuotationStatus({ status: 'accepted', paidDate: '2026-10-01' }, today)).toBe('paid')
  })

  it('is declined, and a replaced one counts as expired', () => {
    expect(guestQuotationStatus({ status: 'declined' }, today)).toBe('declined')
    expect(guestQuotationStatus({ status: 'superseded' }, today)).toBe('expired')
  })
})
