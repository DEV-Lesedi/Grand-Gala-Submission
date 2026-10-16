import './SearchInput.css'

// Controlled input: parent owns value, gets onChange
export default function SearchInput({ value, onChange, placeholder, className = '' }) {
  return (
    <label className={`search-input ${className}`.trim()}>
      <span className="search-input-icon" aria-hidden="true">
        ⌕
      </span>
      <input
        type="search"
        value={value}
        placeholder={placeholder}
        aria-label={placeholder}
        onChange={(event) => onChange(event.target.value)}
      />
    </label>
  )
}
