import { expect, test, type Page } from 'playwright/test';
import { defaultTestAlertScope, readTestAlertSummary, testAlertSuccessMessage, type TestAlertSummary } from '../src/features/test-alerts/testAlertContract';

const currentUser = {
  id: 'user-operator-coordinator',
  name: 'Test Coordinator',
  email: 'coordinator@example.test',
  account_status: 'active',
  push_enabled: true,
  max_operator_devices: 3,
  two_factor_enabled: true,
  profile_completion_required: false,
  roles: [{
    id: 'role-coordinator',
    name: 'coordinator',
    display_name: 'Coördinator',
    can_use_operator_app: true,
    can_use_admin_app: true,
    permissions: [{
      id: 'permission-test-alert',
      name: 'incidents.dispatch.manage',
      category: 'incidents',
      display_name: 'Proefalarmering beheren',
    }],
  }],
};

const schedule = {
  enabled: false,
  day_of_week: 1,
  time: '09:00',
  message: 'Dit is het wekelijkse proefalarm.',
  last_run_at: null,
};

test.describe('handmatige proefalarmering', () => {
  test('defaults safely to self and confirms an all-online reachability test before one submission', async ({ page }) => {
    const submittedScopes: unknown[] = [];
    await mockTestAlertApi(page, async (scope) => {
      submittedScopes.push(scope);
      await new Promise((resolve) => setTimeout(resolve, 75));

      return sendResult(scope === 'all_online' ? {
        scope: 'all_online',
        recipient_count: 4,
        queued_token_count: 6,
        skipped_user_count: 2,
        failed_user_count: 1,
      } : {
        scope: 'self',
        recipient_count: 1,
        queued_token_count: 1,
        skipped_user_count: 0,
        failed_user_count: 0,
      });
    });

    await page.goto('/test-alert');

    const selfOption = page.getByRole('radio', { name: /alleen mijzelf/i });
    const allOnlineOption = page.getByRole('radio', { name: /alle online operator-apps/i });
    await expect(selfOption).toBeChecked();
    await expect(allOnlineOption).not.toBeChecked();
    await expect(page.getByText(/beschikbaarheid, certificaten en drones tellen hierbij niet mee/i)).toBeVisible();

    await page.getByRole('button', { name: 'Persoonlijke proefmelding versturen' }).click();
    await expect.poll(() => submittedScopes).toEqual(['self']);
    await expect(page.getByRole('status').filter({ hasText: 'Persoonlijke proefmelding klaargezet' })).toContainText('Persoonlijke proefmelding klaargezet voor je actieve gekoppelde app.');

    await allOnlineOption.check();
    await page.getByRole('button', { name: 'Bereikbaarheidstest starten' }).click();
    const dialog = page.getByRole('dialog', { name: 'Alle online operator-apps alarmeren?' });
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('Er wordt niet gefilterd op beschikbaarheid, certificeringen of toegewezen drones.');
    await expect(dialog.getByRole('button', { name: 'Annuleren' })).toBeFocused();
    expect(submittedScopes).toEqual(['self']);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    expect(submittedScopes).toEqual(['self']);

    await page.getByRole('button', { name: 'Bereikbaarheidstest starten' }).click();
    const confirmButton = dialog.getByRole('button', { name: 'Ja, bereikbaarheidstest starten' });
    await confirmButton.evaluate((button: HTMLButtonElement) => {
      button.click();
      button.click();
    });

    await expect.poll(() => submittedScopes).toEqual(['self', 'all_online']);
    await expect(dialog).toBeHidden();
    await expect(page.getByText('Klaargezet voor gebruikers').locator('..')).toContainText('4');
    await expect(page.getByText('Pushmeldingen in wachtrij').locator('..')).toContainText('6');
    await expect(page.getByText('Vooraf overgeslagen').locator('..')).toContainText('2');
    await expect(page.getByText('Niet klaargezet door fout').locator('..')).toContainText('1');
  });

  test('shows a server validation error without claiming the test was sent', async ({ page }) => {
    await mockTestAlertApi(page, async () => ({
      status: 422,
      body: {
        message: 'Geen actieve gekoppelde apps gevonden.',
        errors: { scope: ['Geen actieve gekoppelde apps gevonden.'] },
      },
    }));

    await page.goto('/test-alert');
    await page.getByRole('button', { name: 'Persoonlijke proefmelding versturen' }).click();

    await expect(page.getByRole('alert').filter({ hasText: 'Geen actieve gekoppelde apps gevonden.' })).toHaveText('Geen actieve gekoppelde apps gevonden.');
    await expect(page.getByLabel('Resultaat van de laatste proefalarmering')).toHaveCount(0);
  });

  test('shows a safe fallback when a successful response has no summary metadata', async ({ page }) => {
    await mockTestAlertApi(page, async () => ({ body: { data: dispatchPayload('self') } }));

    await page.goto('/test-alert');
    await page.getByRole('button', { name: 'Persoonlijke proefmelding versturen' }).click();

    await expect(page.getByRole('alert').filter({ hasText: 'gestart, maar het verzendresultaat kon niet worden gelezen' })).toContainText('gestart, maar het verzendresultaat kon niet worden gelezen');
    await expect(page.getByLabel('Resultaat van de laatste proefalarmering')).toHaveCount(0);
  });
});

test('test-alert contract keeps self as the safe default and distinguishes fan-out failures', () => {
  expect(defaultTestAlertScope).toBe('self');
  expect(testAlertSuccessMessage({
    scope: 'all_online',
    recipient_count: 3,
    queued_token_count: 3,
    skipped_user_count: 1,
    failed_user_count: 2,
  })).toContain('2 gebruikers konden niet worden klaargezet');
  expect(readTestAlertSummary(undefined)).toBeNull();
  expect(readTestAlertSummary({ scope: 'all_online', recipient_count: -1 })).toBeNull();
});

interface MockPostResponse {
  status?: number;
  body: unknown;
}

async function mockTestAlertApi(
  page: Page,
  onPost: (scope: unknown) => Promise<MockPostResponse>,
): Promise<void> {
  await page.context().addCookies([{ name: 'XSRF-TOKEN', value: 'test-csrf-token', url: 'http://127.0.0.1:3000' }]);
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;

    if (path === '/api/auth/me') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: currentUser }) });
      return;
    }
    if (path === '/api/branding') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { name: 'DIS', short_name: 'DIS', tenant_name: 'Testorganisatie', logo_data_url: '' } }),
      });
      return;
    }
    if (path === '/api/test-alert/schedule') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: schedule }) });
      return;
    }
    if (path === '/api/test-alert' && request.method() === 'GET') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: null }) });
      return;
    }
    if (path === '/api/test-alert' && request.method() === 'POST') {
      const response = await onPost(request.postDataJSON()?.scope);
      await route.fulfill({
        status: response.status ?? 201,
        contentType: 'application/json',
        body: JSON.stringify(response.body),
      });
      return;
    }

    await route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ error: { message: 'Test route not mocked.' } }) });
  });
}

function sendResult(summary: TestAlertSummary): MockPostResponse {
  return {
    body: {
      data: dispatchPayload(summary.scope),
      meta: summary,
    },
  };
}

function dispatchPayload(scope: 'self' | 'all_online') {
  return {
    id: `dispatch-${scope}`,
    incident_id: `incident-${scope}`,
    status: 'sent',
    priority: 'test',
    message: 'Dit is een proefalarm.',
    sent_at: '2026-07-14T20:00:00Z',
    recipients: [],
  };
}
