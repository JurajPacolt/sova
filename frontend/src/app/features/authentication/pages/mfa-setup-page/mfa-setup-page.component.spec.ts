import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { of } from 'rxjs';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { AuthSessionService } from '../../../../core/auth/auth-session.service';
import { AuthSessionStore } from '../../../../core/auth/auth-session.store';
import { MfaSetupPageComponent } from './mfa-setup-page.component';

const MFA_REQUIRED = {
  enabled: false,
  verified: false,
  enrollment_required: true,
  recovery_codes_remaining: 0,
} as const;

describe('MfaSetupPageComponent', () => {
  const api = {
    getMfaStatus: vi.fn(),
    beginMfaEnrollment: vi.fn(),
    confirmMfaEnrollment: vi.fn(),
    regenerateMfaRecoveryCodes: vi.fn(),
  };
  const auth = {
    refreshCurrentSession: vi.fn(),
    logout: vi.fn(),
  };
  const router = {
    navigateByUrl: vi.fn().mockResolvedValue(true),
  };

  beforeEach(async () => {
    vi.clearAllMocks();
    api.getMfaStatus.mockReturnValue(of({ mfa: MFA_REQUIRED }));
    api.beginMfaEnrollment.mockReturnValue(
      of({
        secret: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
        otpauth_uri: 'otpauth://totp/SOVA%3Aadmin%40example.test?secret=ABC',
      }),
    );
    api.confirmMfaEnrollment.mockReturnValue(
      of({
        mfa: {
          enabled: true,
          verified: true,
          enrollment_required: false,
          recovery_codes_remaining: 2,
        },
        recovery_codes: ['ABCD-EFGH-JKLM-NPQR', 'STUV-WXYZ-2345-6789'],
      }),
    );
    auth.refreshCurrentSession.mockReturnValue(
      of({
        user: {
          id: '019f9f00-0000-7000-8000-000000000003',
          email: 'admin@example.test',
          display_name: 'Administrator',
          preferred_locale: 'sk',
          is_superadmin: true,
        },
        impersonation: null,
        mfa: {
          enabled: true,
          verified: true,
          enrollment_required: false,
          recovery_codes_remaining: 2,
        },
      }),
    );

    await TestBed.configureTestingModule({
      imports: [MfaSetupPageComponent],
      providers: [
        AuthSessionStore,
        { provide: SovaApiClient, useValue: api },
        { provide: AuthSessionService, useValue: auth },
        { provide: Router, useValue: router },
      ],
    }).compileComponents();

    TestBed.inject(AuthSessionStore).setAuthenticated(
      {
        id: '019f9f00-0000-7000-8000-000000000003',
        email: 'admin@example.test',
        display_name: 'Administrator',
        preferred_locale: 'sk',
        is_superadmin: false,
      },
      null,
      MFA_REQUIRED,
    );
  });

  it('enrolls an authenticator and shows recovery codes only after confirmation', () => {
    const fixture = TestBed.createComponent(MfaSetupPageComponent);
    fixture.detectChanges();
    const root = fixture.nativeElement as HTMLElement;
    const password = root.querySelector<HTMLInputElement>('#enrollment-password');

    expect(password).not.toBeNull();
    password!.value = 'correct horse battery staple';
    password!.dispatchEvent(new Event('input'));
    password!.form!.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    expect(api.beginMfaEnrollment).toHaveBeenCalledWith({
      current_password: 'correct horse battery staple',
    });
    expect(root.textContent).toContain('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567');

    const code = root.querySelector<HTMLInputElement>('#confirmation-code');
    expect(code).not.toBeNull();
    code!.value = '123456';
    code!.dispatchEvent(new Event('input'));
    code!.form!.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    expect(api.confirmMfaEnrollment).toHaveBeenCalledWith({ code: '123456' });
    expect(auth.refreshCurrentSession).toHaveBeenCalledOnce();
    expect(root.textContent).toContain('ABCD-EFGH-JKLM-NPQR');
    expect(root.textContent).toContain('STUV-WXYZ-2345-6789');
  });
});
