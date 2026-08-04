import { expect, test } from 'playwright/test';

test('recovers the authenticated session when the mobile approval completion response is lost', async ({ page }) => {
  let authenticated = false;
  let meRequests = 0;
  let completionRequests = 0;
  const user = {
    id: '01K20WEBLOGINAPPROVALUSER1',
    name: 'Mobiele goedkeuring',
    email: 'approval@example.test',
    account_status: 'active',
    push_enabled: true,
    max_operator_devices: 2,
    two_factor_enabled: true,
    profile_completion_required: false,
    roles: [{
      id: '01K20WEBLOGINAPPROVALROLE1',
      name: 'operator',
      display_name: 'Operator',
      can_use_operator_app: true,
      can_use_admin_app: false,
      permissions: [],
    }],
    teams: [],
  };

  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;

    if (path === '/api/auth/csrf-cookie') {
      await route.fulfill({
        status: 204,
        headers: { 'Set-Cookie': 'XSRF-TOKEN=e2e-csrf-token; Path=/; SameSite=Lax' },
      });
      return;
    }
    if (path === '/api/auth/me') {
      meRequests += 1;
      await route.fulfill(authenticated ? {
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: user }),
      } : {
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ error: { code: 'unauthenticated', message: 'Authentication is required.' } }),
      });
      return;
    }
    if (path === '/api/branding') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { name: 'DIS', short_name: 'DIS', login_title: 'Inloggen', tenant_name: 'Test' } }),
      });
      return;
    }
    if (path === '/api/auth/login') {
      await route.fulfill({
        status: 202,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            requires_2fa: true,
            authenticated: false,
            mobile_approval: {
              available: true,
              status: 'pending',
              expires_at: new Date(Date.now() + 120_000).toISOString(),
              poll_after_seconds: 1,
              verification_number: '321',
            },
          },
        }),
      });
      return;
    }
    if (path === '/api/auth/2fa/mobile-approval/status') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            available: true,
            status: 'approved',
            expires_at: new Date(Date.now() + 110_000).toISOString(),
            poll_after_seconds: 1,
            verification_number: '321',
          },
        }),
      });
      return;
    }
    if (path === '/api/auth/2fa/mobile-approval/complete') {
      completionRequests += 1;
      authenticated = true;
      await route.abort('failed');
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [] }),
    });
  });

  await page.goto('/login');
  await page.locator('input[type="email"]').fill('approval@example.test');
  await page.locator('input[type="password"]').fill('Test-password-123!');
  await page.getByRole('button', { name: 'Inloggen' }).click();

  await expect(page.getByText('Goedkeuren via de app')).toBeVisible();
  await expect(page.getByText('321', { exact: true })).toBeVisible();
  await expect(page).toHaveURL(/\/profile$/, { timeout: 10_000 });
  expect(completionRequests).toBe(1);
  expect(meRequests).toBeGreaterThanOrEqual(2);
});
