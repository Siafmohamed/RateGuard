import { describe, expect, it } from 'vitest'

import { formatCents } from './money'

/*
 * Money is transported as integer cents and must never be turned into a float.
 * These cases pin the exact strings so a "clever" refactor to `cents / 100`
 * cannot slip through.
 */
describe('formatCents', () => {
  it('formats zero', () => {
    expect(formatCents(0)).toBe('$0.00')
  })

  it('formats a sub-cent value as minor units', () => {
    expect(formatCents(5)).toBe('$0.05')
  })

  it('pads the minor units', () => {
    expect(formatCents(99)).toBe('$0.99')
  })

  it('formats exactly one major unit', () => {
    expect(formatCents(100)).toBe('$1.00')
  })

  it('groups thousands', () => {
    expect(formatCents(123456)).toBe('$1,234.56')
  })

  it('keeps precision on large amounts', () => {
    // Number.MAX_SAFE_INTEGER cents; a float-based formatter drifts on the
    // minor units at this magnitude.
    expect(formatCents(Number.MAX_SAFE_INTEGER)).toBe('$90,071,992,547,409.91')
  })

  it('prefixes negative values', () => {
    expect(formatCents(-123456)).toBe('-$1,234.56')
  })

  it('honours a different currency', () => {
    expect(formatCents(123456, { currency: 'EUR' })).toBe('€1,234.56')
  })

  it('never rounds a truncated value up', () => {
    expect(formatCents(199)).toBe('$1.99')
  })
})
