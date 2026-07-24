import { expect, test } from '@playwright/test';
import { dispatchDeliveryNotice } from '../src/features/incidents/dispatchDeliveryPresentation';

test('toont de afgesproken centrale meldingen tijdens en na de pushwachtrij', () => {
  expect(dispatchDeliveryNotice('queued_for_push')).toBe('Alarm in wachtrij geplaatst.');
  expect(dispatchDeliveryNotice('sent')).toBe('Alarm verstuurd');
  expect(dispatchDeliveryNotice('partial')).toBe('Alarm verstuurd');
  expect(dispatchDeliveryNotice('failed')).toBeNull();
});
