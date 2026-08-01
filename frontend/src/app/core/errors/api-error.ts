import { HttpErrorResponse } from '@angular/common/http';
import { isProblemDetails, ProblemDetails } from '../api/api.models';
import { TranslationKey } from '../i18n/translations';

/**
 * A failed request turned into something a screen can show.
 *
 * The wording is a **catalog key**, never the server's `detail`: Problem Details
 * are written in one language and this application speaks six. The identifiers
 * the server does send — the domain `code` and the correlation `request_id` —
 * travel with it, so a screen can react to a specific rule and a person can
 * quote a failure to support (webflow `05-STAVY-ROZHRANIA.md` §3.2).
 */
export interface ApiError {
  /** `0` when the request never reached the server. */
  readonly status: number;
  readonly code: string | null;
  /** Shown for server faults, where it is the only way to find the request again. */
  readonly requestId: string | null;
  readonly messageKey: TranslationKey;
  readonly fieldErrors: Readonly<Record<string, readonly string[]>>;
  /** True when trying the same request again could plausibly succeed. */
  readonly retryable: boolean;
  /** The request never left the browser — a network fault, not a refusal. */
  readonly offline: boolean;
  readonly retryAfterSeconds: number | null;
}

/**
 * The catalog key for each HTTP status the API can answer with (§3.1).
 *
 * `401` is included for completeness only: the session interceptor takes the
 * screen to the login page before a caller gets to render it.
 */
function messageKeyFor(status: number): TranslationKey {
  if (status >= 500) {
    return 'error.server';
  }

  switch (status) {
    case 0:
      return 'error.offline';
    case 400:
      return 'error.badRequest';
    case 401:
      return 'error.sessionExpired';
    case 403:
      return 'error.forbidden';
    case 404:
      return 'error.notFound';
    case 409:
      return 'error.conflict';
    case 410:
      return 'error.gone';
    case 413:
      return 'error.tooLarge';
    case 422:
      return 'error.validation';
    case 429:
      return 'error.rateLimited';
    default:
      return 'error.unexpected';
  }
}

export function describeApiError(error: unknown): ApiError {
  if (!(error instanceof HttpErrorResponse)) {
    // Something that never was a request — a bug in this application rather
    // than an answer from the server. It still gets a sentence instead of a
    // blank screen.
    return {
      status: 0,
      code: null,
      requestId: null,
      messageKey: 'error.unexpected',
      fieldErrors: {},
      retryable: false,
      offline: false,
      retryAfterSeconds: null,
    };
  }

  const problem = isProblemDetails(error.error) ? error.error : null;
  // A browser reports a refused connection, a DNS failure and a cut cable
  // identically, as status `0` — which is exactly the case where the numbers on
  // screen are worth keeping and the request worth repeating.
  const offline = error.status === 0;

  return {
    status: error.status,
    code: problem?.code ?? null,
    requestId: requestIdOf(error, problem),
    messageKey: messageKeyFor(error.status),
    fieldErrors: problem?.errors ?? {},
    retryable: offline || error.status === 429 || error.status >= 500,
    offline,
    retryAfterSeconds: retryAfterOf(error),
  };
}

/** The domain code the server sent, for the rules a screen answers by name. */
export function problemCode(error: unknown): string | null {
  return error instanceof HttpErrorResponse && isProblemDetails(error.error)
    ? error.error.code
    : null;
}

export function hasProblemCode(error: unknown, ...codes: readonly string[]): boolean {
  const code = problemCode(error);

  return code !== null && codes.includes(code);
}

/**
 * The correlation identifier, from the body when the server managed to write
 * one and from the header when it did not — a proxy or a crash can cut the body
 * short, and the header survives that.
 */
function requestIdOf(error: HttpErrorResponse, problem: ProblemDetails | null): string | null {
  const header = error.headers.get('X-Request-ID');

  return problem?.request_id ?? (header !== null && header !== '' ? header : null);
}

/** Only the seconds form: an HTTP date would need a clock this cannot trust. */
function retryAfterOf(error: HttpErrorResponse): number | null {
  const header = error.headers.get('Retry-After');

  if (header === null || !/^\d+$/u.test(header.trim())) {
    return null;
  }

  return Number.parseInt(header.trim(), 10);
}
