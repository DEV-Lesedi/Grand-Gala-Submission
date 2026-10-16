import './SegmentedControl.css'

// Joined buttons, one selected (All | Stays | Events). options: [{ value, label }]
export default function SegmentedControl({ options, value, onChange }) {
  return (
    <div className="segmented-control" role="group">
      {options.map((option) => (
        <button
          key={option.value}
          type="button"
          className={`segmented-option ${option.value === value ? 'is-active' : ''}`.trim()}
          aria-pressed={option.value === value}
          onClick={() => onChange(option.value)}
        >
          {option.label}
        </button>
      ))}
    </div>
  )
}
