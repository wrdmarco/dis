import { expect, test, type APIResponse, type Page } from 'playwright/test';
import { GET as getSecurityText } from '../app/.well-known/security.txt/route';
import { isAuthenticatedSessionFailure } from '../src/lib/apiClient';
import { canonicalRedirectPath, validatedCanonicalRedirectOrigin } from '../src/lib/redirectPolicy';
import { buildContentSecurityPolicy } from '../src/lib/securityPolicy';

const requiredCspDirectives = [
  "default-src 'self'",
  "object-src 'none'",
  "base-uri 'self'",
  "frame-ancestors 'none'",
  "form-action 'self'",
];

test('only a definitive authenticated-session 401 clears the active web login', () => {
  expect(isAuthenticatedSessionFailure(401, 'unauthenticated')).toBe(true);
  expect(isAuthenticatedSessionFailure(401, 'session_expired')).toBe(true);
  expect(isAuthenticatedSessionFailure(401, 'registration_session_expired')).toBe(false);
  expect(isAuthenticatedSessionFailure(401, 'wallboard_unauthenticated')).toBe(false);
  expect(isAuthenticatedSessionFailure(403, 'unauthenticated')).toBe(false);
});

test('CSP allows only supported frames plus same-origin and local-blob media', () => {
  const policy = buildContentSecurityPolicy({ nonce: 'test-nonce', development: false });
  const frameDirective = policy.split(';').map((part) => part.trim()).find((part) => part.startsWith('frame-src '));
  const mediaDirective = policy.split(';').map((part) => part.trim()).find((part) => part.startsWith('media-src '));

  expect(frameDirective).toContain('https://www.youtube.com');
  expect(frameDirective).toContain('https://player.vimeo.com');
  expect(frameDirective).not.toContain('*.youtube.com');
  expect(frameDirective).not.toContain('*.vimeo.com');
  expect(mediaDirective).toBe("media-src 'self' blob:");
  expect(mediaDirective).not.toContain('data:');
  expect(mediaDirective).not.toContain('https:');
  expect(mediaDirective).not.toContain('*');
});

