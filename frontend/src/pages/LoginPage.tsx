import { useState } from 'react'

import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'

import { Button } from '@/components/ui/Button'
import { useSession } from '@/hooks/useSession'
import { login, loginInputSchema, type LoginInput } from '@/lib/api/auth'
import { isApiError } from '@/lib/api/errors'
import { applyApiFieldErrors } from '@/lib/api/formErrors'
import { queryKeys } from '@/lib/queryKeys'

interface LocationState {
  from?: string
}

export function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const session = useSession()

  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<LoginInput>({
    resolver: zodResolver(loginInputSchema),
    defaultValues: { email: '', password: '' },
  })

  const from = (location.state as LocationState | null)?.from ?? '/app/invoices'

  const mutation = useMutation({
    mutationFn: (values: LoginInput) => login(values),
    onSuccess: async () => {
      setFormError(null)
      queryClient.clear()
      await queryClient.invalidateQueries({ queryKey: queryKeys.session })
      navigate(from, { replace: true })
    },
    onError: (error) => {
      setFormError(null)

      // A 401/422 on the auth endpoints surfaces as field errors, never as a
      // redirect (the interceptor exempts /auth/login).
      if (!applyApiFieldErrors(setError, error) && isApiError(error)) {
        setFormError(error.message)
      }
    },
  })

  if (session.data) {
    return <Navigate to={from} replace />
  }

  const onSubmit = handleSubmit((values) => mutation.mutate(values))

  return (
    <div className="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4">
      <h1 className="text-2xl font-semibold text-slate-900">Sign in to RateGuard</h1>
      <p className="mt-1 text-sm text-slate-500">Audit carrier invoices and recover overcharges.</p>

      <form className="mt-6 space-y-4" onSubmit={onSubmit} noValidate>
        <div>
          <label htmlFor="email" className="block text-sm font-medium text-slate-700">
            Email
          </label>
          <input
            id="email"
            type="email"
            autoComplete="email"
            {...register('email')}
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          />
          {errors.email ? (
            <p role="alert" className="mt-1 text-xs text-red-600">
              {errors.email.message}
            </p>
          ) : null}
        </div>

        <div>
          <label htmlFor="password" className="block text-sm font-medium text-slate-700">
            Password
          </label>
          <input
            id="password"
            type="password"
            autoComplete="current-password"
            {...register('password')}
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          />
          {errors.password ? (
            <p role="alert" className="mt-1 text-xs text-red-600">
              {errors.password.message}
            </p>
          ) : null}
        </div>

        {formError ? (
          <p role="alert" className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
            {formError}
          </p>
        ) : null}

        <Button type="submit" loading={mutation.isPending} className="w-full">
          Sign in
        </Button>
      </form>

      <p className="mt-6 text-sm text-slate-500">
        No account?{' '}
        <Link to="/register" className="font-medium text-indigo-600 hover:text-indigo-500">
          Create one
        </Link>
      </p>
    </div>
  )
}
