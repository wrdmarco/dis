'use client';

import {
  BadgeCheck,
  Bell,
  ChevronRight,
  LoaderCircle,
  MessageSquareText,
  Wrench,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import { formatDateTime } from '../../lib/dateTime';
import type { UserNotification, UserNotificationType } from '../../types/api';
import { useNotifications } from './NotificationsContext';

const notificationPopoverId = 'personal-notifications-popover';
const notificationHeadingId = 'personal-notifications-heading';

export function NotificationCenter({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const {
    notifications,
    unreadCount,
    loading,
    loadError,
    actionError,
    openingId,
    markingAllRead,
    loadingMore,
    loadMoreError,
    hasMore,
    isRinging,
    ringSequence,
    refresh,
    loadMore,
    openNotification,
    markAllRead,
  } = useNotifications();
  const rootRef = useRef<HTMLDivElement | null>(null);
  const triggerRef = useRef<HTMLButtonElement | null>(null);

  useEffect(() => {
    if (!open) {
      return undefined;
    }

    void refresh();

    const closeOnOutsideClick = (event: PointerEvent) => {
      if (rootRef.current !== null && !rootRef.current.contains(event.target as Node)) {
        onOpenChange(false);
      }
    };
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onOpenChange(false);
        triggerRef.current?.focus();
      }
    };

    window.addEventListener('pointerdown', closeOnOutsideClick);
    window.addEventListener('keydown', closeOnEscape);

    return () => {
      window.removeEventListener('pointerdown', closeOnOutsideClick);
      window.removeEventListener('keydown', closeOnEscape);
    };
  }, [onOpenChange, open, refresh]);

  const notificationLabel = unreadCount === 0
    ? 'Meldingen openen, geen ongelezen meldingen'
    : `Meldingen openen, ${unreadCount} ongelezen`;

  return (
    <div className="notification-center" ref={rootRef}>
      <button
        ref={triggerRef}
        className={`notification-center__trigger ${open ? 'notification-center__trigger--open' : ''}`}
        type="button"
        onClick={() => onOpenChange(!open)}
        aria-label={notificationLabel}
        aria-expanded={open}
        aria-controls={notificationPopoverId}
      >
        <span
          key={ringSequence}
          className={isRinging ? 'notification-center__bell notification-center__bell--ringing' : 'notification-center__bell'}
          aria-hidden
        >
          <Bell size={20} />
        </span>
        {unreadCount > 0 ? (
          <span className="notification-center__badge" aria-hidden>
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        ) : null}
      </button>
      <span className="sr-only" role="status" aria-live="polite">
        {isRinging ? 'Nieuwe persoonlijke melding ontvangen.' : ''}
      </span>

      {open ? (
        <section
          className="notification-center__popover"
          id={notificationPopoverId}
          role="region"
          aria-labelledby={notificationHeadingId}
        >
          <header className="notification-center__header">
            <div>
              <h2 id={notificationHeadingId}>Meldingen</h2>
              <p>{unreadCount === 0 ? 'Alles is gelezen' : `${unreadCount} ongelezen`}</p>
            </div>
            {unreadCount > 0 ? (
              <button
                className="notification-center__mark-all"
                type="button"
                disabled={markingAllRead || openingId !== null}
                onClick={() => void markAllRead()}
              >
                {markingAllRead ? 'Bezig…' : 'Alles gelezen'}
              </button>
            ) : null}
          </header>

          {loadError ? (
            <div className="notification-center__state notification-center__state--error" role="status">
              <p>Meldingen konden niet worden geladen.</p>
              <button type="button" onClick={() => void refresh()}>Opnieuw proberen</button>
            </div>
          ) : null}
          {actionError ? <p className="notification-center__action-error" role="alert">{actionError}</p> : null}

          {!loadError && loading && notifications.length === 0 ? (
            <div className="notification-center__state" role="status">
              <LoaderCircle className="notification-center__loader" aria-hidden size={20} />
              <span>Meldingen laden…</span>
            </div>
          ) : null}

          {!loadError && !loading && notifications.length === 0 ? (
            <div className="notification-center__empty">
              <span className="notification-center__empty-icon" aria-hidden><Bell size={21} /></span>
              <strong>Geen ongelezen meldingen</strong>
              <p>Nieuwe updates over jouw eigen gegevens verschijnen hier.</p>
            </div>
          ) : null}

          {notifications.length > 0 ? (
            <ul className="notification-center__list" aria-label="Ongelezen meldingen">
              {notifications.map((notification) => (
                <NotificationItem
                  key={notification.id}
                  notification={notification}
                  busy={openingId === notification.id}
                  disabled={openingId !== null || markingAllRead}
                  onOpen={async () => {
                    if (await openNotification(notification)) {
                      onOpenChange(false);
                    }
                  }}
                />
              ))}
            </ul>
          ) : null}

          {notifications.length > 0 && hasMore ? (
            <footer className="notification-center__load-more">
              <button
                type="button"
                disabled={loadingMore || openingId !== null || markingAllRead}
                onClick={() => void loadMore()}
              >
                {loadingMore ? (
                  <>
                    <LoaderCircle className="notification-center__loader" aria-hidden size={16} />
                    Oudere meldingen laden…
                  </>
                ) : `Oudere meldingen laden (${notifications.length} van ${unreadCount})`}
              </button>
              {loadMoreError ? <p role="alert">{loadMoreError}</p> : null}
            </footer>
          ) : null}
        </section>
      ) : null}
    </div>
  );
}

function NotificationItem({
  notification,
  busy,
  disabled,
  onOpen,
}: {
  notification: UserNotification;
  busy: boolean;
  disabled: boolean;
  onOpen: () => Promise<void>;
}) {
  const Icon = iconForNotificationType(notification.type);

  return (
    <li className="notification-center__item" data-tone={notification.tone}>
      <button type="button" disabled={disabled} onClick={() => void onOpen()}>
        <span className="notification-center__item-icon" aria-hidden>
          {busy ? <LoaderCircle className="notification-center__loader" size={18} /> : <Icon size={18} />}
        </span>
        <span className="notification-center__item-copy">
          <strong>{notification.title}</strong>
          <span>{notification.message}</span>
          <time dateTime={notification.occurred_at}>{formatDateTime(notification.occurred_at)}</time>
        </span>
        <ChevronRight className="notification-center__item-chevron" aria-hidden size={17} />
      </button>
    </li>
  );
}

function iconForNotificationType(type: UserNotificationType) {
  switch (type) {
    case 'certification_expiring':
    case 'certification_expired':
      return BadgeCheck;
    case 'asset_maintenance_due':
    case 'asset_maintenance_overdue':
      return Wrench;
    case 'product_request_status':
      return MessageSquareText;
  }
}
