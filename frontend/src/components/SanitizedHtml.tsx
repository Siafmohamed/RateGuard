import DOMPurify from 'dompurify'
import { useMemo } from 'react'

export interface SanitizedHtmlProps {
  /** Already sanitised server-side; DOMPurify is defence in depth. */
  html: string
  className?: string
}

/**
 * The ONE place the app uses `dangerouslySetInnerHTML`, and only for the
 * `content_html` field returned by the content gateway. The markup is sanitised
 * on the server (App\Domain\Content\Support\ContentSanitizer) and again here, so
 * a compromise of the CMS still cannot inject script into the SPA.
 */
export function SanitizedHtml({ html, className }: SanitizedHtmlProps) {
  const clean = useMemo(() => DOMPurify.sanitize(html), [html])

  return <div className={className} dangerouslySetInnerHTML={{ __html: clean }} />
}
