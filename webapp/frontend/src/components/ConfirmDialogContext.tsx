'use client';

import { CircleHelp, ShieldAlert, TriangleAlert } from 'lucide-react';
import { usePathname } from 'next/navigation';
import {
  createContext,
  type ReactNode,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
} from 'react';
import { ModalDialog } from './ModalDialog';

export type ConfirmDialogIntent = 'default' | 'warning' | 'danger';

export interface ConfirmDialogOptions {
  cancelLabel?: string;
  confirmLabel?: string;
  eyebrow?: string;
  intent?: ConfirmDialogIntent;
  message: string;
  title: string;
}

type ConfirmDialogRequest = (options: ConfirmDialogOptions) => Promise<boolean>;

interface PendingConfirmation {
  options: ConfirmDialogOptions;
  resolve: (confirmed: boolean) => void;
}

const ConfirmDialogContext = createContext<ConfirmDialogRequest | null>(null);

export function ConfirmDialogProvider({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const [pending, setPending] = useState<PendingConfirmation | null>(null);
  const pendingRef = useRef<PendingConfirmation | null>(null);
  const pathnameRef = useRef(pathname);

  const settle = useCallback((confirmed: boolean) => {
    const current = pendingRef.current;
    if (current === null) {
      return;
    }

    pendingRef.current = null;
    setPending(null);
    current.resolve(confirmed);
  }, []);

  const requestConfirmation = useCallback<ConfirmDialogRequest>((options) => new Promise((resolve) => {
    pendingRef.current?.resolve(false);
    const request = { options, resolve };
    pendingRef.current = request;
    setPending(request);
  }), []);

  useEffect(() => () => {
    pendingRef.current?.resolve(false);
    pendingRef.current = null;
  }, []);

  useEffect(() => {
    if (pathnameRef.current !== pathname) {
      pathnameRef.current = pathname;
      settle(false);
    }
  }, [pathname, settle]);

  useEffect(() => {
    const cancelOnHistoryNavigation = () => settle(false);
    window.addEventListener('popstate', cancelOnHistoryNavigation);
    return () => window.removeEventListener('popstate', cancelOnHistoryNavigation);
  }, [settle]);

  return (
    <ConfirmDialogContext.Provider value={requestConfirmation}>
      {children}
      {pending !== null ? (
        <ConfirmationDialog
          options={pending.options}
          onCancel={() => settle(false)}
          onConfirm={() => settle(true)}
        />
      ) : null}
    </ConfirmDialogContext.Provider>
  );
}

export function useConfirmDialog(): ConfirmDialogRequest {
  const context = useContext(ConfirmDialogContext);
  if (context === null) {
    throw new Error('useConfirmDialog must be used inside ConfirmDialogProvider');
  }

  return context;
}

function ConfirmationDialog({
  onCancel,
  onConfirm,
  options,
}: {
  onCancel: () => void;
  onConfirm: () => void;
  options: ConfirmDialogOptions;
}) {
  const intent = options.intent ?? 'warning';
  const signal = confirmationSignal(intent);
  const SignalIcon = signal.icon;

  return (
    <ModalDialog
      className={`modal--confirmation modal--confirmation-${intent}`}
      description={(
        <span className="confirmation-dialog__copy">
          <span className="confirmation-dialog__signal">
            <SignalIcon aria-hidden="true" size={18} strokeWidth={2} />
            <span>{signal.label}</span>
          </span>
          <span className="confirmation-dialog__message">{options.message}</span>
        </span>
      )}
      eyebrow={options.eyebrow ?? 'Bevestiging'}
      narrow
      onClose={onCancel}
      role="alertdialog"
      title={options.title}
    >
      <footer className="confirmation-dialog__actions">
        <button
          className="secondary-button"
          type="button"
          onClick={onCancel}
          data-dialog-initial="true"
        >
          {options.cancelLabel ?? 'Annuleren'}
        </button>
        <button
          className={intent === 'danger' ? 'danger-button' : 'primary-button'}
          type="button"
          onClick={onConfirm}
        >
          {options.confirmLabel ?? 'Doorgaan'}
        </button>
      </footer>
    </ModalDialog>
  );
}

function confirmationSignal(intent: ConfirmDialogIntent): {
  icon: typeof CircleHelp;
  label: string;
} {
  switch (intent) {
    case 'danger':
      return { icon: ShieldAlert, label: 'Onomkeerbare actie' };
    case 'warning':
      return { icon: TriangleAlert, label: 'Controleer deze keuze' };
    default:
      return { icon: CircleHelp, label: 'Actie bevestigen' };
  }
}
