import { useState, type ChangeEvent } from 'react'

import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'

import { Button } from '@/components/ui/Button'
import { listCarriers } from '@/lib/api/carriers'
import { isApiError } from '@/lib/api/errors'
import { applyApiFieldErrors } from '@/lib/api/formErrors'
import {
  ACCEPTED_UPLOAD_TYPES,
  uploadInvoice,
  uploadInvoiceInputSchema,
  validateInvoiceFile,
  type UploadInvoiceInput,
} from '@/lib/api/invoices'
import { queryKeys } from '@/lib/queryKeys'
import { useUiStore } from '@/stores/ui'

export interface UploadInvoiceDialogProps {
  open: boolean
  onClose: () => void
}

const EMPTY_VALUES: UploadInvoiceInput = { carrier: '', invoice_number: '', invoice_date: '' }

/**
 * Upload flow: pick a carrier (code + name), fill the invoice header and attach
 * a csv/txt file (checked against the 5 MB / type rules client-side, mirroring
 * the server). A 202 response navigates straight to the new invoice.
 */
export function UploadInvoiceDialog({ open, onClose }: UploadInvoiceDialogProps) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const pushToast = useUiStore((state) => state.pushToast)

  const [file, setFile] = useState<File | null>(null)
  const [fileError, setFileError] = useState<string | null>(null)
  const [progress, setProgress] = useState(0)

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<UploadInvoiceInput>({
    resolver: zodResolver(uploadInvoiceInputSchema),
    defaultValues: EMPTY_VALUES,
  })

  const { data: carriers = [] } = useQuery({
    queryKey: queryKeys.carriers,
    queryFn: ({ signal }) => listCarriers(signal),
    enabled: open,
  })

  const mutation = useMutation({
    mutationFn: ({ nextFile, values }: { nextFile: File; values: UploadInvoiceInput }) =>
      uploadInvoice({ ...values, file: nextFile }, { onProgress: setProgress }),

    onSuccess: (invoice) => {
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
      handleClose()
      navigate(`/app/invoices/${invoice.id}`)
    },

    onError: (error) => {
      if (!isApiError(error)) {
        return
      }

      // 413: the server rejected the size outright.
      if (error.status === 413) {
        setFileError('That file is larger than 5 MB.')

        return
      }

      if (applyApiFieldErrors(setError, error)) {
        return
      }

      // 402 (upgrade dialog) and 429 (rate limit) are surfaced globally by the
      // interceptors, so there is nothing extra to show here.
      if (error.status === 402 || error.status === 429) {
        return
      }

      pushToast(error.message)
    },
  })

  function handleClose() {
    reset(EMPTY_VALUES)
    setFile(null)
    setFileError(null)
    setProgress(0)
    onClose()
  }

  function handleFileChange(event: ChangeEvent<HTMLInputElement>) {
    const selected = event.target.files?.[0] ?? null

    setFile(selected)
    setFileError(selected ? validateInvoiceFile(selected) : null)
  }

  const onSubmit = handleSubmit((values) => {
    if (!file) {
      setFileError('Choose a file to upload.')

      return
    }

    const issue = validateInvoiceFile(file)

    if (issue) {
      setFileError(issue)

      return
    }

    setProgress(0)
    mutation.mutate({ nextFile: file, values })
  })

  if (!open) {
    return null
  }

  const busy = mutation.isPending

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="upload-dialog-title"
      className="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/40 p-4"
    >
      <div className="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        <h2 id="upload-dialog-title" className="text-lg font-semibold text-slate-900">
          Upload invoice
        </h2>

        <form className="mt-4 space-y-4" onSubmit={onSubmit} noValidate>
          <div>
            <label htmlFor="carrier" className="block text-sm font-medium text-slate-700">
              Carrier
            </label>
            <select
              id="carrier"
              {...register('carrier')}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            >
              <option value="">Select a carrier…</option>
              {carriers.map((carrier) => (
                <option key={carrier.id} value={carrier.code}>
                  {carrier.code} — {carrier.name}
                </option>
              ))}
            </select>
            {errors.carrier ? (
              <p role="alert" className="mt-1 text-xs text-red-600">
                {errors.carrier.message}
              </p>
            ) : null}
          </div>

          <div>
            <label htmlFor="invoice_number" className="block text-sm font-medium text-slate-700">
              Invoice number
            </label>
            <input
              id="invoice_number"
              type="text"
              {...register('invoice_number')}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            />
            {errors.invoice_number ? (
              <p role="alert" className="mt-1 text-xs text-red-600">
                {errors.invoice_number.message}
              </p>
            ) : null}
          </div>

          <div>
            <label htmlFor="invoice_date" className="block text-sm font-medium text-slate-700">
              Invoice date
            </label>
            <input
              id="invoice_date"
              type="date"
              {...register('invoice_date')}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            />
            {errors.invoice_date ? (
              <p role="alert" className="mt-1 text-xs text-red-600">
                {errors.invoice_date.message}
              </p>
            ) : null}
          </div>

          <div>
            <label htmlFor="file" className="block text-sm font-medium text-slate-700">
              Invoice file (csv or txt, max 5 MB)
            </label>
            <input
              id="file"
              type="file"
              accept={ACCEPTED_UPLOAD_TYPES}
              onChange={handleFileChange}
              className="mt-1 block w-full text-sm text-slate-600"
            />
            {fileError ? (
              <p role="alert" className="mt-1 text-xs text-red-600">
                {fileError}
              </p>
            ) : null}
          </div>

          {busy || progress > 0 ? (
            <div
              role="progressbar"
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={progress}
              aria-label="Upload progress"
              className="h-2 w-full overflow-hidden rounded bg-slate-200"
            >
              <div
                className="h-full bg-indigo-600 transition-all"
                style={{ width: `${progress}%` }}
              />
            </div>
          ) : null}

          <div className="flex justify-end gap-2 pt-2">
            <Button variant="secondary" onClick={handleClose} disabled={busy}>
              Cancel
            </Button>
            <Button type="submit" loading={busy}>
              Upload
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}
