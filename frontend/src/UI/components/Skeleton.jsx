import './Skeleton.css'

// Grey shimmering shapes where content will appear while it loads.
// Each one waits 150ms before showing (skeleton-wait in Skeleton.css), so quick loads don't flash.

export function SkeletonBlock({ width = '100%', height = '16px', className = '' }) {
  return <div className={`skeleton-block ${className}`.trim()} style={{ width, height }} />
}

export function SkeletonCard() {
  return (
    <div className="skeleton-card">
      <SkeletonBlock height="160px" />
      <SkeletonBlock height="14px" width="70%" />
      <SkeletonBlock height="12px" width="40%" />
    </div>
  )
}

// count cards in the page's own grid (className: e.g. "browse-grid"), for lists of spaces or events
export function SkeletonCards({ count = 6, className = '' }) {
  return (
    <div className={`skeleton-wait ${className}`.trim()} aria-busy="true" aria-label="Loading">
      {Array.from({ length: count }, (_, index) => (
        <SkeletonCard key={index} />
      ))}
    </div>
  )
}

// count rows, for lists like My bookings or the staff tables
export function SkeletonRows({ count = 4 }) {
  return (
    <div className="skeleton-wait skeleton-rows" aria-busy="true" aria-label="Loading">
      {Array.from({ length: count }, (_, index) => (
        <div key={index} className="skeleton-row">
          <SkeletonBlock width="64px" height="64px" />
          <div className="skeleton-row-text">
            <SkeletonBlock height="14px" width="55%" />
            <SkeletonBlock height="12px" width="35%" />
          </div>
        </div>
      ))}
    </div>
  )
}

// A whole page still loading: a heading, a photo and a few lines (Loaded uses it)
export function SkeletonPage() {
  return (
    <div className="skeleton-wait skeleton-page" aria-busy="true" aria-label="Loading">
      <SkeletonBlock height="28px" width="45%" />
      <SkeletonBlock height="14px" width="30%" />
      <SkeletonBlock height="240px" className="skeleton-page-photo" />
      <SkeletonBlock height="14px" width="90%" />
      <SkeletonBlock height="14px" width="80%" />
      <SkeletonBlock height="14px" width="60%" />
    </div>
  )
}

export default SkeletonBlock
