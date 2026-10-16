import { useOnlineStatus } from '../hooks/useOnlineStatus'
import './OfflineBanner.css'

// A thin bar across the top while offline. Spaces, the gallery and saved favourites keep working
// from the service worker's cache (vite.config.js); anything else just fails until signal returns.
export default function OfflineBanner() {
  const online = useOnlineStatus()
  if (online) return null

  return (
    <div className="offline-banner" role="status">
      You&rsquo;re offline — showing saved pages. Some actions need a connection.
    </div>
  )
}
