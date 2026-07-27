import { expect, test } from 'playwright/test';
import { ApiClient } from '../src/lib/apiClient';

test('DELETE can send a JSON body while retaining the session and CSRF contract', async () => {
  const documentDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'document');
  const fetchDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'fetch');
  const requests: Array<{ input: string; init: RequestInit | undefined }> = [];

  Object.defineProperty(globalThis, 'document', {
    configurable: true,
    value: { cookie: 'XSRF-TOKEN=csrf%20token' },
  });
  Object.defineProperty(globalThis, 'fetch', {
    configurable: true,
    value: async (input: string | URL | Request, init?: RequestInit) => {
      requests.push({ input: String(input), init });
      return new Response(null, { status: 204 });
    },
  });

  try {
    const client = new ApiClient({ baseUrl: '/api', onUnauthenticated: () => undefined });

    await expect(client.delete('/deployment-requests/request-1', {
      lock_version: 7,
    })).resolves.toEqual({ data: null });

    expect(requests).toHaveLength(1);
    expect(requests[0]?.input).toBe('/api/deployment-requests/request-1');
    expect(requests[0]?.init).toMatchObject({
      method: 'DELETE',
      credentials: 'include',
      body: JSON.stringify({ lock_version: 7 }),
    });
    expect(new Headers(requests[0]?.init?.headers).get('Content-Type')).toBe('application/json');
    expect(new Headers(requests[0]?.init?.headers).get('X-XSRF-TOKEN')).toBe('csrf token');
  } finally {
    restoreGlobal('document', documentDescriptor);
    restoreGlobal('fetch', fetchDescriptor);
  }
});

function restoreGlobal(
  name: 'document' | 'fetch',
  descriptor: PropertyDescriptor | undefined,
): void {
  if (descriptor === undefined) {
    delete (globalThis as unknown as Record<string, unknown>)[name];
    return;
  }
  Object.defineProperty(globalThis, name, descriptor);
}
