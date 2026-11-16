import { useEffect, useState } from 'react'

/**
 * Track the tab's visibility so polling can pause while the user is away.
 * React Query's `refetchInterval` is then gated on this value.
 */
export function useDocumentVisible(): boolean {
  const [visible, setVisible] = useState(() =>
    typeof document === 'undefined' ? true : !document.hidden,
  )

  useEffect(() => {
    const onChange = () => setVisible(!document.hidden)

    document.addEventListener('visibilitychange', onChange)

    return () => document.removeEventListener('visibilitychange', onChange)
  }, [])

  return visible
}
