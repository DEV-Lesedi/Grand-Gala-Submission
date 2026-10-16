import { useState } from 'react'

// Each change goes to the API, then reload. problem holds the API's refusal.
export function useSave(reload) {
  const [problem, setProblem] = useState('')

  async function save(change) {
    setProblem('') // the last refusal goes away when the next change is tried
    try {
      const answer = await change()
      reload()
      return answer
    } catch (error) {
      setProblem(error.message)
      return null
    }
  }

  return { save, problem }
}