test.describe('public security contract', () => {
  for (const path of [
    '/',
    '/login',
    '/login/',
    '/apiary',
    '/.well-known/security.txt/extra',
    '/security-contract-not-found',
  ]) {
    test(`${path} enforces CSP and suppresses technology headers`, async ({ request }) => {
      const response = await request.get(path, { maxRedirects: 0 });

      expect(response.status(), `${path} should return an inspectable response`).toBeLessThan(500);
      assertSecurityHeaders(response);
    });
  }

  test('trailing-slash redirects use the configured canonical origin and preserve the query string', async ({ request }) => {
    const response = await request.get('/login/?next=%2Fprofile', { maxRedirects: 0 });
    const expectedLocation = 'https://dis.wrdmarco.nl/login?next=%2Fprofile';

    expect(response.status()).toBe(308);
    expect(response.headers().location).toBe(expectedLocation);
    expect(response.headers().location).not.toContain('localhost');
    if (response.headers().refresh !== undefined) {
      // Next adds this compatibility header to direct 308 responses. Nginx
      // removes it before the response reaches a public client.
      expect(response.headers().refresh).toBe(`0;url=${expectedLocation}`);
    }
    assertSecurityHeaders(response);
  });

  test('redirect canonicalization rejects unsafe origins and normalizes repeated slashes', () => {
    expect(canonicalRedirectPath('//login///')).toBe('/login');
    expect(canonicalRedirectPath('////')).toBe('/');
    expect(validatedCanonicalRedirectOrigin('https://dis.wrdmarco.nl')).toBe('https://dis.wrdmarco.nl');
    expect(validatedCanonicalRedirectOrigin('http://dis.wrdmarco.nl')).toBeNull();
    expect(validatedCanonicalRedirectOrigin('https://dis.wrdmarco.nl/path')).toBeNull();
    expect(validatedCanonicalRedirectOrigin('https://user@dis.wrdmarco.nl')).toBeNull();
  });

  test('security.txt is valid when configured and fails closed otherwise', async ({ request }) => {
    const response = await request.get('/.well-known/security.txt', { maxRedirects: 0 });

    expect([200, 503]).toContain(response.status());
    if (response.status() === 503) {
      expect(response.headers()['content-type']).toMatch(/^text\/plain;\s*charset=utf-8$/i);
      expect(response.headers()['cache-control']).toContain('no-store');
      expect((await response.text()).toLowerCase()).not.toContain('security@example');
      return;
    }

    expect(response.headers()['content-type']).toMatch(/^text\/plain;\s*charset=utf-8$/i);
    const body = await response.text();
    expect(body).toMatch(/^Contact:\s*(?:mailto:|https:\/\/).+$/im);
    expect(body).toContain('Canonical: https://dis.wrdmarco.nl/.well-known/security.txt');
    expect(body).toMatch(/^Preferred-Languages:\s*nl,\s*en$/im);

    const expires = body.match(/^Expires:\s*(.+)$/im)?.[1]?.trim();
    expect(expires, 'security.txt must contain Expires').toBeTruthy();
    expect(Number.isNaN(Date.parse(expires ?? ''))).toBe(false);
    expect(Date.parse(expires ?? '')).toBeGreaterThan(Date.now());
  });

  test('security.txt returns a complete RFC 9116 document for valid test-only configuration', async () => {
    const environment = saveEnvironment([
      'SECURITY_CONTACT',
      'NEXT_PUBLIC_APP_URL',
      'APP_URL',
      'NEXT_PUBLIC_WEBSOCKET_HOST',
    ]);

    try {
      // example.test is reserved for tests and is never written to production configuration.
      process.env.SECURITY_CONTACT = 'mailto:security@example.test';
      process.env.NEXT_PUBLIC_APP_URL = 'https://dis.wrdmarco.nl';
      delete process.env.APP_URL;
      delete process.env.NEXT_PUBLIC_WEBSOCKET_HOST;

      const earliestExpiry = Date.now();
      const response = getSecurityText();
      const body = await response.text();

      expect(response.status).toBe(200);
      expect(response.headers.get('content-type')).toMatch(/^text\/plain;\s*charset=utf-8$/i);
      expect(body).toContain('Contact: mailto:security@example.test');
      expect(body).toContain('Canonical: https://dis.wrdmarco.nl/.well-known/security.txt');
      expect(body).toMatch(/^Preferred-Languages:\s*nl,\s*en$/im);

      const expires = body.match(/^Expires:\s*(.+)$/im)?.[1]?.trim();
      expect(expires).toBeTruthy();
      expect(Number.isNaN(Date.parse(expires ?? ''))).toBe(false);
      expect(Date.parse(expires ?? '')).toBeGreaterThan(earliestExpiry);
      expect(Date.parse(expires ?? '')).toBeLessThanOrEqual(Date.now() + 181 * 24 * 60 * 60 * 1_000);
    } finally {
      restoreEnvironment(environment);
    }
  });

  test('security.txt fails closed for missing test configuration', async () => {
    const environment = saveEnvironment([
      'SECURITY_CONTACT',
      'NEXT_PUBLIC_APP_URL',
      'APP_URL',
      'NEXT_PUBLIC_WEBSOCKET_HOST',
    ]);

    try {
      delete process.env.SECURITY_CONTACT;
      delete process.env.NEXT_PUBLIC_APP_URL;
      delete process.env.APP_URL;
      delete process.env.NEXT_PUBLIC_WEBSOCKET_HOST;

      const response = getSecurityText();

      expect(response.status).toBe(503);
      expect(response.headers.get('content-type')).toMatch(/^text\/plain;\s*charset=utf-8$/i);
      expect(response.headers.get('cache-control')).toContain('no-store');
      expect(await response.text()).not.toMatch(/^(?:Contact|Expires|Canonical):/im);
    } finally {
      restoreEnvironment(environment);
    }
  });

  test('production CSP uses a fresh nonce that is applied to rendered markup', async ({ request }) => {
    const first = await request.get('/login');
    const second = await request.get('/login');
    const firstNonce = cspNonce(first);
    const secondNonce = cspNonce(second);

    expect(firstNonce).not.toBe(secondNonce);
    expect(await first.text()).toContain(`nonce="${firstNonce}"`);
    expect(await second.text()).toContain(`nonce="${secondNonce}"`);
  });

  test('legacy authentication storage is removed and web requests have no Authorization header', async ({ page }) => {
    const authorizationRequests = [];
    page.on('request', (request) => {
      if (request.headers().authorization !== undefined) {
        authorizationRequests.push(`${request.method()} ${request.url()}`);
      }
    });

    await page.goto('/login');
    await page.evaluate(() => {
      localStorage.setItem('dis.session.token', 'legacy-secret');
      localStorage.setItem('dis.session.purpose', 'mfa');
      sessionStorage.setItem('dis.session.token', 'legacy-secret');
      sessionStorage.setItem('dis.session.purpose', 'mfa');
    });
    await page.reload();
    await page.waitForLoadState('networkidle');

    const storage = await page.evaluate(() => ({
      local: Object.fromEntries(Object.entries(localStorage)),
      session: Object.fromEntries(Object.entries(sessionStorage)),
    }));
    for (const key of ['dis.session.token', 'dis.session.purpose']) {
      expect(storage.local[key]).toBeUndefined();
      expect(storage.session[key]).toBeUndefined();
    }
    expect(authorizationRequests).toEqual([]);
  });

  test('login page produces no CSP violations', async ({ page }) => {
    const violations = collectCspViolations(page);

    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    expect(violations).toEqual([]);
  });

  test('legacy query invitation credentials are scrubbed and never exchanged', async ({ page }) => {
    let invitationPayload: unknown;
    await page.route('**/api/auth/csrf-cookie', async (route) => {
      await route.fulfill({
        status: 204,
        headers: {
          'Set-Cookie': 'XSRF-TOKEN=e2e-csrf-token; Path=/; SameSite=Lax',
        },
      });
    });
    await page.route('**/api/registration/invite', async (route) => {
      invitationPayload = route.request().postDataJSON();
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          error: {
            code: 'invalid_invitation',
            message: 'The invitation is invalid.',
          },
        }),
      });
    });

    await page.goto('/register?email=query-user%40example.test&token=query-secret');
    await expect.poll(() => invitationPayload).toEqual({});
    await expect(page).toHaveURL(/\/register$/);
  });
});

