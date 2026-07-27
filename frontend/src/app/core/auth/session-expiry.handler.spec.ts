import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { AuthSessionService } from './auth-session.service';
import { isPublicAccessPath, SessionExpiryHandler } from './session-expiry.handler';

describe('isPublicAccessPath', () => {
  it('keeps anonymous session probes on one-time-link screens', () => {
    expect(isPublicAccessPath('/accept-invitation/token')).toBe(true);
    expect(isPublicAccessPath('/verify-email?status=invalid')).toBe(true);
    expect(isPublicAccessPath('/reset-password')).toBe(true);
  });

  it('does not suppress redirects from protected or lookalike routes', () => {
    expect(isPublicAccessPath('/t/acme/dashboard')).toBe(false);
    expect(isPublicAccessPath('/accept-invitations')).toBe(false);
  });
});

describe('SessionExpiryHandler', () => {
  it('does not redirect while a public access route is being activated', () => {
    const auth = {
      invalidate: vi.fn(),
    };
    const router = {
      url: '/',
      currentNavigation: vi.fn().mockReturnValue({
        extractedUrl: {},
        finalUrl: {},
      }),
      serializeUrl: vi.fn().mockReturnValue('/reset-password/token'),
      navigateByUrl: vi.fn(),
    };
    TestBed.configureTestingModule({
      providers: [
        SessionExpiryHandler,
        { provide: AuthSessionService, useValue: auth },
        { provide: Router, useValue: router },
      ],
    });

    TestBed.inject(SessionExpiryHandler).handle();

    expect(auth.invalidate).toHaveBeenCalledOnce();
    expect(router.navigateByUrl).not.toHaveBeenCalled();
  });
});
