/**
 * Money is transported as integer cents and is only ever *formatted* with
 * integer math: `Math.trunc(cents / 100)` for the major units and `cents % 100`
 * for the minor units. A value is never converted to a float, so display logic
 * cannot drift on large amounts. `Intl` is used only for the locale's grouping
 * separators, decimal separator and currency symbol.
 */

export interface MoneyFormatOptions {
  /** ISO-4217 code, e.g. `USD`. */
  currency?: string
  /** BCP-47 locale, e.g. `en-US`. */
  locale?: string
}

/**
 * Format integer cents as a locale-aware currency string.
 *
 * @example formatCents(123456) // "$1,234.56"
 * @example formatCents(5) // "$0.05"
 */
export function formatCents(cents: number, options: MoneyFormatOptions = {}): string {
  const { currency = 'USD', locale = 'en-US' } = options

  const value = Number.isFinite(cents) ? Math.trunc(cents) : 0
  const negative = value < 0
  const absolute = Math.abs(value)

  const major = Math.trunc(absolute / 100)
  const minor = absolute % 100

  const groupedMajor = new Intl.NumberFormat(locale, {
    useGrouping: true,
    maximumFractionDigits: 0,
  }).format(major)

  const paddedMinor = String(minor).padStart(2, '0')

  // Discover the currency symbol, the decimal separator and their placement from
  // `Intl` using a probe value, then substitute our integer-derived pieces. This
  // keeps the result locale-correct without ever handing a float to the
  // formatter.
  const parts = new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).formatToParts(0)

  const formatted = parts
    .map((part) => {
      switch (part.type) {
        case 'currency':
        case 'decimal':
        case 'literal':
          return part.value
        case 'integer':
          return groupedMajor
        case 'fraction':
          return paddedMinor
        default:
          // `group` is intentionally dropped: grouping already lives inside
          // `groupedMajor`.
          return ''
      }
    })
    .join('')

  return negative ? `-${formatted}` : formatted
}
