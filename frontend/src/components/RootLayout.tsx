import { Outlet } from 'react-router-dom'

import { ToastRegion } from '@/components/ToastRegion'
import { UpgradeDialog } from '@/components/UpgradeDialog'

/**
 * The two global overlays live inside the router so the upgrade dialog can
 * navigate (it links to /app/billing).
 */
export function RootLayout() {
  return (
    <>
      <Outlet />
      <ToastRegion />
      <UpgradeDialog />
    </>
  )
}
