import './Table.css'

// Optional: onRowClick + selectedKey (clickable rows), getRowClass(row) (extra row class)
export default function Table({ columns = [], rows = [], getRowKey, onRowClick, selectedKey, getRowClass }) {
  return (
    <div className="table-wrap">
      <table className="table">
        <thead>
          <tr>
            {columns.map((col) => (
              <th key={col.key}>{col.header}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => {
            const rowKey = getRowKey ? getRowKey(row) : i
            const rowClass = [onRowClick && 'is-clickable', rowKey === selectedKey && 'is-selected', getRowClass?.(row)]
              .filter(Boolean)
              .join(' ')

            // Rows work with Tab + Enter/Space
            const clickProps = onRowClick
              ? {
                  onClick: () => onRowClick(row),
                  tabIndex: 0,
                  onKeyDown: (event) => {
                    // Only when the row itself is focused, not a button inside it
                    if (event.target !== event.currentTarget) return
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault() // stops Space from scrolling the page
                      onRowClick(row)
                    }
                  },
                }
              : {}

            return (
              <tr key={rowKey} className={rowClass || undefined} {...clickProps}>
                {columns.map((col) => (
                  <td key={col.key}>{col.render ? col.render(row) : row[col.key]}</td>
                ))}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
