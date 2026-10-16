import { useState } from 'react'
import Button from '../../../components/Button'
import { priceWithCode } from '../../../../services/checkout'
import { formatRand } from '../../../../lib/money'
import './PromoCodeBox.css'

// Preview only until Pay, which holds the code. locked means Pay already used it.
export default function PromoCodeBox({ order, applied, onApply, onRemove, locked }) {
  const [code, setCode] = useState('')
  const [checking, setChecking] = useState(false)
  const [problem, setProblem] = useState('')

  async function apply() {
    const typed = code.trim().toUpperCase()
    if (!typed) {
      setProblem('Type a code first.')
      return
    }
    setChecking(true)
    setProblem('')
    try {
      onApply(await priceWithCode(order, typed))
      setCode('')
    } catch (error) {
      setProblem(error.message)
    }
    setChecking(false)
  }

  // Enter in the box applies the code instead of paying
  function handleKeyDown(event) {
    if (event.key === 'Enter') {
      event.preventDefault()
      apply()
    }
  }

  if (applied) {
    return (
      <div className="promo-code is-applied">
        <p>
          <strong>{applied.code}</strong> applied · {formatRand(applied.discount)} off
        </p>
        {!locked && (
          <button type="button" className="promo-code-remove" onClick={onRemove}>
            Remove
          </button>
        )}
      </div>
    )
  }

  return (
    <div className="promo-code">
      <label htmlFor="checkout-promo">
        Discount code <span className="checkout-optional">(optional)</span>
      </label>
      <div className="promo-code-row">
        <input
          id="checkout-promo"
          value={code}
          onChange={(event) => setCode(event.target.value)}
          onKeyDown={handleKeyDown}
          placeholder="e.g. SPRING10"
          autoComplete="off"
          aria-invalid={Boolean(problem)}
          aria-describedby={problem ? 'checkout-promo-error' : undefined}
          disabled={locked}
        />
        <Button type="button" variant="secondary" onClick={apply} disabled={checking || locked}>
          {checking ? 'Checking…' : 'Apply'}
        </Button>
      </div>
      {problem && (
        <span id="checkout-promo-error" className="checkout-error" role="alert">
          {problem}
        </span>
      )}
    </div>
  )
}