test.describe('authenticated browser security contract', () => {
  test('login, optional 2FA, authenticated navigation and logout use only an HttpOnly session cookie', async ({ page, context }) => {
    const email = process.env.DIS_E2E_EMAIL;
    const password = process.env.DIS_E2E_PASSWORD;
    test.skip(!email || !password, 'Set DIS_E2E_EMAIL and DIS_E2E_PASSWORD for the real authenticated flow.');

    const authorizationRequests = [];
    const setCookieHeaders = [];
    page.on('request', (request) => {
      if (request.headers().authorization !== undefined) {
        authorizationRequests.push(`${request.method()} ${request.url()}`);
      }
    });
    page.on('response', (response) => {
      if (response.url().includes('/api/auth/')) {
        void response.allHeaders().then((headers) => {
          if (headers['set-cookie']) {
            setCookieHeaders.push(headers['set-cookie']);
          }
        });
      }
    });

    await page.goto('/login');
    await page.locator('input[type="email"]').fill(email ?? '');
    await page.locator('input[type="password"]').fill(password ?? '');
    await page.getByRole('button', { name: /inloggen/i }).click();

    const oneTimeCode = page.locator('input[autocomplete="one-time-code"]');
    if (await oneTimeCode.isVisible({ timeout: 2_000 }).catch(() => false)) {
      const code = process.env.DIS_E2E_2FA_CODE;
      expect(code, 'Set DIS_E2E_2FA_CODE when the account requires 2FA.').toBeTruthy();
      await oneTimeCode.fill(code ?? '');
      await page.getByRole('button', { name: /bevestigen|activeren/i }).click();
    }

    await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
    await page.goto('/profile');
    await expect(page).not.toHaveURL(/\/login(?:\?|$)/);

    const cookies = await context.cookies();
    const sessionCookie = cookies.find((cookie) => cookie.name === '__Host-dis_session');
    expect(sessionCookie, 'the HttpOnly __Host-dis_session cookie must exist').toBeTruthy();
    expect(sessionCookie?.httpOnly).toBe(true);
    expect(sessionCookie?.secure).toBe(true);
    expect(['Lax', 'Strict']).toContain(sessionCookie?.sameSite);
    expect(sessionCookie?.path).toBe('/');
    expect(setCookieHeaders.join('\n')).not.toMatch(/;\s*Domain=/i);

    const authStorage = await page.evaluate(() => ({
      local: Object.keys(localStorage).filter((key) => /(?:auth|bearer|session|token)/i.test(key)),
      session: Object.keys(sessionStorage).filter((key) => /(?:auth|bearer|session|token)/i.test(key)),
    }));
    expect(authStorage).toEqual({ local: [], session: [] });
    expect(authorizationRequests).toEqual([]);

    await page.getByRole('button', { name: /accountmenu openen/i }).click();
    await page.getByRole('button', { name: /uitloggen/i }).click();
    await expect(page).toHaveURL(/\/login(?:\?|$)/, { timeout: 10_000 });
    expect((await context.cookies()).some((cookie) => cookie.name === sessionCookie?.name)).toBe(false);
  });
});

