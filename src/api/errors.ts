import type { Restriction } from './types';

/**
 * The API's error codes. Every failure the server sends carries one of these
 * in `code`, and the client branches on the code — never on message text,
 * which is operator-editable copy.
 *
 * Mirrors bootstrap/app.php (the envelope) plus the codes individual
 * controllers and middleware add.
 */
export type ApiErrorCode =
  | 'unauthenticated'
  | 'forbidden'
  | 'not_found'
  | 'validation_failed'
  | 'rate_limited'
  | 'request_failed'
  | 'server_error'
  | 'account_restricted'
  | 'account_deactivated'
  | 'premium_required'
  | 'photo_limit_reached'
  | 'maintenance'
  | 'sms_unavailable'
  | 'verification_attempts_exhausted'
  | 'product_unknown'
  | 'receipt_invalid'
  | 'receipt_owned_elsewhere'
  | 'store_unavailable'
  | 'network';

export interface ApiErrorPayload {
  message?: string;
  code?: string;
  errors?: Record<string, string[]>;
  /** account_restricted */
  restriction?: Restriction;
  /** premium_required */
  count?: number;
  /** photo_limit_reached */
  limit?: number;
}

export class ApiError extends Error {
  readonly status: number;
  readonly code: ApiErrorCode;
  readonly errors: Record<string, string[]>;
  /** Seconds, from Retry-After, when the server said how long to wait. */
  readonly retryAfter: number | null;
  readonly restriction: Restriction | null;
  readonly count: number | null;
  readonly limit: number | null;

  constructor(status: number, payload: ApiErrorPayload, retryAfter: number | null = null) {
    super(payload.message ?? defaultMessage(status));
    this.name = 'ApiError';
    this.status = status;
    this.code = normaliseCode(status, payload.code);
    this.errors = payload.errors ?? {};
    this.retryAfter = retryAfter;
    this.restriction = payload.restriction ?? null;
    this.count = payload.count ?? null;
    this.limit = payload.limit ?? null;
  }

  /** First validation message for a field, for inline form errors. */
  fieldError(field: string): string | null {
    return this.errors[field]?.[0] ?? null;
  }

  /** The first validation message of any field — for a single banner. */
  get firstError(): string | null {
    const first = Object.values(this.errors)[0];
    return first?.[0] ?? null;
  }

  static network(cause?: unknown): ApiError {
    const error = new ApiError(0, { message: 'No connection. Check your network and try again.', code: 'network' });
    (error as { cause?: unknown }).cause = cause;
    return error;
  }
}

/**
 * The server is authoritative about the code, but a proxy or a crash can
 * answer with no JSON body at all. Fill in from the status so callers always
 * have something to branch on.
 */
function normaliseCode(status: number, code: string | undefined): ApiErrorCode {
  if (code && isKnownCode(code)) return code;

  switch (status) {
    case 401:
      return 'unauthenticated';
    case 403:
      return 'forbidden';
    case 404:
      return 'not_found';
    case 422:
      return 'validation_failed';
    case 429:
      return 'rate_limited';
    case 503:
      return 'maintenance';
    default:
      return status >= 500 ? 'server_error' : 'request_failed';
  }
}

const KNOWN: ReadonlySet<string> = new Set<ApiErrorCode>([
  'unauthenticated',
  'forbidden',
  'not_found',
  'validation_failed',
  'rate_limited',
  'request_failed',
  'server_error',
  'account_restricted',
  'account_deactivated',
  'premium_required',
  'photo_limit_reached',
  'maintenance',
  'sms_unavailable',
  'verification_attempts_exhausted',
  'network',
]);

function isKnownCode(code: string): code is ApiErrorCode {
  return KNOWN.has(code);
}

function defaultMessage(status: number): string {
  if (status === 0) return 'No connection.';
  if (status >= 500) return 'Something went wrong on our side. Please try again.';
  return 'Request failed.';
}

export function isApiError(value: unknown): value is ApiError {
  if (value instanceof ApiError) return true;

  // A duplicate-module-instance guard: under some loaders the class can be
  // evaluated twice and instanceof lies. The shape is what matters.
  return (
    typeof value === 'object' &&
    value !== null &&
    (value as { name?: unknown }).name === 'ApiError' &&
    typeof (value as { code?: unknown }).code === 'string' &&
    typeof (value as { status?: unknown }).status === 'number'
  );
}
