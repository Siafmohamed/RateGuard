import { z } from 'zod'

import { api } from './client'
import { authSessionSchema, type AuthSession } from './schemas'
import { parseResponse } from './validate'

const emailField = z
  .string()
  .min(1, 'Email is required.')
  .refine((value) => z.email().safeParse(value).success, 'Enter a valid email address.')

export const loginInputSchema = z.object({
  email: emailField,
  password: z.string().min(1, 'Password is required.'),
})
export type LoginInput = z.infer<typeof loginInputSchema>

export const registerInputSchema = z
  .object({
    name: z.string().min(1, 'Name is required.').max(255),
    email: emailField,
    password: z.string().min(8, 'Use at least 8 characters.'),
    password_confirmation: z.string().min(1, 'Confirm your password.'),
    organization_name: z.string().min(1, 'Organization name is required.').max(255),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Passwords do not match.',
  })
export type RegisterInput = z.infer<typeof registerInputSchema>

/** Bootstrap call: who am I? A 401 here must NOT trigger the global redirect. */
export async function fetchSession(signal?: AbortSignal): Promise<AuthSession> {
  const { data } = await api.get('/auth/me', { signal })

  return parseResponse(authSessionSchema, data, 'GET /auth/me')
}

export async function login(input: LoginInput, signal?: AbortSignal): Promise<AuthSession> {
  const { data } = await api.post('/auth/login', input, { signal })

  return parseResponse(authSessionSchema, data, 'POST /auth/login')
}

export async function register(input: RegisterInput, signal?: AbortSignal): Promise<AuthSession> {
  const { data } = await api.post('/auth/register', input, { signal })

  return parseResponse(authSessionSchema, data, 'POST /auth/register')
}

export async function logout(): Promise<void> {
  await api.post('/auth/logout')
}
