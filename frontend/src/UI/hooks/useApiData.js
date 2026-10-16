import { useEffect, useRef, useState } from 'react'

// load() returns a promise. deps reload it. Previous data stays until the new answer arrives.
export function useApiData(load, deps) {
  const [round, setRound] = useState(0)
  const [answer, setAnswer] = useState({ key: null, data: null, error: null })
  // Which question this is: the same deps and round mean the same question
  const key = JSON.stringify([...deps, round])

  // Always call the latest load function, without making it a reason to load again
  const loadRef = useRef(load)
  useEffect(() => {
    loadRef.current = load
  })

  useEffect(() => {
    // A page that changes its question quickly (or closes) ignores the older answer
    let current = true
    loadRef.current()
      .then((data) => {
        if (current) setAnswer({ key, data, error: null })
      })
      .catch((error) => {
        if (current) setAnswer({ key, data: null, error })
      })
    return () => {
      current = false
    }
  }, [key])

  const loading = answer.key !== key
  return {
    data: answer.data,
    error: loading ? null : answer.error,
    loading,
    reload: () => setRound((value) => value + 1),
  }
}

// "We couldn't load" plus the thing, with the error message appended when there is one.
export function couldNotLoad(what, error) {
  const title = 'We couldn’t load ' + what
  return error ? title + ': ' + error.message : title
}

// While loading: "Loading…". On failure: couldNotLoad. Otherwise the ready summary.
export function loadedText(state, what, readyText) {
  if (state.error) return couldNotLoad(what, state.error)
  if (!state.data) return 'Loading…'
  return readyText
}
