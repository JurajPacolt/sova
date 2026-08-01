import { HttpErrorResponse, HttpHeaders } from '@angular/common/http';
import { describeApiError, hasProblemCode, problemCode } from './api-error';

function problem(code: string, status: number, requestId = 'req-1'): Record<string, unknown> {
  return {
    type: 'https://sova.example/problems/' + code.toLowerCase(),
    title: 'Problem',
    status,
    detail: 'Internal wording that must not reach the screen.',
    instance: '/api/v1/things',
    request_id: requestId,
    code,
  };
}

function failure(
  status: number,
  body: unknown = null,
  headers: Record<string, string> = {},
): HttpErrorResponse {
  return new HttpErrorResponse({
    status,
    statusText: 'Error',
    error: body,
    headers: new HttpHeaders(headers),
    url: '/api/v1/things',
  });
}

describe('describeApiError', () => {
  /**
   * The server writes Problem Details in one language and this application
   * speaks six, so the wording is always a catalog key. The server's own
   * `detail` never reaches the screen.
   */
  it('answers with a catalog key rather than the server wording', () => {
    const described = describeApiError(failure(403, problem('PERMISSION_DENIED', 403)));

    expect(described.messageKey).toBe('error.forbidden');
    expect(described.code).toBe('PERMISSION_DENIED');
  });

  it('maps each status the API can answer with', () => {
    const cases: readonly [number, string][] = [
      [400, 'error.badRequest'],
      [401, 'error.sessionExpired'],
      [404, 'error.notFound'],
      [409, 'error.conflict'],
      [410, 'error.gone'],
      [413, 'error.tooLarge'],
      [422, 'error.validation'],
      [429, 'error.rateLimited'],
      [500, 'error.server'],
      [503, 'error.server'],
    ];

    for (const [status, key] of cases) {
      expect(describeApiError(failure(status)).messageKey).toBe(key);
    }
  });

  /**
   * A refused connection, a DNS failure and a cut cable all arrive as status
   * `0`. That is the case where repeating the request is worth offering and the
   * numbers already on screen are worth keeping.
   */
  it('reads status 0 as a lost connection, not as a refusal', () => {
    const described = describeApiError(failure(0));

    expect(described.offline).toBe(true);
    expect(described.messageKey).toBe('error.offline');
    expect(described.retryable).toBe(true);
  });

  it('offers a retry only where repeating could work', () => {
    expect(describeApiError(failure(500)).retryable).toBe(true);
    expect(describeApiError(failure(429)).retryable).toBe(true);
    // Hammering a door that will not open, or resending a body the server has
    // already refused on its merits, is not a retry worth offering.
    expect(describeApiError(failure(403)).retryable).toBe(false);
    expect(describeApiError(failure(422)).retryable).toBe(false);
  });

  it('keeps the correlation identifier, from the header when the body is cut short', () => {
    expect(describeApiError(failure(500, problem('INTERNAL', 500, 'req-9'))).requestId).toBe(
      'req-9',
    );
    // A crash or a proxy can truncate the body; the header still names the request.
    expect(
      describeApiError(failure(502, 'Bad Gateway', { 'X-Request-ID': 'req-h' })).requestId,
    ).toBe('req-h');
  });

  it('carries field errors so a form can point at the value it means', () => {
    const body = { ...problem('VALIDATION_FAILED', 422), errors: { name: ['Give it a name.'] } };

    expect(describeApiError(failure(422, body)).fieldErrors['name']).toEqual(['Give it a name.']);
  });

  /** An HTTP date would need a clock this cannot trust, so only seconds count. */
  it('reads Retry-After only in its seconds form', () => {
    expect(describeApiError(failure(429, null, { 'Retry-After': '30' })).retryAfterSeconds).toBe(
      30,
    );
    expect(
      describeApiError(failure(429, null, { 'Retry-After': 'Wed, 21 Oct 2026 07:28:00 GMT' }))
        .retryAfterSeconds,
    ).toBeNull();
  });

  it('still says something about a failure that was never a request', () => {
    const described = describeApiError(new TypeError('undefined is not a function'));

    expect(described.messageKey).toBe('error.unexpected');
    expect(described.retryable).toBe(false);
  });
});

describe('problemCode', () => {
  it('reads the domain code, and nothing from a body that is not Problem Details', () => {
    expect(problemCode(failure(409, problem('DASHBOARD_NAME_TAKEN', 409)))).toBe(
      'DASHBOARD_NAME_TAKEN',
    );
    expect(problemCode(failure(409, '<html>Gateway</html>'))).toBeNull();
    expect(problemCode('not an error at all')).toBeNull();
  });

  it('matches any of the codes a screen answers by name', () => {
    const error = failure(409, problem('LAST_DASHBOARD_REQUIRED', 409));

    expect(hasProblemCode(error, 'DASHBOARD_NAME_TAKEN', 'LAST_DASHBOARD_REQUIRED')).toBe(true);
    expect(hasProblemCode(error, 'DASHBOARD_NAME_TAKEN')).toBe(false);
  });
});
