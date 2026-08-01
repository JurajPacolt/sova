import {
  HttpClient,
  HttpErrorResponse,
  provideHttpClient,
  withInterceptors,
} from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { apiCredentialsInterceptor, readCookie } from './api-credentials.interceptor';
import { sessionExpiryInterceptor } from '../auth/session-expiry.interceptor';
import { SessionExpiryHandler } from '../auth/session-expiry.handler';

describe('API HTTP interceptors', () => {
  const expiryHandler = {
    handle: vi.fn(),
  };
  let http: HttpClient;
  let httpTesting: HttpTestingController;

  beforeEach(() => {
    expiryHandler.handle.mockReset();
    document.cookie = 'sova_csrf=; Max-Age=0; Path=/';

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([apiCredentialsInterceptor, sessionExpiryInterceptor])),
        provideHttpClientTesting(),
        {
          provide: SessionExpiryHandler,
          useValue: expiryHandler,
        },
      ],
    });

    http = TestBed.inject(HttpClient);
    httpTesting = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpTesting.verify();
    document.cookie = 'sova_csrf=; Max-Age=0; Path=/';
  });

  it('sends credentials on API requests without adding CSRF to safe methods', () => {
    http.get('/api/v1/tenants').subscribe();

    const request = httpTesting.expectOne('/api/v1/tenants');
    expect(request.request.withCredentials).toBe(true);
    expect(request.request.headers.has('X-CSRF-Token')).toBe(false);
    request.flush({ tenants: [] });
  });

  it('copies the readable CSRF cookie to unsafe API requests', () => {
    document.cookie = `sova_csrf=${encodeURIComponent('csrf value')}; Path=/`;

    http.post('/api/v1/auth/logout', null).subscribe();

    const request = httpTesting.expectOne('/api/v1/auth/logout');
    expect(request.request.withCredentials).toBe(true);
    expect(request.request.headers.get('X-CSRF-Token')).toBe('csrf value');
    request.flush(null);
  });

  it('does not attach application credentials to non-API requests', () => {
    http.get('https://example.test/public.json').subscribe();

    const request = httpTesting.expectOne('https://example.test/public.json');
    expect(request.request.withCredentials).toBe(false);
    expect(request.request.headers.has('X-CSRF-Token')).toBe(false);
    request.flush({});
  });

  it('invalidates the session only for the stable session-required problem', () => {
    http.get('/api/v1/tenants').subscribe({
      error: (error: unknown) => expect(error).toBeInstanceOf(HttpErrorResponse),
    });

    const request = httpTesting.expectOne('/api/v1/tenants');
    request.flush(
      {
        type: 'urn:sova:problem:authentication-required',
        title: 'Authentication Required',
        status: 401,
        detail: 'A valid session is required.',
        instance: '/api/v1/tenants',
        request_id: 'request-id',
        code: 'SESSION_REQUIRED',
      },
      { status: 401, statusText: 'Unauthorized' },
    );

    expect(expiryHandler.handle).toHaveBeenCalledOnce();
  });
});

describe('readCookie', () => {
  it('decodes the selected cookie without accepting a prefix match', () => {
    expect(readCookie('other_sova_csrf=bad; sova_csrf=valid%20token', 'sova_csrf')).toBe(
      'valid token',
    );
  });

  it('returns null for malformed percent encoding', () => {
    expect(readCookie('sova_csrf=%E0%A4%A', 'sova_csrf')).toBeNull();
  });
});
