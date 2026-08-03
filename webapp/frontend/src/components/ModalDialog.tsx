import { X } from 'lucide-react';
import { type ReactNode, useEffect, useId, useRef } from 'react';

interface ModalDialogProps {
  children: ReactNode;
  className?: string;
  closeDisabled?: boolean;
  description?: ReactNode;
  eyebrow: string;
  narrow?: boolean;
  onClose: () => void;
  role?: 'dialog' | 'alertdialog';
  title: string;
}

export function ModalDialog({
  children,
  className,
  closeDisabled = false,
  description,
  eyebrow,
  narrow = false,
  onClose,
  role = 'dialog',
  title,
}: ModalDialogProps) {
  const dialogRef = useRef<HTMLElement>(null);
  const onCloseRef = useRef(onClose);
  const closeDisabledRef = useRef(closeDisabled);
  const titleId = `modal-${useId().replaceAll(':', '')}`;
  const descriptionId = description === undefined ? undefined : `${titleId}-description`;

  useEffect(() => {
    onCloseRef.current = onClose;
    closeDisabledRef.current = closeDisabled;
  }, [closeDisabled, onClose]);

  useEffect(() => {
    const previouslyFocused = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    const focusFrame = window.requestAnimationFrame(() => {
      const dialog = dialogRef.current;
      const initialTarget = dialog?.querySelector<HTMLElement>('[data-dialog-initial="true"]:not([disabled])')
        ?? dialog?.querySelector<HTMLElement>('[data-dialog-close="true"]:not([disabled])')
        ?? dialog;
      initialTarget?.focus();
    });

    function handleKeyDown(event: KeyboardEvent) {
      const dialog = dialogRef.current;
      if (dialog === null) {
        return;
      }

      if (event.key === 'Escape') {
        if (!closeDisabledRef.current) {
          event.preventDefault();
          onCloseRef.current();
        }
        return;
      }

      if (event.key !== 'Tab') {
        return;
      }

      const focusable = Array.from(dialog.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
      )).filter((element) => element.tabIndex >= 0);
      const first = focusable[0];
      const last = focusable.at(-1);

      if (first === undefined || last === undefined) {
        event.preventDefault();
        dialog.focus();
      } else if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) {
        event.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', handleKeyDown);

    return () => {
      window.cancelAnimationFrame(focusFrame);
      document.removeEventListener('keydown', handleKeyDown);
      document.body.style.overflow = previousOverflow;
      if (previouslyFocused?.isConnected) {
        previouslyFocused.focus();
      }
    };
  }, []);

  return (
    <div
      className="modal-backdrop"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget && !closeDisabled) {
          onClose();
        }
      }}
    >
      <section
        ref={dialogRef}
        className={[
          'modal',
          narrow ? 'modal--narrow' : null,
          className,
        ].filter(Boolean).join(' ')}
        role={role}
        tabIndex={-1}
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={descriptionId}
      >
        <header className="modal__header">
          <div>
            <span className="modal__eyebrow">{eyebrow}</span>
            <h2 id={titleId}>{title}</h2>
          </div>
          <button
            className="icon-button"
            type="button"
            onClick={onClose}
            disabled={closeDisabled}
            aria-label="Sluiten"
            data-dialog-close="true"
          >
            <X aria-hidden size={18} />
          </button>
        </header>
        {description !== undefined ? (
          <p className="modal__description" id={descriptionId}>{description}</p>
        ) : null}
        {children}
      </section>
    </div>
  );
}
