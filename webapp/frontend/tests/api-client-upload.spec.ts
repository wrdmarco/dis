import { expect, test } from 'playwright/test';
import { ApiClient, ApiClientError } from '../src/lib/apiClient';

interface FakeResponse {
  status: number;
  body: unknown;
}

class FakeEventTarget {
  private readonly listeners = new Map<string, Array<(event: Event) => void>>();

  addEventListener(type: string, listener: EventListenerOrEventListenerObject | null): void {
    if (listener === null) return;
    const callback = typeof listener === 'function'
      ? listener
      : (event: Event) => listener.handleEvent(event);
    const listeners = this.listeners.get(type) ?? [];
    listeners.push(callback);
    this.listeners.set(type, listeners);
  }

  emit(type: string, event: Event): void {
    for (const listener of this.listeners.get(type) ?? []) listener(event);
  }
}

class FakeXMLHttpRequest extends FakeEventTarget {
  static responses: FakeResponse[] = [];
  static instances: FakeXMLHttpRequest[] = [];

  readonly upload = new FakeEventTarget();
  readonly headers = new Map<string, string>();
  method = '';
  url = '';
  withCredentials = false;
  status = 0;
  responseText = '';
  body: Document | XMLHttpRequestBodyInit | null = null;

  constructor() {
    super();
    FakeXMLHttpRequest.instances.push(this);
  }

  open(method: string, url: string): void {
    this.method = method;
    this.url = url;
  }

  setRequestHeader(name: string, value: string): void {
    this.headers.set(name, value);
  }

  send(body: Document | XMLHttpRequestBodyInit | null): void {
    this.body = body;
    const response = FakeXMLHttpRequest.responses.shift();
    if (response === undefined) throw new Error('Missing fake XMLHttpRequest response.');

    queueMicrotask(() => {
      this.upload.emit('progress', progressEvent(250, 1_000));
      this.upload.emit('progress', progressEvent(995, 1_000));
      this.upload.emit('progress', progressEvent(1_000, 1_000));
      this.status = response.status;
      this.responseText = JSON.stringify(response.body);
      this.emit('load', new Event('load'));
    });
  }
}

test('form uploads report real transfer percentages and keep session and CSRF headers', async () => {
  await withFakeBrowser([{ status: 201, body: { data: { id: 'asset' } } }], async () => {
    const percentages: Array<number | null> = [];
    const client = new ApiClient({ baseUrl: '/api', onUnauthenticated: () => undefined });
    const form = new FormData();
    form.set('file', new Blob(['video']), 'video.mp4');

    const response = await client.postForm<{ id: string }>(
      '/admin/wallboard-media/assets',
      form,
      ({ percentage }) => percentages.push(percentage),
    );

    expect(response.data.id).toBe('asset');
    expect(percentages).toEqual([25, 99, 100]);
    expect(FakeXMLHttpRequest.instances).toHaveLength(1);
    const request = FakeXMLHttpRequest.instances[0];
    expect(request.method).toBe('POST');
    expect(request.url).toBe('/api/admin/wallboard-media/assets');
    expect(request.withCredentials).toBe(true);
    expect(request.headers.get('Accept')).toBe('application/json');
    expect(request.headers.get('X-Requested-With')).toBe('XMLHttpRequest');
    expect(request.headers.get('X-XSRF-TOKEN')).toBe('csrf token');
    expect(request.headers.has('Content-Type')).toBe(false);
    expect(request.body).toBe(form);
  });
});

test('form uploads retry one 419 and retain definitive 401 session handling', async () => {
  let unauthenticatedCount = 0;
  await withFakeBrowser([
    { status: 419, body: { error: { code: 'csrf_token_mismatch', message: 'Refresh CSRF.' } } },
    { status: 200, body: { data: { id: 'retried' } } },
    { status: 401, body: { error: { code: 'unauthenticated', message: 'Session expired.' } } },
  ], async (csrfFetches) => {
    const client = new ApiClient({
      baseUrl: '/api',
      onUnauthenticated: () => { unauthenticatedCount += 1; },
    });
    const progress: Array<number | null> = [];

    await expect(client.postForm<{ id: string }>(
      '/admin/wallboard-media/assets',
      new FormData(),
      ({ percentage }) => progress.push(percentage),
    )).resolves.toEqual({ data: { id: 'retried' } });
    expect(csrfFetches()).toBe(1);
    expect(progress).toEqual([25, 99, 100, 0, 25, 99, 100]);

    const error = await client.postForm(
      '/admin/wallboard-media/assets',
      new FormData(),
      () => undefined,
    ).catch((reason: unknown) => reason);
    expect(error).toBeInstanceOf(ApiClientError);
    expect(error).toMatchObject({ status: 401, code: 'unauthenticated' });
    expect(unauthenticatedCount).toBe(1);
  });
});

async function withFakeBrowser(
  responses: FakeResponse[],
  run: (csrfFetches: () => number) => Promise<void>,
): Promise<void> {
  const documentDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'document');
  const xhrDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'XMLHttpRequest');
  const fetchDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'fetch');
  let csrfFetchCount = 0;
  FakeXMLHttpRequest.responses = [...responses];
  FakeXMLHttpRequest.instances = [];

  Object.defineProperty(globalThis, 'document', {
    configurable: true,
    value: { cookie: 'XSRF-TOKEN=csrf%20token' },
  });
  Object.defineProperty(globalThis, 'XMLHttpRequest', {
    configurable: true,
    value: FakeXMLHttpRequest,
  });
  Object.defineProperty(globalThis, 'fetch', {
    configurable: true,
    value: async () => {
      csrfFetchCount += 1;
      return new Response(null, { status: 204 });
    },
  });

  try {
    await run(() => csrfFetchCount);
    expect(FakeXMLHttpRequest.responses).toEqual([]);
  } finally {
    restoreGlobal('document', documentDescriptor);
    restoreGlobal('XMLHttpRequest', xhrDescriptor);
    restoreGlobal('fetch', fetchDescriptor);
  }
}

function progressEvent(loaded: number, total: number): ProgressEvent {
  return Object.assign(new Event('progress'), { lengthComputable: true, loaded, total }) as ProgressEvent;
}

function restoreGlobal(name: 'document' | 'XMLHttpRequest' | 'fetch', descriptor: PropertyDescriptor | undefined): void {
  if (descriptor === undefined) {
    delete (globalThis as unknown as Record<string, unknown>)[name];
    return;
  }
  Object.defineProperty(globalThis, name, descriptor);
}
