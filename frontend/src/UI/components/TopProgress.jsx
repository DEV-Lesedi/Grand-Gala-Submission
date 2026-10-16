import { useSyncExternalStore } from 'react'
import { activitySnapshot, onActivityChange } from '../../services/activity'
import './TopProgress.css'

// A thin bar along the top of the page while the app is waiting for the API.
// It creeps towards the end while busy, then runs to the end and fades out. Quick requests never show it.
export default function TopProgress() {
  const { busy, run, finished } = useSyncExternalStore(onActivityChange, activitySnapshot)
  if (!busy && !finished) return null

  // key: each busy spell gets a fresh bar, so it starts again from the left
  return <div key={run} className={`top-progress ${busy ? 'is-busy' : 'is-done'}`} aria-hidden="true" />
}
