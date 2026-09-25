import { ApiError, type ApiErrorPayload } from './errors';

/**
 * One HTTP client for the whole app.
 *
 * Deliberately free of anything React or Expo: it takes a token provider and
 * a base URL, so the same module runs under Node for connectivity checks and
 * under React Native in the app. Every response goes through one interpreter,
 * so there is exactly one place that knows the API's error envelope.
 */

export interface ClientOptions {
  baseUrl: string;
  getToken: () => Promise<string | null>;
  /** Called on every error after interpretation — the app wires global handling here. */
  onError?: (error: ApiError) => void;
  fetchImpl?: typeof fetch;
  /** App version sent as a header, so the server can see what is talking to it. */
  appVersion?: string;
}

export type Query = Record<string, string | number | boolean | null | undefined>;

export interface RequestOptions {
  query?: Query;
  body?: unknown;
  /** A FormData body is sent as-is; the browser/RN sets the multipart boundary. */
  formData?: FormData;
  headers?: Record<string, string>;
  /** Skip the bearer header, for public routes and sign-in itself. */
  anonymous?: boolean;
  signal?: AbortSignal;
}

export class ApiClient {
  private readonly baseUrl: string;

  constructor(private readonly options: ClientOptions) {
    this.baseUrl = options.baseUrl.replace(/\/+$/, '');
  }

  get<T>(path: string, options: RequestOptions = {}): Promise<T> {
    return this.request<T>('GET', path, options);
  }

  post<T>(path: string, options: RequestOptions = {}): Promise<T> {
    return this.request<T>('POST', path, options);
  }

  patch<T>(path: string, options: RequestOptions = {}): Promise<T> {
    return this.request<T>('PATCH', path, options);
  }

  put<T>(path: string, options: RequestOptions = {}): Promise<T> {
    return this.request<T>('PUT', path, options);
  }

  delete<T>(path: string, options: RequestOptions = {}): Promise<T> {
    return this.request<T>('DELETE', path, options);
  }

  async request<T>(method: string, path: string, options: RequestOptions): Promise<T> {
    const url = this.buildUrl(path, options.query);
    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...(this.options.appVersion ? { 'X-App-Version': this.options.appVersion } : {}),
      ...options.headers,
    };

    if (!options.anonymous) {
      const token = await this.options.getToken();
      if (token) headers.Authorization = `Bearer ${token}`;
    }

    let body: BodyInit | undefined;

    if (options.formData) {
      body = options.formData;
    } else if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(options.body);
    }

    let response: Response;

    try {
      response = await (this.options.fetchImpl ?? fetch)(url, { method, headers, body, signal: options.signal });
    } catch (cause) {
      const error = ApiError.network(cause);
      this.options.onError?.(error);
      throw error;
    }

    const payload = await readJson(response);

    if (!response.ok) {
      const error = new ApiError(response.status, (payload ?? {}) as ApiErrorPayload, retryAfter(response));
      this.options.onError?.(error);
      throw error;
    }

    return payload as T;
  }

  private buildUrl(path: string, query?: Query): string {
    const url = `${this.baseUrl}/${path.replace(/^\/+/, '')}`;

    if (!query) return url;

    const params = Object.entries(query)
      .filter(([, value]) => value !== undefined && value !== null && value !== '')
      .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);

    return params.length ? `${url}?${params.join('&')}` : url;
  }
}

async function readJson(response: Response): Promise<unknown> {
  const text = await response.text();

  if (!text) return null;

  try {
    return JSON.parse(text);
  } catch {
    // An HTML error page from a proxy. The status still tells us what happened.
    return null;
  }
}

/** Retry-After in seconds, whether the server sent seconds or an HTTP date. */
function retryAfter(response: Response): number | null {
  const header = response.headers.get('Retry-After');

  if (!header) return null;

  const seconds = Number(header);
  if (Number.isFinite(seconds)) return Math.max(0, Math.ceil(seconds));

  const date = Date.parse(header);
  return Number.isNaN(date) ? null : Math.max(0, Math.ceil((date - Date.now()) / 1000));
}
