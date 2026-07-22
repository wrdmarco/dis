import type { DispatchDeliveryStatus } from '../../types/api';

export function dispatchDeliveryNotice(state: DispatchDeliveryStatus['state']): string | null {
  switch (state) {
    case 'preparing_speech':
      return 'Alarm in wachtrij geplaatst — spraak wordt voorbereid (maximaal 10 seconden)';
    case 'queued_for_push':
      return 'Alarm in wachtrij geplaatst.';
    case 'sent':
    case 'partial':
      return 'Alarm verstuurd';
    case 'failed':
      return null;
  }
}
