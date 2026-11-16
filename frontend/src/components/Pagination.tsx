import { Button } from '@/components/ui/Button'

export interface PaginationProps {
  page: number
  lastPage: number
  onPageChange: (page: number) => void
}

export function Pagination({ page, lastPage, onPageChange }: PaginationProps) {
  if (lastPage <= 1) {
    return null
  }

  return (
    <nav
      aria-label="Pagination"
      className="flex items-center justify-between gap-4 border-t border-slate-200 px-4 py-3"
    >
      <Button
        variant="secondary"
        disabled={page <= 1}
        onClick={() => onPageChange(page - 1)}
        aria-label="Previous page"
      >
        Previous
      </Button>
      <span className="text-sm text-slate-600">
        Page {page} of {lastPage}
      </span>
      <Button
        variant="secondary"
        disabled={page >= lastPage}
        onClick={() => onPageChange(page + 1)}
        aria-label="Next page"
      >
        Next
      </Button>
    </nav>
  )
}
