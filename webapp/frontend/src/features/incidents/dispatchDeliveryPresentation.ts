import type { DispatchDeliveryStatus } from '../../types/api';

export function dispatchDeliveryNotice(state: DispatchDeliveryStatus['state']): string | null {
  switch (state) {
    case 'queued_for_push':
      return 'Alarm in wachtrij geplaatst.';
    case 'sent':
    case 'partial':
      return 'Alarm verstuurd';
    case 'failed':
      return null;
  }
}
