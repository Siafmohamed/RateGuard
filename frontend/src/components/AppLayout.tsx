import { useEffect } from 'react'

import { useQueryClient } from '@tanstack/react-query'
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom'

import { OrgSwitcher } from '@/components/OrgSwitcher'
import { useActiveOrganization } from '@/hooks/useActiveOrganization'
import { logout } from '@/lib/api/auth'
import { resetApiState } from '@/lib/api/client'
import { useActiveOrganizationId, useActiveOrganizationStore } from '@/stores/activeOrganization'

const NAV_LINKS = [
  { to: '/app/invoices', label: 'Invoices' },
  { to: '/app/billing', label: 'Billing' },
]

/**
 * The guarded application shell: header, organization switcher and the routed
 * screen. It also keeps the active organization id in sync - on first load (or
 * after a 403 recovery reset it) the first membership becomes active.
 */
export function AppLayout() {
  const { organizations } = useActiveOrganization()
  const activeId = useActiveOrganizationId()
  const setOrganizationId = useActiveOrganizationStore((state) => state.setOrganizationId)
  const reset = useActiveOrganizationStore((state) => state.reset)
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  useEffect(() => {
    if (organizations.length === 0) {
      return
    }

    const stillValid = activeId !== null && organizations.some((org) => org.id === activeId)

    if (!stillValid) {
      setOrganizationId(organizations[0].id)
    }
  }, [organizations, activeId, setOrganizationId])

  async function handleLogout() {
    try {
      await logout()
    } catch {
      // A failed logout still drops local state; the cookie will expire anyway.
    }

    resetApiState()
    reset()
    queryClient.clear()
    navigate('/login', { replace: true })
  }

  return (
    <div className="min-h-screen">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
          <div className="flex items-center gap-5">
            <Link to="/app/invoices" className="text-base font-semibold text-slate-900">
              RateGuard
            </Link>
            <nav className="flex items-center gap-1 text-sm">
              {NAV_LINKS.map((link) => (
                <NavLink
                  key={link.to}
                  to={link.to}
                  className={({ isActive }) =>
                    `rounded-md px-3 py-1.5 ${
                      isActive ? 'bg-slate-100 font-medium text-slate-900' : 'text-slate-600'
                    }`
                  }
                >
                  {link.label}
                </NavLink>
              ))}
            </nav>
          </div>

          <div className="flex items-center gap-3">
            <OrgSwitcher
              organizations={organizations}
              value={activeId}
              onChange={setOrganizationId}
            />
            <button
              type="button"
              onClick={handleLogout}
              className="text-sm text-slate-500 hover:text-slate-800"
            >
              Sign out
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-6xl px-4 py-6">
        <Outlet />
      </main>
    </div>
  )
}
