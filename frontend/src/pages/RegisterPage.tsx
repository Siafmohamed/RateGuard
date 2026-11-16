import { useState } from 'react'

import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { Link, Navigate, useNavigate } from 'react-router-dom'

import { Button } from '@/components/ui/Button'
import { useSession } from '@/hooks/useSession'
import { register as registerAccount, registerInputSchema, type RegisterInput } from '@/lib/api/auth'
import { isApiError } from '@/lib/api/errors'
import { applyApiFieldErrors } from '@/lib/api/formErrors'
import { queryKeys } from '@/lib/queryKeys'

export function RegisterPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const session = useSession()

  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<RegisterInput>({
    resolver: zodResolver(registerInputSchema),
    defaultValues: {
      name: '',
      email: '',
      password: '',
      password_confirmation: '',
      organization_name: '',
    },
  })

  const mutation = useMutation({
    mutationFn: (values: RegisterInput) => registerAccount(values),
    onSuccess: async () => {
      queryClient.clear()
      await queryClient.invalidateQueries({ queryKey: queryKeys.session })
      navigate('/app/invoices', { replace: true })
    },
    onError: (error) => {
      setFormError(null)

      if (!applyApiFieldErrors(setError, error) && isApiError(error)) {
        setFormError(error.message)
      }
    },
  })

  if (session.data) {
    return <Navigate to="/app/invoices" replace />
  }

  const onSubmit = handleSubmit((values) => mutation.mutate(values))

  const fields: Array<{
    name: keyof RegisterInput
    label: string
    type: string
    autoComplete?: string
  }> = [
    { name: 'name', label: 'Full name', type: 'text', autoComplete: 'name' },
    { name: 'email', label: 'Email', type: 'email', autoComplete: 'email' },
    { name: 'organization_name', label: 'Organization name', type: 'text' },
    { name: 'password', label: 'Password', type: 'password', autoComplete: 'new-password' },
    {
      name: 'password_confirmation',
      label: 'Confirm password',
      type: 'password',
      autoComplete: 'new-password',
    },
  ]

  return (
    <div className="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10">
      <h1 className="text-2xl font-semibold text-slate-900">Create your account</h1>
      <p className="mt-1 text-sm text-slate-500">
        Your organization is created with you as its owner.
      </p>

      <form className="mt-6 space-y-4" onSubmit={onSubmit} noValidate>
        {fields.map((field) => (
          <div key={field.name}>
            <label htmlFor={field.name} className="block text-sm font-medium text-slate-700">
              {field.label}
            </label>
            <input
              id={field.name}
              type={field.type}
              autoComplete={field.autoComplete}
              {...register(field.name)}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            />
            {errors[field.name] ? (
              <p role="alert" className="mt-1 text-xs text-red-600">
                {errors[field.name]?.message}
              </p>
            ) : null}
          </div>
        ))}

        {formError ? (
          <p role="alert" className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
            {formError}
          </p>
        ) : null}

        <Button type="submit" loading={mutation.isPending} className="w-full">
          Create account
        </Button>
      </form>

      <p className="mt-6 text-sm text-slate-500">
        Already registered?{' '}
        <Link to="/login" className="font-medium text-indigo-600 hover:text-indigo-500">
          Sign in
        </Link>
      </p>
    </div>
  )
}
