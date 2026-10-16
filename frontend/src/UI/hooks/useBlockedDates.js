import { addDays, TODAY } from '../../lib/dates'
import { loadBlockedDates } from '../../services/stays'
import { useApiData } from './useApiData'

// Days these spaces cannot take, from today through the next year. spaceIds: database ids.
export function useBlockedDates(spaceIds) {
  const ids = [...new Set(spaceIds.filter(Boolean))].sort((a, b) => a - b)
  const from = TODAY
  const to = addDays(TODAY, 365)
  const loaded = useApiData(() => loadBlockedDates(ids, from, to), [ids.join(','), from, to])
  return loaded.data ?? new Set()
}
