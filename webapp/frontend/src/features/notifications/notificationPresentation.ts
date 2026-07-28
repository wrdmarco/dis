import type { UserNotification } from '../../types/api';

const notificationTargetId = /^[A-Za-z0-9_-]{1,80}$/;

export interface NotificationCursor {
  occurredAt: number;
  id: string;
}

export function safeNotificationActionUrl(actionUrl: string): string | null {
  if (!actionUrl.startsWith('/') || actionUrl.startsWith('//')) {
    return null;
  }

  let url: URL;
  try {
    url = new URL(actionUrl, 'https://dis.invalid');
  } catch {
    return null;
  }

  if (url.origin !== 'https://dis.invalid' || url.hash !== '') {
    return null;
  }

  if (url.pathname === '/profile') {
    const section = url.searchParams.get('section');
    const assetId = url.searchParams.get('asset');
    const certificationId = url.searchParams.get('certification');
    const hasOnlyExpectedParameters = [...url.searchParams.keys()].every((key) => (
      section === 'assets'
        ? key === 'section' || key === 'asset'
        : key === 'section' || key === 'certification'
    ));

    if (
      section === 'assets'
      && assetId !== null
      && notificationTargetId.test(assetId)
      && certificationId === null
      && hasOnlyExpectedParameters
      && [...url.searchParams.keys()].length === 2
    ) {
      return `/profile?section=assets&asset=${encodeURIComponent(assetId)}`;
    }

    if (
      section === 'certifications'
      && certificationId !== null
      && notificationTargetId.test(certificationId)
      && assetId === null
      && hasOnlyExpectedParameters
      && [...url.searchParams.keys()].length === 2
    ) {
      return `/profile?section=certifications&certification=${encodeURIComponent(certificationId)}`;
    }

    return null;
  }

  if (url.pathname === '/verzoeken') {
    const requestId = url.searchParams.get('request');
    const keys = [...url.searchParams.keys()];

    if (
      url.searchParams.get('tab') === 'mine'
      && requestId !== null
      && notificationTargetId.test(requestId)
      && keys.length === 2
      && keys.every((key) => key === 'tab' || key === 'request')
    ) {
      return `/verzoeken?tab=mine&request=${encodeURIComponent(requestId)}`;
    }
  }

  return null;
}

export function newestNotificationCursor(
  notifications: readonly UserNotification[],
): NotificationCursor | null {
  return notifications.reduce<NotificationCursor | null>((newest, notification) => {
    const candidate = cursorForNotification(notification);

    return newest === null || compareNotificationCursors(candidate, newest) > 0
      ? candidate
      : newest;
  }, null);
}

export function containsNotificationAfterCursor(
  notifications: readonly UserNotification[],
  cursor: NotificationCursor | null,
): boolean {
  return cursor !== null && notifications.some(
    (notification) => compareNotificationCursors(cursorForNotification(notification), cursor) > 0,
  );
}

function cursorForNotification(notification: UserNotification): NotificationCursor {
  const parsed = Date.parse(notification.occurred_at);

  return {
    occurredAt: Number.isFinite(parsed) ? parsed : 0,
    id: notification.id,
  };
}

function compareNotificationCursors(left: NotificationCursor, right: NotificationCursor): number {
  if (left.occurredAt !== right.occurredAt) {
    return left.occurredAt - right.occurredAt;
  }

  return left.id.localeCompare(right.id);
}
