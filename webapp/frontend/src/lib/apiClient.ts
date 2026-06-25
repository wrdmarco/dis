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
  getToken: () => string | null;
  onUnauthenticated: () => void;
}

export class ApiClient {
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

  private async request<T>(method: string, path: string, body?: unknown): Promise<ApiResponse<T>> {
    const token = this.options.getToken();
    const response = await fetch(`${this.options.baseUrl}${path}`, {
      method,
      headers: {
        Accept: 'application/json',
        ...(body === undefined || body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body),
    });

    if (response.status === 204) {
      return { data: null as T };
    }

    const payload = (await response.json().catch(() => null)) as ApiResponse<T> | ApiErrorBody | LaravelValidationErrorBody | null;

    if (!response.ok) {
      const error = payload && 'error' in payload ? payload.error : undefined;
      const validationMessage = readValidationMessage(payload) ?? readValidationMessage(error?.details);
      if (response.status === 401) {
        this.options.onUnauthenticated();
      }
      throw new ApiClientError(
        validationMessage ?? error?.message ?? 'API request failed.',
        response.status,
        error?.code ?? (validationMessage ? 'validation_failed' : 'server_error'),
        error?.details,
      );
    }

    return payload as ApiResponse<T>;
  }
}

export const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? '/api';

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

  return null;
}
