import type { ApiErrorBody, ApiResponse, LaravelValidationErrorBody } from '../types/api';

export class ApiClientError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly code: string,
    public readonly details?: Record<string, unknown>,
  ) {
    super(message);
  }
}

export interface ApiClientOptions {
  baseUrl: string;
  onUnauthenticated: () => void;
}

export class ApiClient {
  private csrfRequest: Promise<void> | null = null;

  constructor(private readonly options: ApiClientOptions) {}

  async get<T>(path: string): Promise<ApiResponse<T>> {
    return this.request<T>('GET', path);
  }

  async post<T>(path: string, body?: unknown): Promise<ApiResponse<T>> {
    return this.request<T>('POST', path, body);
  }

  async postForm<T>(path: string, body: FormData): Promise<ApiResponse<T>> {
    return this.request<T>('POST', path, body);
  }

  async patch<T>(path: string, body?: unknown): Promise<ApiResponse<T>> {
    return this.request<T>('PATCH', path, body);
  }

  async delete<T>(path: string): Promise<ApiResponse<T>> {
    return this.request<T>('DELETE', path);
  }

  async download(path: string): Promise<{ blob: Blob; filename?: string }> {
    const response = await fetch(`${this.options.baseUrl}${path}`, {
      method: 'GET',
      credentials: 'include',
      headers: {
        Accept: 'application/pdf,application/octet-stream,application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });

    if (!response.ok) {
      throw await this.errorFromResponse(response, 'Download failed.');
    }

    return {
      blob: await response.blob(),
      filename: filenameFromDisposition(response.headers.get('Content-Disposition')),
    };
  }

  private async request<T>(method: string, path: string, body?: unknown, retriedAfterCsrf = false): Promise<ApiResponse<T>> {
    const mutating = isMutatingMethod(method);
    if (mutating) {
      await this.ensureCsrfCookie();
    }

    const csrfToken = mutating ? csrfTokenFromCookie() : null;
    const response = await fetch(`${this.options.baseUrl}${path}`, {
      method,
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(body === undefined || body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
        ...(csrfToken === null ? {} : { 'X-XSRF-TOKEN': csrfToken }),
      },
      body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body),
    });

    if (response.status === 419 && mutating && !retriedAfterCsrf) {
      await this.ensureCsrfCookie(true);

      return this.request<T>(method, path, body, true);
    }

    if (response.status === 204) {
      return { data: null as T };
    }

    const payload = (await response.json().catch(() => null)) as ApiResponse<T> | ApiErrorBody | LaravelValidationErrorBody | null;

    if (!response.ok) {
      throw this.errorFromPayload(response.status, payload, 'API request failed.');
    }

    return payload as ApiResponse<T>;
  }

  private async ensureCsrfCookie(force = false): Promise<void> {
    if (!force && csrfTokenFromCookie() !== null) {
      return;
    }

    if (this.csrfRequest !== null) {
      return this.csrfRequest;
    }

    this.csrfRequest = (async () => {
      const response = await fetch(`${this.options.baseUrl}/auth/csrf-cookie`, {
        method: 'GET',
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      if (!response.ok) {
        throw await this.errorFromResponse(response, 'Unable to initialize the secure session.');
      }
    })();

    try {
      await this.csrfRequest;
    } finally {
      this.csrfRequest = null;
    }
  }

  private async errorFromResponse(response: Response, fallbackMessage: string): Promise<ApiClientError> {
    const payload = (await response.json().catch(() => null)) as ApiErrorBody | LaravelValidationErrorBody | null;

    return this.errorFromPayload(response.status, payload, fallbackMessage);
  }

  private errorFromPayload(status: number, payload: unknown, fallbackMessage: string): ApiClientError {
    const error = payload !== null && typeof payload === 'object' && 'error' in payload
      ? (payload as ApiErrorBody).error
      : undefined;
    const validationMessage = readValidationMessage(payload) ?? readValidationMessage(error?.details);

    if (status === 401) {
      this.options.onUnauthenticated();
    }

    return new ApiClientError(
      validationMessage ?? error?.message ?? fallbackMessage,
      status,
      error?.code ?? (validationMessage ? 'validation_failed' : 'server_error'),
      error?.details,
    );
  }
}

export const apiBaseUrl = process.env.NEXT_PUBLIC_API_BASE_URL ?? '/api';

export function csrfTokenFromCookie(): string | null {
  if (typeof document === 'undefined') {
    return null;
  }

  const cookie = document.cookie
    .split(';')
    .map((part) => part.trim())
    .find((part) => part.startsWith('XSRF-TOKEN='));
  if (cookie === undefined) {
    return null;
  }

  const value = cookie.slice('XSRF-TOKEN='.length);
  try {
    return decodeURIComponent(value);
  } catch {
    return value;
  }
}

function isMutatingMethod(method: string): boolean {
  return ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method.toUpperCase());
}

function readValidationMessage(payload: unknown): string | null {
  if (payload === null || typeof payload !== 'object') {
    return null;
  }

  const candidate = payload as LaravelValidationErrorBody;
  if (typeof candidate.message === 'string' && candidate.message.trim() !== '') {
    return candidate.message;
  }

  if (candidate.errors !== undefined) {
    const firstErrors = Object.values(candidate.errors).find((messages) => Array.isArray(messages) && messages.length > 0);
    const firstMessage = firstErrors?.[0];

    return typeof firstMessage === 'string' && firstMessage.trim() !== '' ? firstMessage : null;
  }

  const directErrors = Object.values(payload as Record<string, unknown>).find(
    (messages): messages is unknown[] => Array.isArray(messages) && messages.length > 0,
  );
  const directMessage = directErrors?.[0];

  return typeof directMessage === 'string' && directMessage.trim() !== '' ? directMessage : null;
}

function filenameFromDisposition(disposition: string | null): string | undefined {
  if (disposition === null) {
    return undefined;
  }

  const match = /filename="?([^"]+)"?/i.exec(disposition);
  return match?.[1];
}