function assertSecurityHeaders(response: APIResponse): void {
  const headers = response.headers();
  const csp = headers['content-security-policy'];
  expect(csp, 'Content-Security-Policy must be enforced').toBeTruthy();
  for (const directive of requiredCspDirectives) {
    expect(csp).toContain(directive);
  }
  expect(csp).not.toContain("'unsafe-eval'");
  expect(csp).not.toContain("'unsafe-inline'");
  expect(csp).not.toMatch(/(?:^|\s)\*(?:\s|;|$)/);

  expect(headers['x-content-type-options']).toBe('nosniff');
  expect(headers['referrer-policy']).toBeTruthy();
  expect(headers['permissions-policy']).toBeTruthy();
  expect(headers['cross-origin-opener-policy']).toBeTruthy();
  expect(headers['cross-origin-resource-policy']).toBeTruthy();
  for (const header of ['server', 'x-powered-by', 'x-nextjs-cache', 'x-nextjs-prerender', 'x-nextjs-stale-time', 'x-served-by']) {
    expect(headers[header], `${header} must not reveal technology`).toBeUndefined();
  }

  const cacheControl = headers['cache-control'] ?? '';
  expect(cacheControl).toContain('no-store');
}

function cspNonce(response: APIResponse): string {
  const csp = response.headers()['content-security-policy'] ?? '';
  const nonce = csp.match(/'nonce-([^']+)'/)?.[1];
  expect(nonce, 'CSP must contain a nonce').toBeTruthy();

  return nonce ?? '';
}

function collectCspViolations(page: Page): string[] {
  const violations = [];
  page.on('console', (message) => {
    if (/content security policy|violat(?:e|ion).*csp/i.test(message.text())) {
      violations.push(message.text());
    }
  });
  page.on('pageerror', (error) => {
    if (/content security policy|violat(?:e|ion).*csp/i.test(error.message)) {
      violations.push(error.message);
    }
  });

  return violations;
}

function saveEnvironment(names: string[]): Map<string, string | undefined> {
  return new Map(names.map((name) => [name, process.env[name]]));
}

function restoreEnvironment(environment: Map<string, string | undefined>): void {
  for (const [name, value] of environment) {
    if (value === undefined) {
      delete process.env[name];
    } else {
      process.env[name] = value;
    }
  }
}
