import { readFileSync } from 'node:fs';
import { expect, test, type Page, type Route } from 'playwright/test';

const addressBookPage = readFileSync(
  new URL('../src/features/address-book/AddressBookPage.tsx', import.meta.url),
  'utf8',
);

test('address-book names use a calm dedicated text style instead of strong emphasis', () => {
  expect(addressBookPage).toContain('<span className={styles.contactName}>{entry.name}</span>');
  expect(addressBookPage).not.toContain('<strong>{entry.name}</strong>');
});

test('address-book names render at medium weight', async ({ page }) => {
  await mockAddressBookApi(page);
  await page.goto('/address-book');

  const contactName = page.getByText('Annemarie van den Berg', { exact: true });
  await expect(contactName).toBeVisible();
  await expect.poll(() => contactName.evaluate((element) => ({
    tagName: element.tagName,
    weight: window.getComputedStyle(element).fontWeight,
  }))).toEqual({
    tagName: 'SPAN',
    weight: '500',
  });
});

async function mockAddressBookApi(page: Page): Promise<void> {
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/auth/me') {
      await fulfillJson(route, 200, {
        data: {
          id: 'address-book-user',
          name: 'Adresboek Testgebruiker',
          email: 'address-book@example.test',
          account_status: 'active',
          push_enabled: true,
          max_operator_devices: 3,
          two_factor_enabled: true,
          mfa_required: false,
          profile_completion_required: false,
          mail_preferences: { ui: { theme: 'dark' } },
          roles: [{
            id: 'address-book-role',
            name: 'address-book-role',
            display_name: 'Adresboekrol',
            can_use_operator_app: false,
            can_use_admin_app: true,
            permissions: [{
              id: 'address-book-view',
              name: 'address-book.view',
              category: 'users',
              display_name: 'Adresboek bekijken',
            }],
          }],
        },
      });
      return;
    }

    if (path === '/api/branding') {
      await fulfillJson(route, 200, {
        data: {
          name: 'DIS',
          short_name: 'DIS',
          tenant_name: 'Testorganisatie',
          logo_data_url: '',
        },
      });
      return;
    }

    if (path === '/api/address-book') {
      await fulfillJson(route, 200, {
        data: [{
          id: 'contact-1',
          name: 'Annemarie van den Berg',
          phone_number: '+31612345678',
          city: 'Utrecht',
          region: 'Utrecht',
          country: 'NL',
        }],
      });
      return;
    }

    await fulfillJson(route, 404, {
      error: {
        code: 'not_found',
        message: 'Testroute niet gemockt.',
        details: {},
      },
    });
  });
}

async function fulfillJson(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  });
}
