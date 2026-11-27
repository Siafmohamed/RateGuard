import { Navigate, createBrowserRouter } from 'react-router-dom'

import { AppLayout } from '@/components/AppLayout'
import { ProtectedRoute } from '@/components/ProtectedRoute'
import { RootLayout } from '@/components/RootLayout'
import { ArticlePage } from '@/features/articles/ArticlePage'
import { ArticlesPage } from '@/features/articles/ArticlesPage'
import { BillingPage } from '@/pages/BillingPage'
import { BlogListPage } from '@/pages/BlogListPage'
import { BlogPostPage } from '@/pages/BlogPostPage'
import { DocPage } from '@/pages/DocPage'
import { InvoiceDetailPage } from '@/pages/InvoiceDetailPage'
import { InvoicesListPage } from '@/pages/InvoicesListPage'
import { LoginPage } from '@/pages/LoginPage'
import { NotFoundPage } from '@/pages/NotFoundPage'
import { RegisterPage } from '@/pages/RegisterPage'
import { RouteErrorPage } from '@/pages/RouteErrorPage'

export const router = createBrowserRouter([
  {
    path: '/',
    element: <RootLayout />,
    errorElement: <RouteErrorPage />,
    children: [
      { index: true, element: <Navigate to="/app/invoices" replace /> },

      { path: 'login', element: <LoginPage /> },
      { path: 'register', element: <RegisterPage /> },

      // Public content routes: these are the ones the Phase 5 shell renders.
      { path: 'blog', element: <BlogListPage /> },
      { path: 'blog/:slug', element: <BlogPostPage /> },
      { path: 'docs/:slug', element: <DocPage /> },

      // Public articles, read through the Laravel content gateway
      // (see features/articles). Addressed by slug; Arabic slugs are
      // percent-encoded so the link encodes them.
      { path: 'articles', element: <ArticlesPage /> },
      { path: 'articles/:slug', element: <ArticlePage /> },

      {
        path: 'app',
        element: <ProtectedRoute />,
        children: [
          {
            element: <AppLayout />,
            children: [
              { index: true, element: <Navigate to="/app/invoices" replace /> },
              { path: 'invoices', element: <InvoicesListPage /> },
              { path: 'invoices/:id', element: <InvoiceDetailPage /> },
              { path: 'billing', element: <BillingPage /> },
            ],
          },
        ],
      },

      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
