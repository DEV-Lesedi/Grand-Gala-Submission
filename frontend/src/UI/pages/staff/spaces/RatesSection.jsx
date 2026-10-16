import { useState } from 'react'
import RateForm from './RateForm'
import { rateSchedule } from '../../../../lib/rateSchedule'
import { dateRangeText } from './spaceHelpers'
import { formatDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import './RatesSection.css'

// Layout only. rateSchedule() chooses the rows, and each action saves immediately.
export default function RatesSection({ space, rates, onAddRate, onCancelRate, onEndRateNow }) {
  const [form, setForm] = useState(null) // the open popup: { mode, basis, start } or null

  const schedule = rateSchedule(rates) // one entry per kind of price

  return (
    <section className="rates-section">
      <h3 className="rates-section-title">
        Rates <span>saved straight away</span>
      </h3>

      {schedule.map((group) => (
        <RateGroup
          key={group.kind.value}
          group={group}
          onChangePrice={(start) => setForm({ mode: 'change', basis: group.kind.value, start })}
          onAddSeason={(start) => setForm({ mode: 'season', basis: group.kind.value, start })}
          onCancel={(rate) => onCancelRate(rate)}
          onEndNow={(rate) => onEndRateNow(rate)}
        />
      ))}

      {schedule.length === 0 && <p className="rates-empty">No price yet. Guests can't book this space until it has one.</p>}

      <button type="button" className="link-button rates-link" onClick={() => setForm({ mode: 'change', basis: null })}>
        {schedule.length === 0 ? '+ Add a price' : '+ Add another kind of price'}
      </button>

      {form && (
        <RateForm
          mode={form.mode}
          space={space}
          basis={form.basis}
          rates={rates}
          start={form.start}
          onAdd={(rate) => onAddRate(rate)}
          onClose={() => setForm(null)}
        />
      )}
    </section>
  )
}

// group is one rateSchedule() entry: now, coming up, and past.
function RateGroup({ group, onChangePrice, onAddSeason, onCancel, onEndNow }) {
  const [showPast, setShowPast] = useState(false)
  const { kind, now, backTo, nextPrice, thenFrom, upcoming, past, canEndNow, hasGapAfter, changeLabel } = group

  return (
    <div className="rate-group">
      <p className="rate-group-kind">{kind.chip}</p>

      <div className={`rate-now ${now ? '' : 'is-empty'}`.trim()}>
        <div className="rate-now-text">
          <span className="rate-now-label">Now</span>
          {now ? (
            <>
              <span className="rate-now-price">
                {formatRand(now.price)} <small>/ {kind.unit}</small>
              </span>
              <span className="rate-now-detail">
                {now.label} · {now.validTo ? `until ${formatDate(now.validTo)}` : `since ${formatDate(now.validFrom)}`}
              </span>
              {backTo && (
                <span className="rate-now-then">
                  Then back to {formatRand(backTo.price)} · {backTo.label}
                </span>
              )}
              {nextPrice && (
                <span className="rate-now-then">
                  Then {formatRand(nextPrice.price)} · {nextPrice.label} from {formatDate(thenFrom)}
                </span>
              )}
              {hasGapAfter && <span className="rate-now-warning">No price after {formatDate(now.validTo)}</span>}
            </>
          ) : (
            <span className="rate-now-detail">No price today</span>
          )}
        </div>
        <div className="rate-now-actions">
          {/* The normal price can only be changed */}
          {canEndNow && (
            <button type="button" className="rate-button" onClick={() => onEndNow(now)}>
              End now
            </button>
          )}
          <button type="button" className="rate-button is-main" onClick={() => onChangePrice({ label: changeLabel })}>
            {now ? 'Change price' : 'Set price'}
          </button>
        </div>
      </div>

      {upcoming.length > 0 && (
        <>
          <p className="rate-list-title">Coming up</p>
          <ul className="rate-list">
            {upcoming.map((rate) => (
              <li key={rate.id} className="rate-item">
                <span className="rate-item-text">
                  <strong>{formatRand(rate.price)}</strong> · {rate.label}
                  <span className="rate-item-period">
                    {rate.validTo ? dateRangeText(rate.validFrom, rate.validTo) : `From ${formatDate(rate.validFrom)}, no end`}
                  </span>
                  {/* A season covering its start day wins until it ends */}
                  {rate.waitsFor && (
                    <span className="rate-item-waits">
                      Applies after {rate.waitsFor.label} ({formatDate(rate.waitsFor.validTo)})
                    </span>
                  )}
                </span>
                <button type="button" className="rate-button" onClick={() => onCancel(rate)}>
                  Cancel
                </button>
              </li>
            ))}
          </ul>
        </>
      )}

      <button type="button" className="link-button rates-link" onClick={() => onAddSeason()}>
        + Add a seasonal price
      </button>

      {past.length > 0 && (
        <>
          {/* aria-expanded: is the list open? */}
          <button type="button" className="link-button rate-past-toggle" aria-expanded={showPast} onClick={() => setShowPast(!showPast)}>
            {showPast ? '▾' : '▸'} Past prices ({past.length})
          </button>
          {showPast && (
            <ul className="rate-list">
              {past.map((rate) => (
                <li key={rate.id} className="rate-item is-past">
                  <span className="rate-item-text">
                    <strong>{formatRand(rate.price)}</strong> · {rate.label} · {rate.tag}
                    <span className="rate-item-period">
                      {rate.validTo ? dateRangeText(rate.validFrom, rate.validTo) : `From ${formatDate(rate.validFrom)}`}
                    </span>
                  </span>
                  {/* Prefills the form (a past season stays a season) */}
                  <button
                    type="button"
                    className="rate-button"
                    // A past season returns as a season, never as the permanent price.
                    onClick={() => (rate.isSeasonal ? onAddSeason : onChangePrice)({ label: rate.label, price: rate.price })}
                  >
                    Use again
                  </button>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </div>
  )
}
