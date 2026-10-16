import { STAY_STEPS } from '../../../../lib/guest/staysData'
import './StaySteps.css'

// current is 1–3. aria-current marks the step the guest is on.
export default function StaySteps({ current }) {
  return (
    <div className="stay-steps">
      <ol>
        {STAY_STEPS.map((step, index) => {
          const number = index + 1
          const state = number < current ? 'is-done' : number === current ? 'is-current' : ''
          return (
            <li key={step} className={state} aria-current={number === current ? 'step' : undefined}>
              <span className="stay-steps-number">{number < current ? '✓' : number}</span>
              {step}
            </li>
          )
        })}
      </ol>
    </div>
  )
}
