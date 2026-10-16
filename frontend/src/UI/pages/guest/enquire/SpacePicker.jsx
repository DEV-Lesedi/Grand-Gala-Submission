import './SpacePicker.css'

// Cards to tick any number of hire spaces, or none. chosen is the ticked ids.
export default function SpacePicker({ spaces, chosen, onChange }) {
  function toggle(id) {
    onChange(chosen.includes(id) ? chosen.filter((item) => item !== id) : [...chosen, id])
  }

  return (
    <fieldset className="space-picker">
      <legend>
        Spaces of interest <span>Select any.</span>
      </legend>
      <div className="space-picker-grid">
        {spaces.map((space) => (
          // aria-pressed: screen readers say whether each card is ticked
          <button
            key={space.id}
            type="button"
            className="space-picker-card"
            aria-pressed={chosen.includes(space.id)}
            onClick={() => toggle(space.id)}
          >
            <img src={space.photo} alt="" />
            <span className="space-picker-name">{space.name}</span>
            <span className="space-picker-meta">Up to {space.capacity} guests</span>
          </button>
        ))}
      </div>
    </fieldset>
  )
}
