import { create } from 'zustand'

export type ToastTone = 'error' | 'info' | 'success'

export interface Toast {
  id: number
  message: string
  tone: ToastTone
}

/*
 * Transient UI state: the toast queue (announced through an aria-live region)
 * and the global upgrade dialog opened by the 402 `plan_limit_reached` handler.
 * Nothing here is business state.
 */
interface UiState {
  toasts: Toast[]
  pushToast: (message: string, tone?: ToastTone) => void
  dismissToast: (id: number) => void
  upgradeOpen: boolean
  upgradeDetails: unknown
  openUpgrade: (details?: unknown) => void
  closeUpgrade: () => void
}

let nextToastId = 1

export const useUiStore = create<UiState>((set) => ({
  toasts: [],

  pushToast: (message, tone = 'error') => {
    const toast: Toast = { id: nextToastId++, message, tone }

    set((state) => ({ toasts: [...state.toasts, toast] }))
  },

  dismissToast: (id) => {
    set((state) => ({ toasts: state.toasts.filter((toast) => toast.id !== id) }))
  },

  upgradeOpen: false,
  upgradeDetails: null,

  openUpgrade: (details = null) => {
    set({ upgradeOpen: true, upgradeDetails: details })
  },

  closeUpgrade: () => {
    set({ upgradeOpen: false, upgradeDetails: null })
  },
}))
