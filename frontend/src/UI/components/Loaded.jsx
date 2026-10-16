import EmptyState from './EmptyState'
import { SkeletonPage } from './Skeleton'
import { couldNotLoad } from '../hooks/useApiData'

// Loading (a page-shaped skeleton, or `loading` if given), error with Try again, then not found.
// find() with no match uses notFound. The content fades in when it arrives.
export default function Loaded({ state, what, notFound = null, find, loading = <SkeletonPage />, children }) {
  if (state.loading && !state.data) return loading

  if (state.error) {
    return (
      <EmptyState
        titleAs="h1"
        title={couldNotLoad(what)}
        description={state.error.message}
        actionLabel="Try again"
        onAction={state.reload}
      />
    )
  }

  const found = find ? find(state.data) : state.data
  if (!found) return notFound
  return <div className="fade-in">{children(found)}</div>
}
